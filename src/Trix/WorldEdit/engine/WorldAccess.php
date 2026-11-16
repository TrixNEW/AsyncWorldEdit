<?php

declare(strict_types=1);

namespace Trix\WorldEdit\engine;

interface WorldAccess{
	public function id() : string;

	public function isValid() : bool;

	public function minY() : int;

	public function maxY() : int;

	/** Pins one chunk for reads and writes until release() or another acquire(). */
	public function acquire(int $chunkX, int $chunkZ) : void;

	public function release() : void;

	public function read(int $x, int $y, int $z) : BlockValue;

	/**
	 * Replaces the block only while it still equals $expected.
	 * Returns the value actually stored, or null when the block changed since it was read.
	 */
	public function write(int $x, int $y, int $z, BlockValue $expected, BlockValue $value) : ?BlockValue;
}
