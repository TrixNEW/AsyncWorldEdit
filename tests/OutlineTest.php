<?php

declare(strict_types=1);

namespace Trix\WorldEdit\tests;

use Trix\WorldEdit\geometry\Region;
use Trix\WorldEdit\quark\SelectionOutline;
use PHPUnit\Framework\TestCase;

final class OutlineTest extends TestCase{
	public function testOutlineSamplingHasConstantPacketBoundAtAnySelectionSize() : void{
		foreach(['cuboid','ellipsoid','cylinder'] as $shape){
			$region=new Region(-10000,-64,-10000,10000,319,10000,$shape);
			$points=iterator_to_array(SelectionOutline::points($region));
			self::assertCount(72,$points);
			foreach($points as [$x,$y,$z]){
				self::assertGreaterThanOrEqual($region->minX,$x); self::assertLessThanOrEqual($region->maxX+1,$x);
				self::assertGreaterThanOrEqual($region->minY,$y); self::assertLessThanOrEqual($region->maxY+1,$y);
				self::assertGreaterThanOrEqual($region->minZ,$z); self::assertLessThanOrEqual($region->maxZ+1,$z);
			}
		}
	}
}
