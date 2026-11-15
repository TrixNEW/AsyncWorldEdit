<?php

declare(strict_types=1);

namespace asyncworldedit\engine;

use asyncworldedit\geometry\Region;
use asyncworldedit\pattern\Mask;
use asyncworldedit\pattern\Pattern;
use Generator;

final class TerrainOperations{

	/** @return Generator<int, array{int, int, int, BlockValue, BlockValue}|null> */
	public static function overlay(WorldAccess $world, Region $region, Pattern $pattern, ?Mask $mask = null, int $air = 0, int $seed = 0) : Generator{
		for($x = $region->minX; $x <= $region->maxX; ++$x){
			for($z = $region->minZ; $z <= $region->maxZ; ++$z){
				for($y = $region->maxY; $y >= $region->minY; --$y){
					$top = false;
					if($region->contains($x, $y, $z)){
						$world->acquire($x >> 4, $z >> 4);
						$top = $world->read($x, $y, $z)->state !== $air;
					}
					yield null;
					if(!$top){
						continue;
					}
					if($y + 1 < $world->maxY()){
						$world->acquire($x >> 4, $z >> 4);
						$expected = $world->read($x, $y + 1, $z);
						if($expected->state === $air && ($mask === null || $mask->matches($x, $y + 1, $z, $expected->state))){
							$value = new BlockValue($pattern->choose($x, $y + 1, $z, $expected->state, $seed));
							yield [$x, $y + 1, $z, $value, $expected];
						}else{
							yield null;
						}
					}
					break;
				}
			}
		}
	}

	/** @return Generator<int, array{int, int, int, BlockValue, BlockValue}|null> */
	public static function naturalize(WorldAccess $world, Region $region, int $grass, int $dirt, int $stone, int $air = 0) : Generator{
		$grassValue = new BlockValue($grass);
		$dirtValue = new BlockValue($dirt);
		$stoneValue = new BlockValue($stone);
		for($x = $region->minX; $x <= $region->maxX; ++$x){
			for($z = $region->minZ; $z <= $region->maxZ; ++$z){
				$surface = null;
				for($y = $region->maxY; $y >= $region->minY; --$y){
					if(!$region->contains($x, $y, $z)){
						yield null;
						continue;
					}
					$world->acquire($x >> 4, $z >> 4);
					$expected = $world->read($x, $y, $z);
					if($expected->state === $air){
						yield null;
						continue;
					}
					$surface ??= $y;
					$value = $y === $surface ? $grassValue : ($surface - $y <= 3 ? $dirtValue : $stoneValue);
					yield [$x, $y, $z, $value, $expected];
				}
			}
		}
	}

	/**
	 * A 3x3 height average preserves caves below the surface; at most twice the volume is visited.
	 * @return Generator<int, array{int, int, int, BlockValue, BlockValue}|null>
	 */
	public static function smooth(WorldAccess $world, Region $region, int $air = 0, int $maxBlocks = 500000) : Generator{
		if($region->volume() > $maxBlocks){ throw new \InvalidArgumentException('Smoothing exceeds snapshot limit'); }
		$width = $region->maxX - $region->minX + 1;
		$length = $region->maxZ - $region->minZ + 1;
		$heightCount = $region->maxY - $region->minY + 1;
		$columns = $width * $length;
		$heights = str_repeat(pack("V", ($region->minY - 1) & 0xffffffff), $columns);
		$states = str_repeat(pack("V", $air), $columns);
		$snapshot = str_repeat(pack('V', $air), $region->volume());
		for($x = $region->minX; $x <= $region->maxX; ++$x){
			for($z = $region->minZ; $z <= $region->maxZ; ++$z){
				$index = ($x - $region->minX) * $length + $z - $region->minZ;
				$found = false;
				for($y = $region->maxY; $y >= $region->minY; --$y){
					if($region->contains($x, $y, $z)){
						$world->acquire($x >> 4, $z >> 4);
						$block = $world->read($x, $y, $z);
						if($block->nbt !== null){ throw new \InvalidArgumentException('Smoothing does not support block entities'); }
						self::setPacked($snapshot, $index * $heightCount + $y - $region->minY, $block->state);
						if(!$found && $block->state !== $air){
							self::setPacked($heights, $index, $y & 0xffffffff);
							self::setPacked($states, $index, $block->state);
							$found = true;
						}
					}
					yield null;
				}
			}
		}
		for($x = $region->minX; $x <= $region->maxX; ++$x){
			for($z = $region->minZ; $z <= $region->maxZ; ++$z){
				$index = ($x - $region->minX) * $length + $z - $region->minZ;
				$height = self::height($heights, $index);
				$state = self::packed($states, $index);
				$sum = 0;
				$count = 0;
				for($nx = max($region->minX, $x - 1); $nx <= min($region->maxX, $x + 1); ++$nx){
					for($nz = max($region->minZ, $z - 1); $nz <= min($region->maxZ, $z + 1); ++$nz){
						$neighbor = ($nx - $region->minX) * $length + $nz - $region->minZ;
						$sum += self::height($heights, $neighbor);
						++$count;
						if($state === $air){
							$state = self::packed($states, $neighbor);
						}
					}
				}
				$average = (int) floor($sum / $count);
				if($average === $height){
					yield null;
				}
				for($y = min($height, $average) + 1, $end = max($height, $average); $y <= $end; ++$y){
					if(!$region->contains($x, $y, $z)){
						yield null;
						continue;
					}
					$expected = new BlockValue(self::packed($snapshot, $index * $heightCount + $y - $region->minY));
					$value = new BlockValue($average < $height ? $air : $state);
					yield [$x, $y, $z, $value, $expected];
				}
			}
		}
	}

	private static function setPacked(string &$buffer, int $index, int $value) : void{
		$packed = pack("V", $value);
		for($byte = 0; $byte < 4; ++$byte){
			$buffer[$index * 4 + $byte] = $packed[$byte];
		}
	}

	private static function packed(string $buffer, int $index) : int{
		$decoded = unpack("V", $buffer, $index * 4);
		if($decoded === false){
			throw new \LogicException("Invalid terrain heightmap");
		}
		return $decoded[1];
	}

	private static function height(string $buffer, int $index) : int{
		$height = self::packed($buffer, $index);
		return $height >= 0x80000000 ? $height - 4294967296 : $height;
	}
}
