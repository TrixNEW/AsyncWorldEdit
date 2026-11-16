<?php

declare(strict_types=1);

namespace Trix\WorldEdit\engine;

use Trix\WorldEdit\Settings;
use Trix\WorldEdit\geometry\Region;
use Trix\WorldEdit\pattern\Mask;

/**
 * Runs queued jobs on the main thread within a per-tick time budget.
 *
 * @phpstan-import-type Steps from Job
 */
final class Editor{
	/** Bytes kept free for each tick's history so a full limit fails jobs instead of overshooting it. */
	private const TICK_HISTORY_RESERVE = 262144;

	public readonly History $history;
	/** @var array<int, Job> */
	private array $jobs = [];
	/** @var array<string, Job> */
	private array $owners = [];
	/** @var array<string, true> owners whose history is discarded when their running job settles */
	private array $forgotten = [];
	private int $nextId = 1;
	private int $rotation = 0;
	private bool $ticking = false;
	private bool $closed = false;
	public int $lastTickNanos = 0;

	/** @param (\Closure(bool) : void)|null $activity told when the first job arrives and the last one leaves */
	public function __construct(public readonly Settings $settings, private ?\Closure $activity = null){
		$this->history = new History($settings->historyBytes, $settings->historyEntries);
	}

	/**
	 * Jobs that write must reserve the box they touch; overlapping reservations in a world are refused.
	 * Jobs without bounds can only yield read-only steps.
	 *
	 * @param Steps $steps
	 * @param (\Closure(Job) : void)|null $onDone
	 */
	public function submit(string $owner, WorldAccess $world, \Generator $steps, int $limit, ?Region $bounds = null, ?Mask $mask = null, ?\Closure $onDone = null, bool $record = true) : Job{
		if($owner === '' || strlen($owner) > 128 || str_contains($owner, "\0")){
			throw new \InvalidArgumentException('Invalid operation owner');
		}
		if($this->closed || isset($this->owners[$owner]) || isset($this->forgotten[$owner])){
			throw new \RuntimeException('You already have an active operation');
		}
		if(count($this->jobs) >= $this->settings->maxJobs){
			throw new \RuntimeException('The edit queue is full; retry shortly');
		}
		if($limit < 1 || $limit > $this->settings->maxBlocks){
			throw new \InvalidArgumentException('Operation exceeds the block limit');
		}
		if(!$world->isValid()){
			throw new \RuntimeException('World is no longer loaded');
		}
		if($bounds !== null && ($bounds->minY < $world->minY() || $bounds->maxY >= $world->maxY())){
			throw new \OutOfBoundsException('Selection exceeds world height');
		}
		if($bounds !== null){
			foreach($this->jobs as $other){
				if($other->bounds !== null && $other->world->id() === $world->id() && $bounds->intersects($other->bounds)){
					throw new \RuntimeException('Another operation owns this region; retry after it finishes');
				}
			}
		}
		$job = new Job($this->nextId++, $owner, $world, $steps, $limit, $bounds, $mask, $record, $onDone);
		$this->jobs[$job->id] = $job;
		$this->owners[$owner] = $job;
		if(count($this->jobs) === 1){
			($this->activity)?->__invoke(true);
		}
		return $job;
	}

	/** @param (\Closure(Job) : void)|null $onDone */
	public function restore(string $owner, WorldAccess $world, bool $redo = false, ?\Closure $onDone = null) : Job{
		if(isset($this->owners[$owner])){
			throw new \RuntimeException('Cancel or finish your current operation before using history');
		}
		$key = History::key($owner, $world->id());
		$entry = $this->history->top($key, $redo) ?? throw new \RuntimeException($redo ? 'Nothing to redo in this world' : 'Nothing to undo in this world');
		// Redo entries are the changes recorded while undoing, so both directions replay an entry backwards.
		$steps = (static function() use ($entry) : \Generator{
			foreach($entry->changes(true) as [$x, $y, $z, $before, $after]){
				yield [$x, $y, $z, $before, $after];
			}
		})();
		$job = $this->submit($owner, $world, $steps, max(1, $entry->count()), $entry->bounds(), onDone: $onDone, record: false);
		$job->restoring = [$entry, $redo];
		$this->history->pin($entry);
		return $job;
	}

