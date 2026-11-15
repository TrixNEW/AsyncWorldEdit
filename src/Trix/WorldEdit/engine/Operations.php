<?php

declare(strict_types=1);

namespace Trix\WorldEdit\engine;

use Trix\WorldEdit\geometry\Region;
use Trix\WorldEdit\pattern\Mask;
use Trix\WorldEdit\pattern\Pattern;

final class Operations{
	/** @return \Generator<int, array{int,int,int,BlockValue,?BlockValue}|null, void, void> */
	public static function fill(WorldAccess $world, Region $region, Pattern $pattern, ?Mask $mask = null, string $mode = 'set', int $seed = 0) : \Generator{
		foreach($region->positions() as [$x, $y, $z]){
			if(($mode === 'walls' || $mode === 'faces') && !$region->isBoundary($x, $y, $z, $mode === 'walls')){
				yield null;
				continue;
			}
			if($mode === 'hollow' && $region->isBoundary($x, $y, $z)){
				yield null;
				continue;
			}
			$world->acquire($x >> 4, $z >> 4);
			$before = $world->read($x, $y, $z);
			if($mask !== null && !$mask->matches($x, $y, $z, $before->state)){
				yield null;
				continue;
			}
			$state = $pattern->choose($x, $y, $z, $before->state, $seed);
			yield $state === $before->state ? null : [$x, $y, $z, new BlockValue($state), $before];
		}
	}

	/**
	 * @param \Closure(array<int,int>):void $result
	 * @return \Generator<int, null, void, void>
	 */
	public static function distribution(WorldAccess $world, Region $region, \Closure $result, ?Mask $mask = null) : \Generator{
		$counts = [];
		foreach($region->positions() as [$x, $y, $z]){
			$world->acquire($x >> 4, $z >> 4);
			$state = $world->read($x, $y, $z)->state;
			if($mask === null || $mask->matches($x, $y, $z, $state)){
				$counts[$state] = ($counts[$state] ?? 0) + 1;
			}
			yield null;
		}
		arsort($counts);
		$result($counts);
	}
}
