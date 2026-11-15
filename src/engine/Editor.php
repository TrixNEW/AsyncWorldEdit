<?php

declare(strict_types=1);

namespace asyncworldedit\engine;

use asyncworldedit\Settings;
use asyncworldedit\geometry\Region;

final class Editor{
	/** @var array<int, Job> */
	private array $jobs = [];
	/** @var array<string, int> */
	private array $owners = [];
	/** @var array<string, list<ChangeSet>> */
	private array $undo = [];
	/** @var array<string, list<ChangeSet>> */
	private array $redo = [];
	private int $nextId = 1;
	private int $cursor = 0;
	private bool $closed = false;
	private int $retainedBytes = 0;
	private bool $ticking = false;
	/** @var array<string,true> */
	private array $forgotten = [];
	/** @var array<string,ChangeSet> */
	private array $restoring = [];
	public int $lastTickNanos = 0;

	/** @param \Closure(bool):void|null $activity */
	public function __construct(public readonly Settings $settings, private ?\Closure $activity = null){}

	/**
	 * Each yield is one bounded unit of work. null advances a read-only operation.
	 * @param \Generator<int, array{int,int,int,BlockValue,?BlockValue}|null, void, void> $steps
	 * @param \Closure(Job):void|null $completion
	 */
	public function submit(string $owner, WorldAccess $world, \Generator $steps, int $limit, ?\Closure $completion = null, bool $record = true, ?Region $bounds = null) : Job{
		if($owner === '' || strlen($owner) > 128 || str_contains($owner, "\0")){
			throw new \InvalidArgumentException('Invalid operation owner');
		}
		if($this->closed || count($this->jobs) >= $this->settings->maxJobs || isset($this->owners[$owner]) || isset($this->forgotten[$owner])){
			throw new \RuntimeException('Edit queue is full or you already have an active operation');
		}
		if($limit < 1 || $limit > $this->settings->maxBlocks || !$world->isValid()){
			throw new \InvalidArgumentException('Operation exceeds limits or its world is unavailable');
		}
		if($bounds !== null){
			if($bounds->minY < $world->minY() || $bounds->maxY >= $world->maxY()){
				throw new \OutOfBoundsException('Selection exceeds world height');
			}
			foreach($this->jobs as $other){
				$b = $other->bounds;
				if($other->world->id() === $world->id() && ($b === null || (
					$bounds->minX <= $b->maxX && $bounds->maxX >= $b->minX &&
					$bounds->minY <= $b->maxY && $bounds->maxY >= $b->minY &&
					$bounds->minZ <= $b->maxZ && $bounds->maxZ >= $b->minZ))){
					throw new \RuntimeException('Another operation owns this region; retry after it finishes');
				}
			}
		}else{
			foreach($this->jobs as $other){
				if($other->world->id() === $world->id()){
					throw new \RuntimeException('History or an unbounded API operation requires exclusive access to this world');
				}
			}
		}
		$job = new Job($this->nextId++, $owner, $world, $steps, $limit, function(Job $job) use ($completion, $record) : void{
			if(!$this->closed && $record && $job->changed > 0 && $job->world->isValid()){
				$key = $job->owner . "\0" . $job->world->id();
				foreach($this->redo[$key] ?? [] as $entry){ $this->retainedBytes -= $entry->bytes(); }
				unset($this->redo[$key]);
				$this->undo[$key][] = $job->history;
				$this->retainedBytes += $job->history->bytes();
				$this->trimHistory();
			}
			$completion?->__invoke($job);
		}, $bounds);
		$this->jobs[$job->id] = $job;
		$this->owners[$owner] = $job->id;
		if(count($this->jobs) === 1){ ($this->activity)?->__invoke(true); }
		return $job;
	}

	public function hasJobs() : bool{
		return $this->jobs !== [];
	}

	public function active(string $owner) : ?Job{
		return isset($this->owners[$owner]) ? $this->jobs[$this->owners[$owner]] : null;
	}

