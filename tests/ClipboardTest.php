<?php

declare(strict_types=1);

namespace Trix\WorldEdit\tests;

use Trix\WorldEdit\clipboard\Clipboard;
use InvalidArgumentException;
use OutOfBoundsException;
use PHPUnit\Framework\TestCase;

final class ClipboardTest extends TestCase{
	public function testImmutableStorageSizeIncludesTilePayloadAndSurvivesCloning() : void{
		$clipboard = new Clipboard(2,1,1,pack('V*',1,2),[0=>'exact data']);
		self::assertSame(8 + 128 + 10,$clipboard->bytes());
		self::assertSame($clipboard->bytes(),(clone $clipboard)->bytes());
		$plain = new Clipboard(2,1,1,pack('V*',1,2));
		self::assertSame($plain->bytes(),$plain->rotate(90)->flip('x')->bytes());
	}

	private static function sample() : Clipboard{
		return new Clipboard(2, 2, 2, pack("V*", 0, 1, 2, 3, 4, 5, 6, 0xffffffff), [], [-10, 4, -20], [-12, 3, -18]);
	}

	public function testPackedYzxStatesAndTiles() : void{
		$clipboard = new Clipboard(2, 2, 2, pack("V*", 0, 1, 2, 3, 4, 5, 6, 0xffffffff), [5 => "nbt"]);
		self::assertSame(8, $clipboard->volume());
		self::assertSame(1, $clipboard->at(1, 0, 0)->state);
		self::assertSame(2, $clipboard->at(0, 0, 1)->state);
		self::assertSame(4, $clipboard->at(0, 1, 0)->state);
		self::assertSame(0xffffffff, $clipboard->at(1, 1, 1)->state);
		self::assertSame("nbt", $clipboard->at(1, 1, 0)->nbt);
		self::assertNull($clipboard->at(0, 0, 0)->nbt);
	}

	public function testRotationsAndFlipsPreservePivotOffsets() : void{
		$clipboard = self::sample();
		$original = iterator_to_array($clipboard->coordinates());
		self::assertSame([2, 1, -2], array_slice($original[0], 0, 3));
		$turned = iterator_to_array($clipboard->rotate(90)->coordinates());
		self::assertSame([2, 1, 2], array_slice($turned[0], 0, 3));
		self::assertSame([1, 2, 3], array_slice($turned[7], 0, 3));
		self::assertEquals($original, iterator_to_array($clipboard->rotate(90)->rotate(90)->rotate(90)->rotate(90)->coordinates()));
		self::assertEquals($original, iterator_to_array($clipboard->rotate(-90)->rotate(450)->coordinates()));
		foreach(["x", "y", "z"] as $axis){
			self::assertEquals($original, iterator_to_array($clipboard->flip($axis)->flip($axis)->coordinates()));
		}
		self::assertSame([-2, 1, 2], array_slice(iterator_to_array($clipboard->rotate(90)->flip("x")->coordinates())[0], 0, 3));
		self::assertSame([2, 1, -2], array_slice(iterator_to_array($clipboard->flip("x")->rotate(90)->coordinates())[0], 0, 3));
		self::assertEquals($original, iterator_to_array($clipboard->coordinates()));
		self::assertSame($clipboard->states, $clipboard->rotate(90)->states);
	}

	public function testTransformedBoundsMatchEveryPastedCoordinate() : void{
		$clipboard = self::sample()->rotate(90)->flip("y");
		$bounds = $clipboard->bounds([10, 20, 30]);
		self::assertSame([11, 18, 32, 12, 19, 33], [$bounds->minX, $bounds->minY, $bounds->minZ, $bounds->maxX, $bounds->maxY, $bounds->maxZ]);
		self::assertSame($clipboard->volume(), $bounds->volume());
		foreach($clipboard->coordinates() as [$x, $y, $z]){
			self::assertTrue($bounds->contains($x + 10, $y + 20, $z + 30));
		}
		$this->expectException(InvalidArgumentException::class);
		$clipboard->bounds([30000000, 20, 30]);
	}

	public function testRelativeBoundsPreserveOffsetsBeyondWorldLimits() : void{
		$clipboard = new Clipboard(1, 1, 1, pack("V", 1), [], [-30000000, 0, 0], [30000000, 0, 0]);
		self::assertSame(["min" => [-60000000, 0, 0], "max" => [-60000000, 0, 0]], $clipboard->relativeBounds());
		$worldBounds = $clipboard->bounds($clipboard->origin);
		self::assertSame(-30000000, $worldBounds->minX);
		self::assertSame(-30000000, $worldBounds->maxX);
		self::assertSame(1, $worldBounds->volume());
	}

