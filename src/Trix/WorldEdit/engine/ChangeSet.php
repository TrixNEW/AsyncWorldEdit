<?php

declare(strict_types=1);

namespace Trix\WorldEdit\engine;

use Trix\WorldEdit\geometry\Region;

/** Append-only block history: 20-byte records, sealed into deflated blocks of 4096. */
final class ChangeSet{
	private const RECORD = 20;
	private const BLOCK = 4096;
	private const TILE_OVERHEAD = 256;

	private string $records = '';
	/** @var list<string> */
	private array $sealed = [];
	private int $count = 0;
	private int $sealedBytes = 0;
	/** @var array<int, array{?string, ?string}> */
	private array $tiles = [];
	private int $tileBytes = 0;
	/** @var array{int, int, int, int, int, int} */
	private array $box = [PHP_INT_MAX, PHP_INT_MAX, PHP_INT_MAX, PHP_INT_MIN, PHP_INT_MIN, PHP_INT_MIN];

	public function __construct(public readonly string $worldId){}

	public function append(int $x, int $y, int $z, BlockValue $before, BlockValue $after) : void{
		if((($x << 32) >> 32) !== $x || (($y << 32) >> 32) !== $y || (($z << 32) >> 32) !== $z){
			throw new \OutOfBoundsException('History coordinates exceed signed 32-bit storage');
		}
		$box = &$this->box;
		if($x < $box[0]){ $box[0] = $x; }
		if($y < $box[1]){ $box[1] = $y; }
		if($z < $box[2]){ $box[2] = $z; }
		if($x > $box[3]){ $box[3] = $x; }
		if($y > $box[4]){ $box[4] = $y; }
		if($z > $box[5]){ $box[5] = $z; }
		if(strlen($this->records) === self::BLOCK * self::RECORD){
			$this->seal();
		}
		$this->records .= pack('V5', $x, $y, $z, $before->state, $after->state);
		if($before->nbt !== null || $after->nbt !== null){
			$this->setTiles($this->count, $before->nbt, $after->nbt);
		}
		++$this->count;
	}

	public function updateLastAfter(BlockValue $value) : void{
		if($this->records === ''){
			throw new \LogicException('History is empty');
		}
		$this->records = substr_replace($this->records, pack('V', $value->state), -4);
		$index = $this->count - 1;
		$this->setTiles($index, $this->tiles[$index][0] ?? null, $value->nbt);
	}

	public function removeLast() : void{
		if($this->records === ''){
			throw new \LogicException('History is empty');
		}
		$this->records = substr($this->records, 0, -self::RECORD);
		$this->setTiles(--$this->count, null, null);
	}

	public function count() : int{
		return $this->count;
	}

	/** Box around every change ever appended, or null when empty. */
	public function bounds() : ?Region{
		return $this->count === 0 ? null : new Region(...$this->box);
	}

	public function bytes() : int{
		return 256 + $this->sealedBytes + count($this->sealed) * 64 + strlen($this->records) + $this->tileBytes;
	}

	/** @return \Generator<int, array{int, int, int, BlockValue, BlockValue}, void, void> */
	public function changes(bool $reverse = false) : \Generator{
		$blocks = count($this->sealed) + 1;
		for($part = 0; $part < $blocks; ++$part){
			$block = $reverse ? $blocks - $part - 1 : $part;
			$data = $block === $blocks - 1 ? $this->records : gzinflate($this->sealed[$block], self::BLOCK * self::RECORD);
			if($data === false || strlen($data) % self::RECORD !== 0){
				throw new \RuntimeException('Corrupted history');
			}
			$records = intdiv(strlen($data), self::RECORD);
			for($step = 0; $step < $records; ++$step){
				$i = $reverse ? $records - $step - 1 : $step;
				$record = unpack('V5', $data, $i * self::RECORD);
				if($record === false){
					throw new \RuntimeException('Corrupted history record');
				}
				[1 => $x, 2 => $y, 3 => $z, 4 => $before, 5 => $after] = $record;
				$tiles = $this->tiles[$block * self::BLOCK + $i] ?? null;
				yield [
					($x << 32) >> 32, ($y << 32) >> 32, ($z << 32) >> 32,
					$tiles === null || $tiles[0] === null ? BlockValue::of($before) : new BlockValue($before, $tiles[0]),
					$tiles === null || $tiles[1] === null ? BlockValue::of($after) : new BlockValue($after, $tiles[1]),
				];
			}
		}
	}

	private function seal() : void{
		$compressed = gzdeflate($this->records, 1);
		if($compressed === false){
			throw new \RuntimeException('History compression failed');
		}
		$this->sealed[] = $compressed;
		$this->sealedBytes += strlen($compressed);
		$this->records = '';
	}

	private function setTiles(int $index, ?string $before, ?string $after) : void{
		$old = $this->tiles[$index] ?? null;
		if($old !== null){
			$this->tileBytes -= strlen($old[0] ?? '') + strlen($old[1] ?? '') + self::TILE_OVERHEAD;
			unset($this->tiles[$index]);
		}
		if($before !== null || $after !== null){
			$this->tiles[$index] = [$before, $after];
			$this->tileBytes += strlen($before ?? '') + strlen($after ?? '') + self::TILE_OVERHEAD;
		}
	}
}
