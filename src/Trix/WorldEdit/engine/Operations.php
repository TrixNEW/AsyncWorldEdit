<?php

declare(strict_types=1);

namespace Trix\WorldEdit\engine;

use Trix\WorldEdit\geometry\Region;
use Trix\WorldEdit\pattern\Mask;
use Trix\WorldEdit\pattern\Pattern;

/** @phpstan-import-type Steps from Job */
final class Operations{
	public const FILL_MODES = ['set', 'walls', 'faces', 'hollow'];

	/**
	 * Fills the region, its walls, its faces, or its interior (hollow).
	 *
	 * @return Steps
	 */
	public static function fill(WorldAccess $world, Region $region, Pattern $pattern, string $mode = 'set', int $seed = 0) : \Generator{
		if(!in_array($mode, self::FILL_MODES, true)){
			throw new \InvalidArgumentException("Unknown fill mode $mode");
		}
		$hollow = $mode === 'hollow';
		$cuboid = $region->shape === 'cuboid';
		foreach($region->columns() as [$x, $z]){
			for($y = $region->minY; $y <= $region->maxY; ++$y){
				if(!$cuboid && !$region->contains($x, $y, $z)){
					continue;
				}
				if($mode !== 'set' && $region->isBoundary($x, $y, $z, $mode === 'walls') === $hollow){
					yield null;
					continue;
				}
				// Chunks are released at the end of each tick, so pin again after every yield.
				$world->acquire($x >> 4, $z >> 4);
				$before = $world->read($x, $y, $z);
				$state = $pattern->choose($x, $y, $z, $before->state, $seed);
				yield $state === $before->state ? null : [$x, $y, $z, BlockValue::of($state), $before];
			}
		}
	}

	/**
	 * A stepped pyramid with its base centred on ($x, $y, $z).
	 *
	 * @return Steps
	 */
	public static function pyramid(WorldAccess $world, int $x, int $y, int $z, int $radius, Pattern $pattern, bool $hollow, int $seed = 0) : \Generator{
		$region = new Region($x - $radius, $y, $z - $radius, $x + $radius, $y + $radius, $z + $radius);
		foreach($region->positions() as [$bx, $by, $bz]){
			$edge = $radius - ($by - $y);
			$distance = max(abs($bx - $x), abs($bz - $z));
			if($distance > $edge || ($hollow && $by !== $y && $distance !== $edge)){
				yield null;
				continue;
			}
			$world->acquire($bx >> 4, $bz >> 4);
			$before = $world->read($bx, $by, $bz);
			$state = $pattern->choose($bx, $by, $bz, $before->state, $seed);
			yield $state === $before->state ? null : [$bx, $by, $bz, BlockValue::of($state), $before];
		}
	}

	/**
	 * Counts states, most common first.
	 *
	 * @return \Generator<int, null, mixed, array<int, int>>
	 */
	public static function distribution(WorldAccess $world, Region $region, ?Mask $mask = null) : \Generator{
		$counts = [];
		foreach($region->positions() as [$x, $y, $z]){
			$world->acquire($x >> 4, $z >> 4);
			$state = $world->stateAt($x, $y, $z);
			if($mask === null || $mask->matches($state)){
				$counts[$state] = ($counts[$state] ?? 0) + 1;
			}
			yield null;
		}
		arsort($counts);
		return $counts;
	}
}
