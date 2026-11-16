<?php

declare(strict_types=1);

namespace Trix\WorldEdit\clipboard;

use Trix\WorldEdit\engine\BlockValue;
use Trix\WorldEdit\geometry\Region;
use InvalidArgumentException;

/** Packed uint32LE states in YZX order. Rotations and flips share the packed data and only change the matrix. */
final class Clipboard{
	private const IDENTITY = [1, 0, 0, 0, 1, 0, 0, 0, 1];
	private const TILE_OVERHEAD = 128;

	/** @var array<int, int> */
	private array $matrix = self::IDENTITY;
	private readonly int $size;

	/**
	 * @param array<int, string> $tiles normalized little-endian block entity NBT by block index
	 * @param array{int, int, int} $min original selection minimum
	 * @param array{int, int, int} $origin copy pivot
	 */
	public function __construct(
		public readonly int $width,
		public readonly int $height,
		public readonly int $length,
		public readonly string $states,
		public readonly array $tiles = [],
		public readonly array $min = [0, 0, 0],
		public readonly array $origin = [0, 0, 0],
		int $maxBlocks = 500000
	){
		if($maxBlocks < 1 || $width < 1 || $height < 1 || $length < 1 || $width > $maxBlocks || $height > intdiv($maxBlocks, $width) || $length > intdiv($maxBlocks, $width * $height)){
			throw new InvalidArgumentException('Clipboard dimensions exceed the block limit');
		}
		$volume = $width * $height * $length;
		if(strlen($states) !== $volume * 4){
			throw new InvalidArgumentException('Clipboard states must contain one uint32LE per block');
		}
		self::validatePoint($min);
		self::validatePoint($origin);
		$tileBytes = 0;
		foreach($tiles as $index => $nbt){
			if($index < 0 || $index >= $volume || $nbt === '' || strlen($nbt) > 65536){
				throw new InvalidArgumentException('Invalid clipboard block entity');
			}
			$tileBytes += strlen($nbt) + self::TILE_OVERHEAD;
		}
		$this->size = strlen($states) + $tileBytes;
	}

	public function volume() : int{
		return $this->width * $this->height * $this->length;
	}

	public function bytes() : int{
		return $this->size;
	}

	public function isTransformed() : bool{
		return $this->matrix !== self::IDENTITY;
	}

	/** Block at local, untransformed coordinates. */
	public function at(int $x, int $y, int $z) : BlockValue{
		if($x < 0 || $x >= $this->width || $y < 0 || $y >= $this->height || $z < 0 || $z >= $this->length){
			throw new \OutOfBoundsException('Clipboard coordinates are outside its dimensions');
		}
		$index = ($y * $this->length + $z) * $this->width + $x;
		$state = unpack('V', $this->states, $index * 4)[1] ?? throw new \LogicException('Invalid packed clipboard state');
		$nbt = $this->tiles[$index] ?? null;
		return $nbt === null ? BlockValue::of($state) : new BlockValue($state, $nbt);
	}

	public function rotate(int $degrees) : self{
		if($degrees % 90 !== 0){
			throw new InvalidArgumentException('Clipboard rotation must be a multiple of 90 degrees');
		}
		$result = clone $this;
		for($turn = intdiv((($degrees % 360) + 360) % 360, 90); $turn > 0; --$turn){
			$m = $result->matrix;
			$result->matrix = [-$m[6], -$m[7], -$m[8], $m[3], $m[4], $m[5], $m[0], $m[1], $m[2]];
		}
		return $result->validated();
	}

	public function flip(string $axis) : self{
		$row = match(strtolower($axis)){
			'x' => 0, 'y' => 3, 'z' => 6,
			default => throw new InvalidArgumentException('Clipboard flip axis must be x, y or z')
		};
		$result = clone $this;
		for($column = 0; $column < 3; ++$column){
			$result->matrix[$row + $column] *= -1;
		}
		return $result->validated();
	}

