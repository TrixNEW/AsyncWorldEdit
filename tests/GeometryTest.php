<?php

declare(strict_types=1);

namespace asyncworldedit\tests;

use asyncworldedit\geometry\Region;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class GeometryTest extends TestCase{

	public function testInclusiveBoundsAndImmutability() : void{
		$region = Region::between([2, 4, 6], [-2, -4, -6]);
		self::assertSame(5 * 9 * 13, $region->volume());
		self::assertTrue($region->contains(-2, -4, -6));
		self::assertTrue($region->contains(2, 4, 6));
		self::assertFalse($region->contains(3, 4, 6));
		$shifted = $region->shift(1, 2, 3);
		self::assertSame([-1, -2, -3, 3, 6, 9], [$shifted->minX, $shifted->minY, $shifted->minZ, $shifted->maxX, $shifted->maxY, $shifted->maxZ]);
		self::assertSame(-2, $region->minX);
		self::assertSame(1, (new Region(0, 0, 0, 0, 0, 0))->volume());
	}

	public function testTraversalGroupsNegativeChunksWithoutMissingPositions() : void{
		$region = new Region(-17, -1, -17, 16, 1, 16);
		$visited = [];
		$chunks = [];
		$last = null;
		foreach($region->positions() as [$x, $y, $z]){
			$key = "$x:$y:$z";
			self::assertArrayNotHasKey($key, $visited);
			self::assertTrue($region->contains($x, $y, $z));
			$visited[$key] = true;
			$chunk = ($x >> 4) . ":" . ($z >> 4);
			if($last !== $chunk){
				self::assertArrayNotHasKey($chunk, $chunks);
				$chunks[$chunk] = true;
				$last = $chunk;
			}
		}
		self::assertCount($region->volume(), $visited);
		self::assertCount(16, $chunks);
		self::assertArrayHasKey("-17:-1:-17", $visited);
		self::assertArrayHasKey("16:1:16", $visited);
	}

	public function testCurvedSelectionsAndBoundaries() : void{
		$ellipsoid = new Region(-1, -1, -1, 1, 1, 1, "ellipsoid");
		self::assertCount(19, iterator_to_array($ellipsoid->positions()));
		self::assertTrue($ellipsoid->contains(0, 0, 0));
		self::assertTrue($ellipsoid->contains(1, 0, 0));
		self::assertFalse($ellipsoid->contains(1, 1, 1));
		$cylinder = new Region(-2, -1, -2, 2, 1, 2, "cylinder");
		self::assertTrue($cylinder->contains(2, 1, 0));
		self::assertFalse($cylinder->contains(2, 0, 2));
		self::assertFalse($cylinder->contains(0, 2, 0));
		self::assertCount(63, iterator_to_array($cylinder->positions()));
		$flat = new Region(-2, 0, -2, 2, 0, 2);
		self::assertTrue($flat->isBoundary(0, 0, 0));
		self::assertFalse($flat->isBoundary(0, 0, 0, true));
		self::assertTrue($flat->isBoundary(2, 0, 0, true));
		self::assertFalse($flat->isBoundary(3, 0, 0));
		foreach(["ellipsoid", "cylinder"] as $shape){
			self::assertSame([[5, 7, 9]], iterator_to_array((new Region(5, 7, 9, 5, 7, 9, $shape))->positions()));
		}
	}

	public function testDirectionalResizing() : void{
		$region = new Region(-3, -3, -3, 3, 3, 3);
		foreach(["north", "south", "east", "west", "up", "down"] as $direction){
			self::assertEquals($region, $region->expand(2, $direction)->contract(2, $direction));
			self::assertSame(9 * 7 * 7, $region->expand(2, $direction)->volume());
			self::assertSame(5 * 7 * 7, $region->contract(2, $direction)->volume());
		}
		self::assertSame(-5, $region->expand(2, "north")->minZ);
		self::assertSame(5, $region->expand(2, "east")->maxX);
		self::assertSame(-1, $region->contract(2, "west")->minX);
	}

	public function testInvalidBoundsAndArithmeticAreRejected() : void{
		$invalid = [
			static fn() => new Region(1, 0, 0, 0, 0, 0),
			static fn() => new Region(-30000001, 0, 0, 0, 0, 0),
			static fn() => new Region(0, 0, 0, 30000001, 0, 0),
			static fn() => new Region(0, -2049, 0, 0, 0, 0),
			static fn() => new Region(0, 0, 0, 0, 2049, 0),
			static fn() => new Region(-30000000, -2048, -30000000, 30000000, 2048, 30000000),
			static fn() => new Region(0, 0, 0, 0, 0, 0, "unknown"),
			static fn() => (new Region(0, 0, 0, 0, 0, 0))->shift(PHP_INT_MAX, 0, 0),
			static fn() => (new Region(0, 0, 0, 0, 0, 0))->expand(PHP_INT_MAX, "east"),
			static fn() => (new Region(0, 0, 0, 0, 0, 0))->contract(1, "east"),
			static fn() => (new Region(0, 0, 0, 0, 0, 0))->expand(-1, "east"),
			static fn() => (new Region(0, 0, 0, 0, 0, 0))->expand(1, "invalid"),
			static fn() => Region::between([0, 0], [0, 0, 0]),
			static fn() => Region::between([0, 0, "0"], [0, 0, 0])
		];
		foreach($invalid as $operation){
			try{
				$operation();
				self::fail("Invalid geometry was accepted");
			}catch(InvalidArgumentException){
				self::assertTrue(true);
			}
		}
	}
}