	public function active(string $owner) : ?Job{
		return $this->owners[$owner] ?? null;
	}

	public function hasJobs() : bool{
		return $this->jobs !== [];
	}

	/** Bytes held by retained history and by the changes of running jobs. */
	public function historyBytes() : int{
		$bytes = $this->history->bytes();
		foreach($this->jobs as $job){
			$bytes += $job->history->bytes();
		}
		return $bytes;
	}

	/** Runs jobs for up to the configured time budget. $maxSteps additionally caps the work units, for tests. */
	public function tick(int $maxSteps = PHP_INT_MAX) : void{
		if($this->ticking || $this->jobs === []){
			return;
		}
		$this->ticking = true;
		$start = hrtime(true);
		$deadline = $start + $this->settings->budgetMicros * 1000;
		try{
			$available = $this->settings->historyBytes - $this->historyBytes();
			$ids = array_keys($this->jobs);
			$count = count($ids);
			// Each job gets an equal share of what is left; time and steps a job does not use flow to the next one.
			for($i = 0; $i < $count && $maxSteps > 0; ++$i){
				$job = $this->jobs[$ids[($i + $this->rotation) % $count]] ?? null;
				$now = hrtime(true);
				if($now >= $deadline){
					break;
				}
				if($job !== null){
					$maxSteps -= $this->run($job, $now + intdiv($deadline - $now, $count - $i), intdiv($maxSteps - 1, $count - $i) + 1, $available);
				}
			}
			++$this->rotation;
		}finally{
			foreach($this->jobs as $job){
				if($job->cancelled || !$job->world->isValid()){
					$this->finish($job, JobStatus::Cancelled, $job->world->isValid() ? null : 'World unloaded');
					continue;
				}
				try{
					$job->world->release();
				}catch(\Throwable $e){
					$this->finish($job, JobStatus::Failed, $e->getMessage());
				}
			}
			$this->lastTickNanos = hrtime(true) - $start;
			$this->ticking = false;
		}
	}

	/** Runs steps until $until or $quota steps; returns the steps run. */
	private function run(Job $job, int $until, int $quota, int &$available) : int{
		$job->status = JobStatus::Running;
		$world = $job->world;
		$minY = $world->minY();
		$maxY = $world->maxY();
		$bounds = $job->bounds;
		$mask = $job->mask;
		$visited = $job->visited;
		try{
			$steps = $job->steps ?? throw new \LogicException('Job has no steps');
			do{
				if(!$world->isValid()){
					break;
				}
				if(!$steps->valid()){
					$job->result = $steps->getReturn();
					$this->finish($job, $job->conflicts === 0 ? JobStatus::Completed : JobStatus::Conflicted);
					break;
				}
				// Starting the generator runs operation code, which can cancel the job.
				if($job->cancelled){
					break;
				}
				if(++$job->visited > $job->limit){
					throw new \RuntimeException('Operation exceeded its declared work limit');
				}
				$step = $steps->current();
				if($step !== null){
					[$x, $y, $z, $value, $expected] = $step;
					if($bounds === null || !$bounds->containsBox($x, $y, $z) || $y < $minY || $y >= $maxY){
						throw new \OutOfBoundsException('Edit exceeds its reserved region or the world height');
					}
					$world->acquire($x >> 4, $z >> 4);
					$expected ??= $world->read($x, $y, $z);
					if(($mask === null || $mask->matches($expected->state)) && !$expected->equals($value)){
						$cost = 20 + ($expected->nbt === null && $value->nbt === null ? 0 : strlen($expected->nbt ?? '') + strlen($value->nbt ?? '') + 256);
						if(($available -= $cost) < 0){
							$available = $this->reclaim($cost);
						}
						$this->apply($job, $x, $y, $z, $expected, $value);
					}
				}
				// A write can cancel the job through world callbacks; never resume it afterwards.
				if($job->cancelled){
					break;
				}
				$steps->next();
			}while($job->visited - $visited < $quota && hrtime(true) < $until);
		}catch(\Throwable $e){
			$this->finish($job, JobStatus::Failed, $e->getMessage());
		}
		return $job->visited - $visited;
	}