	public function testRelativeExtentsMatchRectangularRotationAndFlipCompositions() : void{
		$clipboard = new Clipboard(2, 3, 4, str_repeat(pack("V", 1), 24), [], [-30000000, -3, -20], [30000000, 10, 5]);
		foreach([0, 90, 180, 270] as $rotation){
			foreach(["x", "y", "z"] as $axis){
				$transformed = $clipboard->flip($axis)->rotate($rotation)->flip("x");
				$bounds = $transformed->relativeBounds();
				$positions = iterator_to_array($transformed->coordinates());
				$dimensions = [];
				for($coordinate = 0; $coordinate < 3; ++$coordinate){
					$values = array_column($positions, $coordinate);
					self::assertSame(min($values), $bounds["min"][$coordinate]);
					self::assertSame(max($values), $bounds["max"][$coordinate]);
					$dimensions[] = $bounds["max"][$coordinate] - $bounds["min"][$coordinate] + 1;
				}
				self::assertSame($rotation % 180 === 0 ? [2, 3, 4] : [4, 3, 2], $dimensions);
				self::assertSame(24, array_product($dimensions));
			}
		}
	}

	public function testStateTransformIsNormalizedAndCachedPerState() : void{
		$clipboard = new Clipboard(2, 1, 2, pack("V*", 7, 7, 7, 8));
		foreach([0, 90, 180, 270] as $rotation){
			foreach([null, "x", "y", "z"] as $axis){
				$transformed = $clipboard->rotate($rotation);
				if($axis !== null){
					$transformed = $transformed->flip($axis);
				}
				$calls = [];
				$positions = iterator_to_array($transformed->coordinates(static function(int $state, int $turns, bool $flipX, bool $flipY, bool $flipZ) use (&$calls) : int{
					$calls[] = [$state, $turns, $flipX, $flipY, $flipZ];
					return $state + 10;
				}));
				if($rotation === 0 && $axis === null){
					self::assertSame([], $calls);
					continue;
				}
				self::assertCount(2, $calls);
				self::assertSame(17, $positions[0][3]->state);
				[, $turns, $flipX, $flipY, $flipZ] = $calls[0];
				self::assertFalse($flipZ);
				foreach($positions as $index => [$x, $y, $z]){
					$expectedX = ($index % 2) * ($flipX ? -1 : 1);
					$expectedZ = intdiv($index, 2);
					for($turn = 0; $turn < $turns; ++$turn){
						[$expectedX, $expectedZ] = [-$expectedZ, $expectedX];
					}
					self::assertSame([$expectedX, 0, $expectedZ], [$x, $y, $z]);
					self::assertSame($axis === "y", $flipY);
				}
			}
		}
	}

	public function testTransformsSharePackedStorage() : void{
		$clipboard = new Clipboard(128, 64, 32, str_repeat(pack("V", 1), 128 * 64 * 32));
		$before = memory_get_usage();
		$copies = [];
		for($i = 0; $i < 32; ++$i){
			$copies[] = $clipboard->rotate(90)->flip("x");
		}
		self::assertLessThan(strlen($clipboard->states), memory_get_usage() - $before);
		self::assertCount(32, $copies);
	}

	public function testDirectionalTileTransformsAreRejected() : void{
		$clipboard = new Clipboard(1, 1, 1, pack("V", 1), [0 => "nbt"]);
		self::assertSame("nbt", iterator_to_array($clipboard->rotate(360)->coordinates())[0][3]->nbt);
		foreach([static fn() => $clipboard->rotate(90), static fn() => $clipboard->flip("x")] as $operation){
			try{
				$operation();
				self::fail("Unsafe tile transform was accepted");
			}catch(InvalidArgumentException){
				self::assertTrue(true);
			}
		}
	}

	public function testInvalidDimensionsCoordinatesAndTransformsAreRejected() : void{
		foreach([
			static fn() => new Clipboard(0, 1, 1, ""),
			static fn() => new Clipboard(PHP_INT_MAX, PHP_INT_MAX, PHP_INT_MAX, ""),
			static fn() => new Clipboard(2, 1, 1, pack("V", 0)),
			static fn() => new Clipboard(2, 1, 1, pack("V*", 0, 0), maxBlocks: 1),
			static fn() => new Clipboard(1, 1, 1, pack("V", 0), [1 => "nbt"]),
			static fn() => new Clipboard(1, 1, 1, pack("V", 0), [0 => str_repeat("n", 65537)]),
			static fn() => new Clipboard(1, 1, 1, pack("V", 0), min: [0, 0]),
			static fn() => new Clipboard(1, 1, 1, pack("V", 0), origin: [PHP_INT_MIN, 0, 0]),
			static fn() => self::sample()->rotate(45),
			static fn() => self::sample()->flip("unknown")
		] as $operation){
			try{
				$operation();
				self::fail("Invalid clipboard input was accepted");
			}catch(InvalidArgumentException){
				self::assertTrue(true);
			}
		}
		$this->expectException(OutOfBoundsException::class);
		self::sample()->at(-1, 0, 0);
	}
}
