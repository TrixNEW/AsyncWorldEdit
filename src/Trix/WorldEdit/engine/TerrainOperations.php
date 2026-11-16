<?php

declare(strict_types=1);

namespace Trix\WorldEdit\engine;

use Trix\WorldEdit\geometry\Region;
use Trix\WorldEdit\pattern\Pattern;

/** @phpstan-import-type Steps from Job */
final class TerrainOperations{

	/**
	 * Places the pattern on top of the highest block of every column. Writes can reach one block above the region.
	 *
	 * @return Steps
	 */
	public static function overlay(WorldAccess $world, Region $region, Pattern $pattern, int $air = 0, int $seed = 0) : \Generator{
		for($x = $region->minX; $x <= $region->maxX; ++$x){
			for($z = $region->minZ; $z <= $region->maxZ; ++$z){
				for($y = $region->maxY; $y >= $region->minY; --$y){
					// Chunks are released at the end of each tick, so pin again after every yield.
					$world->acquire($x >> 4, $z >> 4);
					$top = $region->contains($x, $y, $z) && $world->stateAt($x, $y, $z) !== $air;
					yield null;
					if(!$top){
						continue;
					}
					if($y + 1 < $world->maxY()){
						$world->acquire($x >> 4, $z >> 4);
						$above = $world->read($x, $y + 1, $z);
						yield $above->state === $air ? [$x, $y + 1, $z, BlockValue::of($pattern->choose($x, $y + 1, $z, $air, $seed)), $above] : null;
					}
					break;
				}
			}
		}
	}

	/**
	 * Turns each column into grass, three blocks of dirt, then stone.
	 *
	 * @return Steps
	 */
	public static function naturalize(WorldAccess $world, Region $region, int $grass, int $dirt, int $stone, int $air = 0) : \Generator{
		$grassValue = BlockValue::of($grass);
		$dirtValue = BlockValue::of($dirt);
		$stoneValue = BlockValue::of($stone);
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
					yield [$x, $y, $z, $y === $surface ? $grassValue : ($surface - $y <= 3 ? $dirtValue : $stoneValue), $expected];
				}
			}
		}
	}

	/**
	 * Averages surface heights over a 3x3 neighbourhood, leaving caves below the surface alone.
	 * The region is snapshotted first, so it visits at most twice its volume.
	 *
	 * @return Steps
	 */
	public static function smooth(WorldAccess $world, Region $region, int $air = 0, int $maxBlocks = 500000) : \Generator{
		if($region->volume() > $maxBlocks){
			throw new \InvalidArgumentException('Smoothing exceeds the snapshot limit');
		}
		[$width, $columnHeight, $length] = $region->size();
		$heights = str_repeat(pack('V', $region->minY - 1), $width * $length);
		$surfaces = str_repeat(pack('V', $air), $width * $length);
		$snapshot = str_repeat(pack('V', $air), $region->volume());
		for($x = $region->minX; $x <= $region->maxX; ++$x){
			for($z = $region->minZ; $z <= $region->maxZ; ++$z){
				$column = ($x - $region->minX) * $length + $z - $region->minZ;
				$found = false;
				for($y = $region->maxY; $y >= $region->minY; --$y){
					if($region->contains($x, $y, $z)){
						$world->acquire($x >> 4, $z >> 4);
						$block = $world->read($x, $y, $z);
						if($block->nbt !== null){
							throw new \InvalidArgumentException('Smoothing does not support block entities');
						}
						self::put($snapshot, $column * $columnHeight + $y - $region->minY, $block->state);
						if(!$found && $block->state !== $air){
							self::put($heights, $column, $y);
							self::put($surfaces, $column, $block->state);
							$found = true;
						}
					}
					yield null;
				}
			}
		}
		$airValue = BlockValue::of($air);
		for($x = $region->minX; $x <= $region->maxX; ++$x){
			for($z = $region->minZ; $z <= $region->maxZ; ++$z){
				$column = ($x - $region->minX) * $length + $z - $region->minZ;
				$height = self::signed(self::get($heights, $column));
				$surface = self::get($surfaces, $column);
				$sum = 0;
				$count = 0;
				for($nx = max($region->minX, $x - 1); $nx <= min($region->maxX, $x + 1); ++$nx){
					for($nz = max($region->minZ, $z - 1); $nz <= min($region->maxZ, $z + 1); ++$nz){
						$neighbour = ($nx - $region->minX) * $length + $nz - $region->minZ;
						$sum += self::signed(self::get($heights, $neighbour));
						++$count;
						if($surface === $air){
							$surface = self::get($surfaces, $neighbour);
						}
					}
				}
				$average = (int) floor($sum / $count);
				if($average === $height){
					yield null;
					continue;
				}
				$value = $average < $height ? $airValue : BlockValue::of($surface);
				for($y = min($height, $average) + 1, $end = max($height, $average); $y <= $end; ++$y){
					yield $region->contains($x, $y, $z) ? [$x, $y, $z, $value, BlockValue::of(self::get($snapshot, $column * $columnHeight + $y - $region->minY))] : null;
				}
			}
		}
	}

	private static function put(string &$buffer, int $index, int $value) : void{
		$packed = pack('V', $value);
		$offset = $index * 4;
		$buffer[$offset] = $packed[0];
		$buffer[$offset + 1] = $packed[1];
		$buffer[$offset + 2] = $packed[2];
		$buffer[$offset + 3] = $packed[3];
	}

	private static function get(string $buffer, int $index) : int{
		return unpack('V', $buffer, $index * 4)[1] ?? throw new \LogicException('Invalid terrain snapshot');
	}

	private static function signed(int $value) : int{
		return ($value << 32) >> 32;
	}
}
