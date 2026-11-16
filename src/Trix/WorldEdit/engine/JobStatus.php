<?php

declare(strict_types=1);

namespace Trix\WorldEdit\engine;

enum JobStatus : string{
	case Queued = 'queued';
	case Running = 'running';
	case Completed = 'completed';
	case Conflicted = 'conflicted';
	case Cancelled = 'cancelled';
	case Failed = 'failed';

	public function isFinal() : bool{
		return $this !== self::Queued && $this !== self::Running;
	}
}
