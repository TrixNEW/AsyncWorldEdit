<?php

declare(strict_types=1);

namespace Trix\WorldEdit\clipboard;

use Trix\WorldEdit\engine\BlockValue;
use Trix\WorldEdit\geometry\Region;
use Closure;
use Generator;
use InvalidArgumentException;
use OutOfBoundsException;

final class Clipboard{

	private const IDENTITY = [1, 0, 0, 0, 1, 0, 0, 0, 1];
	/** @var array<int, int> */
	private array $matrix = self::IDENTITY;
	private readonly int $size;

	/**
	 * @param array<int, string> $tiles Normalized little-endian block entity NBT, indexed in YZX order.
	 * @param array{int, int, int} $min Original selection minimum.
	 * @param array{int, int, int} $origin Player's copy pivot.
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
		if($maxBlocks < 1 || $maxBlocks > intdiv(PHP_INT_MAX, 4)){
			throw new InvalidArgumentException("Invalid clipboard block limit");
		}
		$volume = 1;
		foreach([$width, $height, $length] as $size){
			if($size < 1 || $size > intdiv($maxBlocks, $volume)){
				throw new InvalidArgumentException("Clipboard dimensions exceed the block limit");
			}
			$volume *= $size;
		}
		if(strlen($states) !== $volume * 4){
			throw new InvalidArgumentException("Clipboard states must contain one uint32LE per block");
		}
		self::validatePoint($min);
		self::validatePoint($origin);
		$this->size = strlen($states) + self::validateTiles($tiles, $volume);
	}

	public function volume() : int{
		return $this->width * $this->height * $this->length;
	}

	public function bytes() : int{
		return $this->size;
	}

	public function at(int $x, int $y, int $z) : BlockValue{
		if($x < 0 || $x >= $this->width || $y < 0 || $y >= $this->height || $z < 0 || $z >= $this->length){
			throw new OutOfBoundsException("Clipboard coordinates are outside its dimensions");
		}
		$index = ($y * $this->length + $z) * $this->width + $x;
		$decoded = unpack("V", $this->states, $index * 4);
		if($decoded === false){
			throw new \LogicException("Invalid packed clipboard state");
		}
		return new BlockValue($decoded[1], $this->tiles[$index] ?? null);
	}

	public function rotate(int $degrees) : self{
		if($degrees % 90 !== 0){
			throw new InvalidArgumentException("Clipboard rotation must be a multiple of 90 degrees");
		}
		$result = clone $this;
		$turns = intdiv((($degrees % 360) + 360) % 360, 90);
		for($turn = 0; $turn < $turns; ++$turn){
			$m = $result->matrix;
			$result->matrix = [-$m[6], -$m[7], -$m[8], $m[3], $m[4], $m[5], $m[0], $m[1], $m[2]];
		}
		$result->validateTransform();
		return $result;
	}

	public function flip(string $axis) : self{
		$row = match(strtolower($axis)){
			"x" => 0, "y" => 3, "z" => 6,
			default => throw new InvalidArgumentException("Clipboard flip axis must be x, y or z")
		};
		$result = clone $this;
		for($column = 0; $column < 3; ++$column){
			$result->matrix[$row + $column] *= -1;
		}
		$result->validateTransform();
		return $result;
	}

	/**
	 * Mirrors are applied before yaw; the callback must return a valid runtime state.
	 * @param Closure(int, int, bool, bool, bool): int|null $stateTransform
	 * @return Generator<int, array{int, int, int, BlockValue}>
	 */
	public function coordinates(?Closure $stateTransform = null) : Generator{
		$cache = [];
		[$turns, $flipX, $flipY] = $this->orientation();
		$transform = $stateTransform !== null && $this->matrix !== self::IDENTITY;
		for($y = 0; $y < $this->height; ++$y){
			for($z = 0; $z < $this->length; ++$z){
				for($x = 0; $x < $this->width; ++$x){
					$block = $this->at($x, $y, $z);
					if($transform){
						$state = $cache[$block->state] ?? $stateTransform($block->state, $turns, $flipX, $flipY, false);
						if(count($cache) < 65536){
							$cache[$block->state] = $state;
						}
						$block = new BlockValue($state, $block->nbt);
					}
					[$dx, $dy, $dz] = $this->transform($x + $this->min[0] - $this->origin[0], $y + $this->min[1] - $this->origin[1], $z + $this->min[2] - $this->origin[2]);
					yield [$dx, $dy, $dz, $block];
				}
			}
		}
	}

