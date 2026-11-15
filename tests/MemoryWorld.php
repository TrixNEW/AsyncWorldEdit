<?php

declare(strict_types=1);

namespace asyncworldedit\tests;

use asyncworldedit\engine\BlockValue;
use asyncworldedit\engine\WorldAccess;

final class MemoryWorld implements WorldAccess{
	/** @var array<string, BlockValue> */
	public array $blocks = [];
	public bool $valid = true;
	public bool $fail = false;
	public int $pins = 0;
	public bool $failRelease = false;
	/** @var \Closure():void|null */
	public ?\Closure $afterWrite = null;
	public function __construct(private string $name = 'test'){}
	public function id() : string{ return $this->name; }
	public function isValid() : bool{ return $this->valid; }
	public function minY() : int{ return -64; }
	public function maxY() : int{ return 320; }
	public function acquire(int $chunkX, int $chunkZ) : void{ $this->pins = 1; }
	public function release() : void{
		$this->pins = 0;
		if($this->failRelease){ throw new \RuntimeException('Injected release failure'); }
	}
	public function read(int $x, int $y, int $z) : BlockValue{ return $this->blocks["$x:$y:$z"] ?? new BlockValue(0); }
	public function write(int $x, int $y, int $z, BlockValue $value) : void{
		if($this->fail){ throw new \RuntimeException('Injected write failure'); }
		$this->blocks["$x:$y:$z"] = $value;
		($this->afterWrite)?->__invoke();
	}
}
