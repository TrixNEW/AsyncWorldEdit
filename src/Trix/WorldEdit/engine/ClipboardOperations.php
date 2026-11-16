<?php

declare(strict_types=1);

namespace Trix\WorldEdit\engine;

use Trix\WorldEdit\clipboard\Clipboard;
use Trix\WorldEdit\geometry\Region;
use Trix\WorldEdit\pattern\Mask;

/** @phpstan-import-type Steps from Job */
final class ClipboardOperations{
	private const MAX_TILE_BYTES = 4194304;

	/**
	 * Copies the region; a cut then replaces copied blocks matching $mask with air. Returns the clipboard.
	 * $prepared runs between the two passes, so a clipboard that cannot be kept fails before anything is cut.
	 *
	 * @param array{int, int, int} $pivot
	 * @param (\Closure(Clipboard) : void)|null $prepared
	 * @return \Generator<int, array{int, int, int, BlockValue, BlockValue}|null, mixed, Clipboard>
	 */
	public static function capture(WorldAccess $world, Region $region, array $pivot, int $maxBlocks, bool $cut = false, int $air = 0, ?Mask $mask = null, ?\Closure $prepared = null) : \Generator{
		if($region->volume() > $maxBlocks){
			throw new \InvalidArgumentException('Selection exceeds the clipboard block limit');
		}
		[$width, , $length] = $region->size();
		$states = str_repeat(pack('V', $air), $region->volume());
		$tiles = [];
		$tileBytes = 0;
		$box = new Region($region->minX, $region->minY, $region->minZ, $region->maxX, $region->maxY, $region->maxZ);
		foreach($box->positions() as [$x, $y, $z]){
			if($region->contains($x, $y, $z)){
				$world->acquire($x >> 4, $z >> 4);
				$value = $world->read($x, $y, $z);
				$index = (($y - $region->minY) * $length + $z - $region->minZ) * $width + $x - $region->minX;
				if($value->state !== $air){
					// Byte writes modify the string in place; substr_replace() would copy it for every block.
					$packed = pack('V', $value->state);
					$offset = $index * 4;
					$states[$offset] = $packed[0];
					$states[$offset + 1] = $packed[1];
					$states[$offset + 2] = $packed[2];
					$states[$offset + 3] = $packed[3];
				}
				if($value->nbt !== null){
					$tileBytes += strlen($value->nbt) + 128;
					if($tileBytes > self::MAX_TILE_BYTES){
						throw new \InvalidArgumentException('Clipboard block entities exceed the 4 MiB limit');
					}
					$tiles[$index] = $value->nbt;
				}
			}
			yield null;
		}
		$clipboard = new Clipboard($width, $region->maxY - $region->minY + 1, $length, $states, $tiles, [$region->minX, $region->minY, $region->minZ], $pivot, $maxBlocks);
		unset($states, $tiles);
		if($prepared !== null){
			$prepared($clipboard);
		}
		if($cut){
			$airValue = BlockValue::of($air);
			foreach($box->positions() as [$x, $y, $z]){
				$copied = $region->contains($x, $y, $z) ? $clipboard->at($x - $region->minX, $y - $region->minY, $z - $region->minZ) : null;
				yield $copied !== null && ($mask === null || $mask->matches($copied->state)) ? [$x, $y, $z, $airValue, $copied] : null;
			}
		}
		return $clipboard;
	}

	/**
	 * Pastes relative to $pivot. $source skips clipboard blocks that do not match it, so a masked move or stack
	 * places exactly the blocks its cut removed.
	 *
	 * @param array{int, int, int} $pivot
	 * @param (\Closure(int, int, bool, bool, bool) : int)|null $transformState
	 * @return Steps
	 */
	public static function paste(WorldAccess $world, Clipboard $clipboard, array $pivot, bool $skipAir = false, int $air = 0, ?\Closure $transformState = null, ?Mask $source = null) : \Generator{
		$clipboard->bounds($pivot);
		[$px, $py, $pz] = $pivot;
		foreach($clipboard->blocks($transformState) as [$dx, $dy, $dz, $value]){
			if(($skipAir && $value->state === $air) || ($source !== null && !$source->matches($value->state))){
				yield null;
				continue;
			}
			$x = $px + $dx;
			$z = $pz + $dz;
			$world->acquire($x >> 4, $z >> 4);
			yield [$x, $py + $dy, $z, $value, $world->read($x, $py + $dy, $z)];
		}
	}
}