	/** @param array{int, int, int} $targetPivot */
	public function bounds(array $targetPivot) : Region{
		self::validatePoint($targetPivot);
		$bounds = $this->relativeBounds();
		return new Region(
			$bounds["min"][0] + $targetPivot[0], $bounds["min"][1] + $targetPivot[1], $bounds["min"][2] + $targetPivot[2],
			$bounds["max"][0] + $targetPivot[0], $bounds["max"][1] + $targetPivot[1], $bounds["max"][2] + $targetPivot[2]
		);
	}

	/** @return array{min: array{int, int, int}, max: array{int, int, int}} */
	public function relativeBounds() : array{
		$first = $this->transform($this->min[0] - $this->origin[0], $this->min[1] - $this->origin[1], $this->min[2] - $this->origin[2]);
		$last = $this->transform($this->min[0] + $this->width - 1 - $this->origin[0], $this->min[1] + $this->height - 1 - $this->origin[1], $this->min[2] + $this->length - 1 - $this->origin[2]);
		return [
			"min" => [min($first[0], $last[0]), min($first[1], $last[1]), min($first[2], $last[2])],
			"max" => [max($first[0], $last[0]), max($first[1], $last[1]), max($first[2], $last[2])]
		];
	}

	/** @return array{int, int, int} */
	private function transform(int $x, int $y, int $z) : array{
		$m = $this->matrix;
		return [$m[0] * $x + $m[1] * $y + $m[2] * $z, $m[3] * $x + $m[4] * $y + $m[5] * $z, $m[6] * $x + $m[7] * $y + $m[8] * $z];
	}

	/** @return array{int, bool, bool} */
	private function orientation() : array{
		$m = $this->matrix;
		$flipX = $m[0] * $m[8] - $m[2] * $m[6] < 0;
		$x = $m[0] * ($flipX ? -1 : 1);
		$z = $m[6] * ($flipX ? -1 : 1);
		$turns = $x === 1 ? 0 : ($z === 1 ? 1 : ($x === -1 ? 2 : 3));
		return [$turns, $flipX, $m[4] < 0];
	}

	private function validateTransform() : void{
		if($this->tiles !== [] && $this->matrix !== self::IDENTITY){
			throw new InvalidArgumentException("Rotating or flipping block entities requires directional NBT transforms");
		}
	}

	/** @param array<array-key, mixed> $point */
	private static function validatePoint(array $point) : void{
		if(array_keys($point) !== [0, 1, 2] || !is_int($point[0]) || !is_int($point[1]) || !is_int($point[2]) || abs($point[0]) > 30000000 || abs($point[1]) > 2048 || abs($point[2]) > 30000000){
			throw new InvalidArgumentException("Clipboard pivot must contain three supported integer coordinates");
		}
	}

	/** @param array<array-key, mixed> $tiles */
	private static function validateTiles(array $tiles, int $volume) : int{
		$bytes = 0;
		foreach($tiles as $index => $nbt){
			if(!is_int($index) || $index < 0 || $index >= $volume || !is_string($nbt) || $nbt === "" || strlen($nbt) > 65536){
				throw new InvalidArgumentException("Invalid clipboard block entity");
			}
			$bytes += strlen($nbt) + 128;
		}
		return $bytes;
	}
}
