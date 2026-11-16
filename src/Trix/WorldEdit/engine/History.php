<?php

declare(strict_types=1);

namespace Trix\WorldEdit\engine;

final class History{
	/** @var array<string, list<ChangeSet>> */
	private array $undo = [];
	/** @var array<string, list<ChangeSet>> */
	private array $redo = [];
	/** @var array<int, true> entries being undone or redone, by object ID */
	private array $pinned = [];
	private int $bytes = 0;

	public function __construct(private readonly int $limit, private readonly int $entries){}

	public static function key(string $owner, string $worldId) : string{
		return $owner . "\0" . $worldId;
	}

	public function bytes() : int{
		return $this->bytes;
	}

	public function record(string $key, ChangeSet $changes) : void{
		$this->drop($this->redo, $key);
		$this->push($this->undo, $key, $changes);
		$this->fit($this->limit);
	}

	public function top(string $key, bool $redo) : ?ChangeSet{
		$stack = ($redo ? $this->redo : $this->undo)[$key] ?? [];
		return $stack === [] ? null : $stack[count($stack) - 1];
	}

	public function pin(ChangeSet $entry) : void{
		$this->pinned[spl_object_id($entry)] = true;
	}

	/**
	 * Settles an undo or redo of $entry. A consumed entry leaves its stack; the inverse changes join the other one.
	 * Partially restored entries stay so the rest can be retried.
	 */
	public function restored(string $key, ChangeSet $entry, bool $redo, bool $consumed, ?ChangeSet $inverse) : void{
		unset($this->pinned[spl_object_id($entry)]);
		if($consumed){
			if($redo){
				$this->remove($this->redo, $key, $entry);
			}else{
				$this->remove($this->undo, $key, $entry);
			}
		}
		if($inverse !== null && $inverse->count() > 0){
			if($redo){
				$this->push($this->undo, $key, $inverse);
			}else{
				$this->push($this->redo, $key, $inverse);
			}
		}
		$this->fit($this->limit);
	}

	/** Evicts the oldest entries of the largest histories until at most $limit bytes remain. */
	public function fit(int $limit) : void{
		while($this->bytes > $limit){
			$largest = null;
			$largestBytes = 0;
			foreach(array_keys($this->undo + $this->redo) as $key){
				$bytes = 0;
				foreach([...($this->undo[$key] ?? []), ...($this->redo[$key] ?? [])] as $entry){
					if(!isset($this->pinned[spl_object_id($entry)])){
						$bytes += $entry->bytes();
					}
				}
				if($bytes > $largestBytes){
					$largest = $key;
					$largestBytes = $bytes;
				}
			}
			if($largest === null || !($this->evictOldest($this->undo, $largest) || $this->evictOldest($this->redo, $largest))){
				return;
			}
		}
	}

	public function forgetOwner(string $owner) : void{
		foreach(array_keys($this->undo + $this->redo) as $key){
			if(str_starts_with($key, $owner . "\0")){
				$this->drop($this->undo, $key);
				$this->drop($this->redo, $key);
			}
		}
	}

	public function forgetWorld(string $worldId) : void{
		foreach(array_keys($this->undo + $this->redo) as $key){
			if(str_ends_with($key, "\0" . $worldId)){
				$this->drop($this->undo, $key);
				$this->drop($this->redo, $key);
			}
		}
	}

	public function clear() : void{
		$this->undo = $this->redo = $this->pinned = [];
		$this->bytes = 0;
	}

	/** @param array<string, list<ChangeSet>> $stacks */
	private function push(array &$stacks, string $key, ChangeSet $entry) : void{
		$stacks[$key][] = $entry;
		$this->bytes += $entry->bytes();
		while(count($stacks[$key]) > $this->entries && $this->evictOldest($stacks, $key)){
			continue;
		}
	}

	/** @param array<string, list<ChangeSet>> $stacks */
	private function evictOldest(array &$stacks, string $key) : bool{
		$oldest = $stacks[$key][0] ?? null;
		if($oldest === null || isset($this->pinned[spl_object_id($oldest)])){
			return false;
		}
		$this->remove($stacks, $key, $oldest);
		return true;
	}

	/** @param array<string, list<ChangeSet>> $stacks */
	private function remove(array &$stacks, string $key, ChangeSet $entry) : void{
		$index = array_search($entry, $stacks[$key] ?? [], true);
		if($index === false){
			return;
		}
		$this->bytes -= $entry->bytes();
		array_splice($stacks[$key], $index, 1);
		if($stacks[$key] === []){
			unset($stacks[$key]);
		}
	}

	/** @param array<string, list<ChangeSet>> $stacks */
	private function drop(array &$stacks, string $key) : void{
		foreach($stacks[$key] ?? [] as $entry){
			$this->bytes -= $entry->bytes();
		}
		unset($stacks[$key]);
	}
}
