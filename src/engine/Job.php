<?php

declare(strict_types=1);

namespace asyncworldedit\engine;

use asyncworldedit\geometry\Region;

final class Job{
	public string $status = 'queued';
	public int $visited = 0;
	public int $changed = 0;
	public int $conflicts = 0;
	public ?string $error = null;
	public ?string $notificationError = null;
	public bool $cancelled = false;
	public readonly int $submitted;
	public ?int $finished = null;
	public readonly ChangeSet $history;
	/** @var \Closure(self):void|null */
	private ?\Closure $completion;

	/**
	 * @param \Generator<int, array{int,int,int,BlockValue,?BlockValue}|null, void, void> $steps
	 * @param \Closure(self):void|null $completion
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $owner,
		public readonly WorldAccess $world,
		public ?\Generator $steps,
		public readonly int $limit,
		?\Closure $completion = null,
		public readonly ?Region $bounds = null
	){
		$this->submitted = hrtime(true);
		$this->history = new ChangeSet($world->id());
		$this->completion = $completion;
	}

	public function cancel() : void{
		$this->cancelled = true;
	}

	public function canContinue() : bool{
		return !$this->isFinished() && !$this->cancelled && $this->world->isValid();
	}

	public function isFinished() : bool{
		return $this->finished !== null;
	}

	public function complete(string $status, ?string $error = null) : void{
		$this->status = $status;
		$this->error = $error;
		$this->finished = hrtime(true);
		$this->steps = null;
		$callback = $this->completion;
		$this->completion = null;
		try{ $callback?->__invoke($this); }
		catch(\Throwable $e){ $this->notificationError = $e->getMessage(); }
	}
}