	/**
	 * Yields every block relative to the pivot, transformed. Mirrors apply before yaw.
	 *
	 * @param (\Closure(int, int, bool, bool, bool) : int)|null $transformState returns the rotated or mirrored state
	 * @return \Generator<int, array{int, int, int, BlockValue}, void, void>
	 */
	public function blocks(?\Closure $transformState = null) : \Generator{
		[$turns, $flipX, $flipY] = $this->orientation();
		$transformState = $this->isTransformed() ? $transformState : null;
		$states = [];
		[$m0, $m1, $m2, $m3, $m4, $m5, $m6, $m7, $m8] = $this->matrix;
		[$offsetX, $offsetY, $offsetZ] = [$this->min[0] - $this->origin[0], $this->min[1] - $this->origin[1], $this->min[2] - $this->origin[2]];
		$width = $this->width;
		$index = 0;
		for($y = 0; $y < $this->height; ++$y){
			$ry = $y + $offsetY;
			for($z = 0; $z < $this->length; ++$z){
				$rz = $z + $offsetZ;
				$row = unpack('V' . $width, $this->states, $index * 4) ?: throw new \LogicException('Invalid packed clipboard state');
				for($x = 0; $x < $width; ++$x, ++$index){
					$state = $row[$x + 1];
					if($transformState !== null){
						$state = $states[$state] ??= $transformState($state, $turns, $flipX, $flipY, false);
					}
					$nbt = $this->tiles[$index] ?? null;
					$rx = $x + $offsetX;
					yield [$m0 * $rx + $m1 * $ry + $m2 * $rz, $m3 * $rx + $m4 * $ry + $m5 * $rz, $m6 * $rx + $m7 * $ry + $m8 * $rz, $nbt === null ? BlockValue::of($state) : new BlockValue($state, $nbt)];
				}
			}
		}
	}

	/** @param array{int, int, int} $pivot */
	public function bounds(array $pivot) : Region{
		self::validatePoint($pivot);
		[$min, $max] = $this->relativeBounds();
		return new Region($min[0] + $pivot[0], $min[1] + $pivot[1], $min[2] + $pivot[2], $max[0] + $pivot[0], $max[1] + $pivot[1], $max[2] + $pivot[2]);
	}

	/** @return array{array{int, int, int}, array{int, int, int}} transformed minimum and maximum relative to the pivot */
	public function relativeBounds() : array{
		$first = $this->transform($this->min[0] - $this->origin[0], $this->min[1] - $this->origin[1], $this->min[2] - $this->origin[2]);
		$last = $this->transform($this->min[0] + $this->width - 1 - $this->origin[0], $this->min[1] + $this->height - 1 - $this->origin[1], $this->min[2] + $this->length - 1 - $this->origin[2]);
		return [
			[min($first[0], $last[0]), min($first[1], $last[1]), min($first[2], $last[2])],
			[max($first[0], $last[0]), max($first[1], $last[1]), max($first[2], $last[2])]
		];
	}

	/** @return array{int, int, int} */
	private function transform(int $x, int $y, int $z) : array{
		$m = $this->matrix;
		return [$m[0] * $x + $m[1] * $y + $m[2] * $z, $m[3] * $x + $m[4] * $y + $m[5] * $z, $m[6] * $x + $m[7] * $y + $m[8] * $z];
	}

	/** @return array{int, bool, bool} clockwise quarter turns, X mirror and Y mirror */
	private function orientation() : array{
		$m = $this->matrix;
		$flipX = $m[0] * $m[8] - $m[2] * $m[6] < 0;
		$x = $m[0] * ($flipX ? -1 : 1);
		$z = $m[6] * ($flipX ? -1 : 1);
		return [$x === 1 ? 0 : ($z === 1 ? 1 : ($x === -1 ? 2 : 3)), $flipX, $m[4] < 0];
	}

	private function validated() : self{
		if($this->tiles !== [] && $this->isTransformed()){
			throw new InvalidArgumentException('Rotating or flipping block entities requires directional NBT transforms');
		}
		return $this;
	}

	/** @param array<array-key, mixed> $point */
	private static function validatePoint(array $point) : void{
		if(array_keys($point) !== [0, 1, 2] || !is_int($point[0]) || !is_int($point[1]) || !is_int($point[2]) || abs($point[0]) > 30000000 || abs($point[1]) > 2048 || abs($point[2]) > 30000000){
			throw new InvalidArgumentException('Clipboard pivot must contain three supported integer coordinates');
		}
	}
}
