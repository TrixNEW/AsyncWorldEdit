<?php

declare(strict_types=1);

namespace Trix\WorldEdit\engine;

interface WorldAccess{
	public function id() : string;
	public function isValid() : bool;
	public function minY() : int;
	public function maxY() : int;
	public function acquire(int $chunkX, int $chunkZ) : void;
	public function release() : void;
	public function read(int $x, int $y, int $z) : BlockValue;
	public function write(int $x, int $y, int $z, BlockValue $value) : void;
}
