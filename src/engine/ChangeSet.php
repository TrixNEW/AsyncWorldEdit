<?php

declare(strict_types=1);

namespace asyncworldedit\engine;

final class ChangeSet{
	private string $records = '';
	/** @var list<string> */
	private array $sealed = [];
	private int $recordCount = 0;
	private int $sealedBytes = 0;
	/** @var array<int, array{?string, ?string}> */
	private array $tiles = [];
	private int $tileBytes = 0;

	public function __construct(public readonly string $worldId){}

	public function append(int $x, int $y, int $z, BlockValue $before, BlockValue $after) : void{
		$index = $this->count();
		if(strlen($this->records) >= 81920){
			$compressed = gzdeflate($this->records, 1);
			if($compressed === false){ throw new \RuntimeException('History compression failed'); }
			$this->sealed[] = $compressed;
			$this->sealedBytes += strlen($compressed);
			$this->records = '';
		}
		$this->records .= pack('V5', $x & 0xffffffff, $y & 0xffffffff, $z & 0xffffffff, $before->state, $after->state);
		++$this->recordCount;
		if($before->nbt !== null || $after->nbt !== null){
			$this->tiles[$index] = [$before->nbt, $after->nbt];
			$this->tileBytes += strlen($before->nbt ?? '') + strlen($after->nbt ?? '') + 256;
		}
	}

	public function count() : int{
		return $this->recordCount;
	}

	public function bytes() : int{
		return $this->sealedBytes + strlen($this->records) + $this->tileBytes;
	}

	public function updateLastAfter(BlockValue $value) : void{
		$offset = strlen($this->records) - 4;
		if($offset < 0){ throw new \LogicException('History is empty'); }
		$packed = pack('V', $value->state);
		for($i = 0; $i < 4; ++$i){ $this->records[$offset + $i] = $packed[$i]; }
		$index = $this->recordCount - 1;
		$old = $this->tiles[$index] ?? [null, null];
		if(isset($this->tiles[$index])){ $this->tileBytes -= strlen($old[0] ?? '') + strlen($old[1] ?? '') + 256; }
		if($old[0] !== null || $value->nbt !== null){
			$this->tiles[$index] = [$old[0], $value->nbt];
			$this->tileBytes += strlen($old[0] ?? '') + strlen($value->nbt ?? '') + 256;
		}else{ unset($this->tiles[$index]); }
	}

	public function removeLast() : void{
		if($this->records === ''){ throw new \LogicException('History is empty'); }
		$index = --$this->recordCount;
		if(isset($this->tiles[$index])){
			[$before,$after] = $this->tiles[$index];
			$this->tileBytes -= strlen($before ?? '') + strlen($after ?? '') + 256;
			unset($this->tiles[$index]);
		}
		$this->records = substr($this->records, 0, -20);
	}

	/** @return \Generator<int, array{int, int, int, BlockValue, BlockValue}> */
	public function changes(bool $reverse = false) : \Generator{
		$chunks = count($this->sealed) + 1;
		for($part = 0; $part < $chunks; ++$part){
			$chunk = $reverse ? $chunks - $part - 1 : $part;
			$data = $chunk === $chunks - 1 ? $this->records : gzinflate($this->sealed[$chunk], 81920);
			if($data === false){ throw new \RuntimeException('Corrupted history'); }
			$count = intdiv(strlen($data), 20);
			for($step = 0; $step < $count; ++$step){
				$i = $reverse ? $count - $step - 1 : $step;
				$values = unpack('V5', $data, $i * 20);
				if($values === false){ throw new \RuntimeException('Corrupted history record'); }
				$v = array_values($values);
				$tiles = $this->tiles[$chunk * 4096 + $i] ?? [null, null];
				yield [self::signed($v[0]), self::signed($v[1]), self::signed($v[2]), new BlockValue($v[3], $tiles[0]), new BlockValue($v[4], $tiles[1])];
			}
		}
	}

	private static function signed(int $value) : int{
		return $value >= 0x80000000 ? $value - 0x100000000 : $value;
	}
}
