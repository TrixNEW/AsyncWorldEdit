<?php

declare(strict_types=1);

namespace Trix\WorldEdit\engine;

use Trix\WorldEdit\clipboard\Clipboard;
use Trix\WorldEdit\geometry\Region;
use Trix\WorldEdit\pattern\Mask;
use Closure;
use Generator;
use InvalidArgumentException;

final class ClipboardOperations{

	/**
	 * Copy visits the bounding volume once; cut visits it at most twice.
	 * @param array{int, int, int} $pivot
	 * @param Closure(Clipboard): void $result
	 * @param Closure(Clipboard): void|null $prepared
	 * @return Generator<int, array{int, int, int, BlockValue, BlockValue}|null>
	 */
	public static function capture(WorldAccess $world, Region $region, array $pivot, int $maxBlocks, Closure $result, bool $cut = false, int $air = 0, ?Mask $mask = null, ?Closure $prepared = null) : Generator{
		if($region->volume() > $maxBlocks){
			throw new InvalidArgumentException("Selection exceeds the clipboard block limit");
		}
		$airValue = new BlockValue($air);
		$width = $region->maxX - $region->minX + 1;
		$height = $region->maxY - $region->minY + 1;
		$length = $region->maxZ - $region->minZ + 1;
		$states = str_repeat(pack("V", $air), $region->volume());
		$tiles = [];
		$tileBytes = 0;
		$box = new Region($region->minX, $region->minY, $region->minZ, $region->maxX, $region->maxY, $region->maxZ);
		foreach($box->positions() as [$x, $y, $z]){
			if($region->contains($x, $y, $z)){
				$world->acquire($x >> 4, $z >> 4);
				$value = $world->read($x, $y, $z);
				$index = (($y - $region->minY) * $length + $z - $region->minZ) * $width + $x - $region->minX;
				$packed = pack("V", $value->state);
				$offset = $index * 4;
				for($byte = 0; $byte < 4; ++$byte){
					$states[$offset + $byte] = $packed[$byte];
				}
				if($value->nbt !== null){
					$tileBytes += strlen($value->nbt) + 128;
					if($tileBytes > 4194304){
						throw new InvalidArgumentException("Clipboard block entities exceed the 4 MiB memory limit");
					}
					$tiles[$index] = $value->nbt;
				}
			}
			yield null;
		}
		$clipboard = new Clipboard($width, $height, $length, $states, $tiles, [$region->minX, $region->minY, $region->minZ], $pivot, $maxBlocks);
		unset($states, $tiles);
		$prepared?->__invoke($clipboard);
		if($cut){
			foreach($box->positions() as [$x, $y, $z]){
				if($region->contains($x, $y, $z)){
					$expected = $clipboard->at($x - $region->minX, $y - $region->minY, $z - $region->minZ);
					yield $mask === null || $mask->matches($x, $y, $z, $expected->state) ? [$x, $y, $z, $airValue, $expected] : null;
				}else{
					yield null;
				}
			}
		}
		$result($clipboard);
	}

	/**
	 * @param array{int, int, int} $targetPivot
	 * @param Closure(int, int, bool, bool, bool): int|null $stateTransform
	 * @return Generator<int, array{int, int, int, BlockValue, BlockValue}|null>
	 */
	public static function paste(WorldAccess $world, Clipboard $clipboard, array $targetPivot, bool $ignoreAir = false, int $air = 0, ?Closure $stateTransform = null, ?Mask $mask = null) : Generator{
		$clipboard->bounds($targetPivot);
		foreach($clipboard->coordinates($stateTransform) as [$dx, $dy, $dz, $value]){
			if($ignoreAir && $value->state === $air){
				yield null;
				continue;
			}
			[$x, $y, $z] = [$targetPivot[0] + $dx, $targetPivot[1] + $dy, $targetPivot[2] + $dz];
			$world->acquire($x >> 4, $z >> 4);
			$expected = $world->read($x, $y, $z);
			if($mask === null || $mask->matches($x, $y, $z, $expected->state)){
				yield [$x, $y, $z, $value, $expected];
			}else{
				yield null;
			}
		}
	}
}