	public function tick() : void{
		if($this->ticking || $this->jobs === []){ return; }
		$this->ticking = true;
		$start = hrtime(true);
		$deadline = $start + $this->settings->budgetMicros * 1000;
		$remaining = $this->settings->blocksPerTick;
		$ids = array_keys($this->jobs);
		try{
			while($this->jobs !== [] && $remaining > 0 && hrtime(true) < $deadline){
				$this->cursor %= count($ids);
				$id = $ids[$this->cursor++];
				if(!isset($this->jobs[$id])){
					$ids = array_keys($this->jobs);
					$this->cursor = 0;
					continue;
				}
				$job = $this->jobs[$id];
				$job->status = 'running';
				try{
					if(!$job->canContinue()){
						$this->finish($job, 'cancelled', $job->world->isValid() ? null : 'World unloaded');
						continue;
					}
					// Resuming a generator can run arbitrary operation code; every operation yields per cell.
					$steps = $job->steps;
					if($steps === null || !$steps->valid()){
						$this->finish($job, $job->conflicts === 0 ? 'completed' : 'conflicted');
						continue;
					}
					if(!$job->canContinue()){
						$this->finish($job, 'cancelled');
						continue;
					}
					if(++$job->visited > $job->limit){
						throw new \RuntimeException('Operation exceeded its declared work limit');
					}
					--$remaining;
					$step = $steps->current();
					if($step !== null){
						[$x, $y, $z, $value, $expected] = $step;
						if(abs($x) > 30000000 || abs($z) > 30000000 || $y < $job->world->minY() || $y >= $job->world->maxY()){
							throw new \OutOfBoundsException('Edit exceeds supported world bounds');
						}
						$bounds = $job->bounds;
						if($bounds !== null && ($x < $bounds->minX || $x > $bounds->maxX || $y < $bounds->minY || $y > $bounds->maxY || $z < $bounds->minZ || $z > $bounds->maxZ)){
							throw new \OutOfBoundsException('Edit exceeds its reserved region');
						}
						$job->world->acquire($x >> 4, $z >> 4);
						$current = $job->world->read($x, $y, $z);
						if($expected !== null && !$current->equals($expected)){
							++$job->conflicts;
						}elseif(!$current->equals($value)){
							if($this->historyBytes() + 20 + strlen($current->nbt ?? '') + strlen($value->nbt ?? '') + 256 > $this->settings->historyBytes){
								throw new \RuntimeException('History memory limit reached; applied changes remain undoable');
							}
							// Record before mutation so an exception after a partial world write remains recoverable.
							$job->history->append($x, $y, $z, $current, $value);
							++$job->changed;
							try{
								$job->world->write($x, $y, $z, $value);
								$actual = $job->world->read($x, $y, $z);
								if(!$actual->equals($value)){ $job->history->updateLastAfter($actual); }
							}catch(\Throwable $e){
								$actual = $job->world->read($x, $y, $z);
								if($actual->equals($current)){ $job->history->removeLast(); --$job->changed; }
								else{ $job->history->updateLastAfter($actual); }
								throw $e;
							}
						}
					}
					if($job->canContinue()){ $steps->next(); }
				}catch(\Throwable $e){
					$this->finish($job, 'failed', $e->getMessage());
				}
			}
		}finally{
			foreach($this->jobs as $job){
				if($job->cancelled){
					$this->finish($job, 'cancelled');
					continue;
				}
				try{ $job->world->release(); }
				catch(\Throwable $error){ $this->finish($job, 'failed', $error->getMessage()); }
			}
			$this->lastTickNanos = hrtime(true) - $start;
			$this->ticking = false;
		}
	}

	/** @param \Closure(Job):void|null $completion */
	public function restore(string $owner, WorldAccess $world, bool $redo = false, ?\Closure $completion = null) : Job{
		if($this->active($owner) !== null){
			throw new \RuntimeException('Cancel or finish your current operation before using history');
		}
		$key = $owner . "\0" . $world->id();
		$stack = $redo ? ($this->redo[$key] ?? []) : ($this->undo[$key] ?? []);
		$entry = end($stack);
		if($entry === false || $entry->worldId !== $world->id()){
			throw new \RuntimeException('No history for this world');
		}
		$steps = (static function() use ($entry) : \Generator{
			foreach($entry->changes(true) as [$x, $y, $z, $before, $after]){
				yield [$x, $y, $z, $before, $after];
			}
		})();
		$job = $this->submit($owner, $world, $steps, max(1, $entry->count()), function(Job $job) use ($key, $entry, $redo, $completion) : void{
			if($this->closed){ $completion?->__invoke($job); return; }
			if(in_array($job->status, ['completed', 'conflicted'], true)){
				if($redo){ array_pop($this->redo[$key]); }
				else{ array_pop($this->undo[$key]); }
				$this->retainedBytes -= $entry->bytes();
			}
			if($job->changed > 0 && $job->world->isValid()){
				if($redo){ $this->undo[$key][] = $job->history; }
				else{ $this->redo[$key][] = $job->history; }
				$this->retainedBytes += $job->history->bytes();
			}
			unset($this->restoring[$key]);
			$this->trimHistory();
			$completion?->__invoke($job);
		}, false);
		$this->restoring[$key] = $entry;
		return $job;
	}