	/** Evicts old history to make room for running jobs, returning the bytes left after $cost. */
	private function reclaim(int $cost) : int{
		$live = $this->historyBytes() - $this->history->bytes();
		$this->history->fit($this->settings->historyBytes - $live - $cost - self::TICK_HISTORY_RESERVE);
		$available = $this->settings->historyBytes - $this->historyBytes() - $cost;
		if($available < 0){
			throw new \RuntimeException('History memory limit reached; applied changes remain undoable');
		}
		return $available;
	}

	private function apply(Job $job, int $x, int $y, int $z, BlockValue $expected, BlockValue $value) : void{
		// Record first, so a write that fails halfway still leaves the block undoable.
		$job->history->append($x, $y, $z, $expected, $value);
		try{
			$stored = $job->world->write($x, $y, $z, $expected, $value);
		}catch(\Throwable $e){
			try{
				$actual = $job->world->read($x, $y, $z);
			}catch(\Throwable){
				// Keep the record: undoing a block that never changed only reports a conflict.
				++$job->changed;
				throw $e;
			}
			if($actual->equals($expected)){
				$job->history->removeLast();
			}else{
				$job->history->updateLastAfter($actual);
				++$job->changed;
			}
			throw $e;
		}
		if($stored === null){
			$job->history->removeLast();
			++$job->conflicts;
			return;
		}
		if($stored !== $value && !$stored->equals($value)){
			$job->history->updateLastAfter($stored);
		}
		++$job->changed;
	}

	public function forget(string $owner) : void{
		$this->history->forgetOwner($owner);
		$job = $this->owners[$owner] ?? null;
		if($job === null){
			return;
		}
		$this->forgotten[$owner] = true;
		$job->cancel();
		if(!$this->ticking){
			$this->finish($job, JobStatus::Cancelled, 'Owner disconnected');
		}
	}

	public function cancelWorld(string $worldId) : void{
		foreach($this->jobs as $job){
			if($job->world->id() === $worldId){
				$job->cancel();
				if(!$this->ticking){
					$this->finish($job, JobStatus::Cancelled, 'World unloaded');
				}
			}
		}
	}

	public function forgetWorld(string $worldId) : void{
		$this->history->forgetWorld($worldId);
	}

	public function shutdown() : void{
		$this->closed = true;
		foreach($this->jobs as $job){
			$job->cancel();
			if(!$this->ticking){
				$this->finish($job, JobStatus::Cancelled, 'Plugin disabled; applied changes remain in the world');
			}
		}
		$this->history->clear();
		$activity = $this->activity;
		$this->activity = null;
		$activity?->__invoke(false);
	}

	private function finish(Job $job, JobStatus $status, ?string $error = null) : void{
		if(($this->jobs[$job->id] ?? null) !== $job){
			return;
		}
		unset($this->jobs[$job->id], $this->owners[$job->owner]);
		try{
			$job->world->release();
		}catch(\Throwable $e){
			$status = JobStatus::Failed;
			$error ??= $e->getMessage();
		}
		$forgotten = isset($this->forgotten[$job->owner]);
		$keep =!$this->closed && !$forgotten && $job->world->isValid();
		$key = History::key($job->owner, $job->world->id());
		if($job->restoring !== null){
			[$entry, $redo] = $job->restoring;
			$this->history->restored($key, $entry, $redo, $keep && ($status === JobStatus::Completed || $status === JobStatus::Conflicted), $keep ? $job->history : null);
		}elseif($keep && $job->record && $job->changed > 0){
			$this->history->record($key, $job->history);
		}
		try{
			$job->settle($status, $error);
		}catch(\Throwable $e){
			$job->error ??= 'Completion failed: ' . $e->getMessage();
		}
		// Only now, so completion callbacks cannot queue new work for a departed owner.
		unset($this->forgotten[$job->owner]);
		if($this->jobs === []){
			($this->activity)?->__invoke(false);
		}
	}
}
