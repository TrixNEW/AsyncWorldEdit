<?php

declare(strict_types=1);

namespace Trix\WorldEdit\geometry;

use Generator;
use InvalidArgumentException;

final readonly class Region{

	public function __construct(
		public int $minX,
		public int $minY,
		public int $minZ,
		public int $maxX,
		public int $maxY,
		public int $maxZ,
		public string $shape = "cuboid"
	){
		if($minX < -30000000 || $maxX > 30000000 || $minZ < -30000000 || $maxZ > 30000000 || $minY < -2048 || $maxY > 2048 || $minX > $maxX || $minY > $maxY || $minZ > $maxZ){
			throw new InvalidArgumentException("Region bounds are inverted or outside supported coordinates");
		}
		if(!in_array($shape, ["cuboid", "ellipsoid", "cylinder"], true)){
			throw new InvalidArgumentException("Unknown region shape: " . $shape);
		}
		$this->volume();
	}

	/**
	 * @param array{int, int, int} $first
	 * @param array{int, int, int} $second
	 */
	public static function between(array $first, array $second, string $shape = "cuboid") : self{
		foreach([$first, $second] as $point){
			self::validatePoint($point);
		}
		return new self(min($first[0], $second[0]), min($first[1], $second[1]), min($first[2], $second[2]), max($first[0], $second[0]), max($first[1], $second[1]), max($first[2], $second[2]), $shape);
	}

	/** Returns the inclusive bounding-box volume, including for curved shapes. */
	public function volume() : int{
		$area = ($this->maxX - $this->minX + 1) * ($this->maxZ - $this->minZ + 1);
		$height = $this->maxY - $this->minY + 1;
		if($area > intdiv(PHP_INT_MAX, $height)){
			throw new InvalidArgumentException("Region volume exceeds the integer limit");
		}
		return $area * $height;
	}

	public function contains(int $x, int $y, int $z) : bool{
		if($x < $this->minX || $x > $this->maxX || $y < $this->minY || $y > $this->maxY || $z < $this->minZ || $z > $this->maxZ){
			return false;
		}
		if($this->shape === "cuboid"){
			return true;
		}
		$dx = (2 * $x - $this->minX - $this->maxX) / ($this->maxX - $this->minX + 1);
		$dz = (2 * $z - $this->minZ - $this->maxZ) / ($this->maxZ - $this->minZ + 1);
		$dy = $this->shape === "ellipsoid" ? (2 * $y - $this->minY - $this->maxY) / ($this->maxY - $this->minY + 1) : 0;
		return $dx * $dx + $dy * $dy + $dz * $dz <= 1.0;
	}

	/** @return Generator<int, array{int, int, int}> */
	public function positions() : Generator{
		for($chunkX = $this->minX >> 4; $chunkX <= $this->maxX >> 4; ++$chunkX){
			for($chunkZ = $this->minZ >> 4; $chunkZ <= $this->maxZ >> 4; ++$chunkZ){
				$endX = min($this->maxX, ($chunkX << 4) + 15);
				$endZ = min($this->maxZ, ($chunkZ << 4) + 15);
				for($x = max($this->minX, $chunkX << 4); $x <= $endX; ++$x){
					for($z = max($this->minZ, $chunkZ << 4); $z <= $endZ; ++$z){
						for($y = $this->minY; $y <= $this->maxY; ++$y){
							if($this->contains($x, $y, $z)){
								yield [$x, $y, $z];
							}
						}
					}
				}
			}
		}
	}

	public function isBoundary(int $x, int $y, int $z, bool $walls = false) : bool{
		return $this->contains($x, $y, $z) && (
			!$this->contains($x - 1, $y, $z) || !$this->contains($x + 1, $y, $z) ||
			!$this->contains($x, $y, $z - 1) || !$this->contains($x, $y, $z + 1) ||
			(!$walls && (!$this->contains($x, $y - 1, $z) || !$this->contains($x, $y + 1, $z)))
		);
	}

	public function shift(int $dx, int $dy, int $dz) : self{
		if($dx < -60000000 || $dx > 60000000 || $dz < -60000000 || $dz > 60000000 || $dy < -4096 || $dy > 4096){
			throw new InvalidArgumentException("Shift exceeds supported coordinates");
		}
		return new self($this->minX + $dx, $this->minY + $dy, $this->minZ + $dz, $this->maxX + $dx, $this->maxY + $dy, $this->maxZ + $dz, $this->shape);
	}

	public function expand(int $amount, string $direction) : self{
		return $this->resize($amount, $direction, false);
	}

	public function contract(int $amount, string $direction) : self{
		return $this->resize($amount, $direction, true);
	}

	private function resize(int $amount, string $direction, bool $contract) : self{
		if($amount < 0 || $amount > 60000000){
			throw new InvalidArgumentException("Resize amount must be between 0 and 60000000");
		}
		$bounds = [$this->minX, $this->minY, $this->minZ, $this->maxX, $this->maxY, $this->maxZ];
		$index = match(strtolower($direction)){
			"west" => 0, "down" => 1, "north" => 2,
			"east" => 3, "up" => 4, "south" => 5,
			default => throw new InvalidArgumentException("Unknown direction: " . $direction)
		};
		$bounds[$index] += $amount * (($index < 3) === $contract ? 1 : -1);
		return new self(...[...$bounds, $this->shape]);
	}

	/** @param array<array-key, mixed> $point */
	private static function validatePoint(array $point) : void{
		if(array_keys($point) !== [0, 1, 2] || !is_int($point[0]) || !is_int($point[1]) || !is_int($point[2])){
			throw new InvalidArgumentException("A position must contain three integer coordinates");
		}
	}
}