	public function forget(string $owner) : void{
		$this->forgotten[$owner] = true;
		$job = $this->active($owner);
		if($job !== null && $this->ticking){
			$job->cancel();
			return;
		}
		if($job !== null){ $this->finish($job, 'cancelled', 'Owner disconnected'); }
		foreach(array_keys($this->undo + $this->redo) as $key){
			if(str_starts_with($key, $owner . "\0")){
				foreach([...($this->undo[$key] ?? []), ...($this->redo[$key] ?? [])] as $entry){ $this->retainedBytes -= $entry->bytes(); }
				unset($this->undo[$key], $this->redo[$key]);
			}
		}
		unset($this->forgotten[$owner]);
	}

	public function forgetWorld(string $worldId) : void{
		foreach(array_keys($this->undo + $this->redo) as $key){
			if(str_ends_with($key, "\0" . $worldId)){
				foreach([...($this->undo[$key] ?? []), ...($this->redo[$key] ?? [])] as $entry){ $this->retainedBytes -= $entry->bytes(); }
				unset($this->undo[$key], $this->redo[$key]);
			}
		}
	}

	public function cancelWorld(string $worldId) : void{
		foreach($this->jobs as $job){
			if($job->world->id() === $worldId){
				$job->cancel();
				if(!$this->ticking){ $this->finish($job, 'cancelled', 'World unloaded'); }
			}
		}
	}

	public function shutdown() : void{
		$this->closed = true;
		$activity = $this->activity;
		$this->activity = null;
		foreach($this->jobs as $job){
			$job->cancel();
			if(!$this->ticking){ $this->finish($job, 'cancelled', 'Plugin disabled; committed changes retained'); }
		}
		$this->undo = $this->redo = $this->restoring = [];
		$this->retainedBytes = 0;
		$activity?->__invoke(false);
	}

	public function historyBytes() : int{
		$bytes = $this->retainedBytes;
		foreach($this->jobs as $job){ $bytes += $job->history->bytes(); }
		return $bytes;
	}

	private function finish(Job $job, string $status, ?string $error = null) : void{
		if(($this->jobs[$job->id] ?? null) !== $job){ return; }
		unset($this->jobs[$job->id], $this->owners[$job->owner]);
		try{ $job->world->release(); }
		catch(\Throwable $failure){ $status = 'failed'; $error = $failure->getMessage(); }
		if(!$job->isFinished()){ $job->complete($status, $error); }
		if(isset($this->forgotten[$job->owner])){
			$this->forget($job->owner);
		}
		if($this->jobs === []){ ($this->activity)?->__invoke(false); }
	}

	private function trimHistory() : void{
		foreach($this->redo as &$entries){
			while(count($entries) > $this->settings->historyEntries){
				$removed = array_shift($entries);
				if($removed !== null){ $this->retainedBytes -= $removed->bytes(); }
			}
		}
		unset($entries);
		foreach($this->undo as &$entries){
			while(count($entries) > $this->settings->historyEntries){
				$removed = array_shift($entries);
				if($removed !== null){ $this->retainedBytes -= $removed->bytes(); }
			}
		}
		unset($entries);
		foreach(array_keys($this->undo + $this->redo) as $key){
			if(($this->undo[$key] ?? []) === []){ unset($this->undo[$key]); }
			if(($this->redo[$key] ?? []) === []){ unset($this->redo[$key]); }
		}
		while($this->historyBytes() > $this->settings->historyBytes){
			foreach($this->undo as $owner => $entries){
				if($entries !== [] && $entries[0] !== ($this->restoring[$owner] ?? null)){
					$removed = array_shift($this->undo[$owner]);
					if($removed !== null){ $this->retainedBytes -= $removed->bytes(); }
					if($this->undo[$owner] === []){ unset($this->undo[$owner]); }
					continue 2;
				}
			}
			foreach($this->redo as $owner => $entries){
				if($entries !== [] && $entries[0] !== ($this->restoring[$owner] ?? null)){
					$removed = array_shift($this->redo[$owner]);
					if($removed !== null){ $this->retainedBytes -= $removed->bytes(); }
					if($this->redo[$owner] === []){ unset($this->redo[$owner]); }
					continue 2;
				}
			}
			break;
		}
	}
}
