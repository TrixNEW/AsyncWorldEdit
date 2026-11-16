<?php

declare(strict_types=1);

namespace Trix\WorldEdit\tests;

use Trix\WorldEdit\engine\BlockValue;
use Trix\WorldEdit\engine\WorldAccess;

final class MemoryWorld implements WorldAccess{
	/** @var array<string, BlockValue> */
	public array $blocks = [];
	public bool $valid = true;
	public bool $fail = false;
	public int $pins = 0;
	public bool $failRelease = false;
	public int $writes = 0;
	/** @var (\Closure() : void)|null */
	public ?\Closure $afterWrite = null;

	public function __construct(private string $name = 'test'){}

	public function id() : string{ return $this->name; }

	public function isValid() : bool{ return $this->valid; }

	public function minY() : int{ return -64; }

	public function maxY() : int{ return 320; }

	/** Requires reads and writes to target the acquired chunk, like QuarkWorld. */
	public bool $strict = false;
	/** @var array{int, int}|null */
	private ?array $acquired = null;

	public function acquire(int $chunkX, int $chunkZ) : void{
		$this->pins = 1;
		$this->acquired = [$chunkX, $chunkZ];
	}

	public function release() : void{
		$this->pins = 0;
		$this->acquired = null;
		if($this->failRelease){
			throw new \RuntimeException('Injected release failure');
		}
	}

	private function checkPinned(int $x, int $z) : void{
		if($this->strict && $this->acquired !== [$x >> 4, $z >> 4]){
			throw new \LogicException("Block $x,$z accessed without its chunk acquired");
		}
	}

	public function stateAt(int $x, int $y, int $z) : int{
		return $this->read($x, $y, $z)->state;
	}

	public function read(int $x, int $y, int $z) : BlockValue{
		$this->checkPinned($x, $z);
		return $this->blocks["$x:$y:$z"] ?? BlockValue::of(0);
	}

	public function write(int $x, int $y, int $z, BlockValue $expected, BlockValue $value) : ?BlockValue{
		$this->checkPinned($x, $z);
		if($this->fail){
			throw new \RuntimeException('Injected write failure');
		}
		if(!$this->read($x, $y, $z)->equals($expected)){
			return null;
		}
		$this->set($x, $y, $z, $value);
		++$this->writes;
		($this->afterWrite)?->__invoke();
		return $value;
	}

	/** Changes a block outside any edit. */
	public function set(int $x, int $y, int $z, BlockValue $value) : void{
		$this->blocks["$x:$y:$z"] = $value;
	}
}
