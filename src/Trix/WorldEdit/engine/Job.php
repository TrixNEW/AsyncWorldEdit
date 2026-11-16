<?php

declare(strict_types=1);

namespace Trix\WorldEdit\engine;

use Trix\WorldEdit\geometry\Region;
use Trix\WorldEdit\pattern\Mask;

/**
 * Each yielded step is one unit of work: a block to write, or null for a read-only unit.
 * A step without an expected value is checked against the block as it is when applied.
 *
 * @phpstan-type Step array{int, int, int, BlockValue, ?BlockValue}|null
 * @phpstan-type Steps \Generator<int, Step, mixed, mixed>
 */
final class Job{
	public JobStatus $status = JobStatus::Queued;
	public int $visited = 0;
	public int $changed = 0;
	public int $conflicts = 0;
	public ?string $error = null;
	/** The operation generator's return value once it completes. */
	public mixed $result = null;
	public bool $cancelled = false;
	public readonly ChangeSet $history;
	/** @var array{ChangeSet, bool}|null the history entry and direction while undoing or redoing */
	public ?array $restoring = null;

	/**
	 * @param Steps $steps
	 * @param (\Closure(self) : void)|null $onDone
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $owner,
		public readonly WorldAccess $world,
		public ?\Generator $steps,
		public readonly int $limit,
		public readonly ?Region $bounds = null,
		public readonly ?Mask $mask = null,
		public readonly bool $record = true,
		private ?\Closure $onDone = null
	){
		$this->history = new ChangeSet($world->id());
	}

	public function cancel() : void{
		$this->cancelled = true;
	}

	public function isFinished() : bool{
		return $this->status->isFinal();
	}

	/** Marks a finished job as failed, for follow-up work that runs in its completion callback. */
	public function fail(string $error) : void{
		$this->status = JobStatus::Failed;
		$this->error = $error;
	}

	/** @internal Settled exactly once by the editor. */
	public function settle(JobStatus $status, ?string $error) : void{
		$this->status = $status;
		$this->error = $error;
		$this->steps = null;
		$onDone = $this->onDone;
		$this->onDone = null;
		$onDone?->__invoke($this);
	}
}
