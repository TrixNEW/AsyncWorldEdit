<?php

declare(strict_types=1);

namespace asyncworldedit\tests;

use asyncworldedit\pattern\Mask;
use asyncworldedit\pattern\Pattern;
use Closure;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PatternTest extends TestCase{

	private static function resolver() : Closure{
		return static fn(string $name) : int => match($name){
			"air" => 0, "stone" => 1, "dirt" => 2,
			'minecraft:oak_log["pillar_axis"="y","stripped_bit"=false]' => 3,
			default => throw new InvalidArgumentException("Unknown block: " . $name)
		};
	}

	private static function solid() : Closure{
		return static fn(int $state) : bool => $state !== 0;
	}

	public function testSingleBlocksPropertiesAndExistingAreCompiledOnce() : void{
		$calls = [];
		$pattern = Pattern::parse('minecraft:oak_log["pillar_axis"="y","stripped_bit"=false]', static function(string $name) use (&$calls) : int{
			$calls[] = $name;
			return 3;
		});
		self::assertSame(3, $pattern->choose(0, 0, 0, 8));
		self::assertSame(3, $pattern->choose(10, 20, 30, 8));
		self::assertSame(['minecraft:oak_log["pillar_axis"="y","stripped_bit"=false]'], $calls);
		self::assertSame(8, Pattern::parse("#existing", self::resolver())->choose(0, 0, 0, 8));
		self::assertSame(0, Pattern::parse("air", self::resolver())->choose(0, 0, 0, 8));
	}

	public function testWeightedPatternsAreDeterministicAcrossTraversalOrder() : void{
		$pattern = Pattern::parse("20%stone,80%dirt", self::resolver());
		$results = [];
		$changedSeed = false;
		for($x = -5000; $x < 5000; ++$x){
			$results[$x] = $pattern->choose($x, -64, -1000, 9, PHP_INT_MAX);
			$changedSeed = $changedSeed || $results[$x] !== $pattern->choose($x, -64, -1000, 9, PHP_INT_MIN);
		}
		for($x = 4999; $x >= -5000; --$x){
			self::assertSame($results[$x], $pattern->choose($x, -64, -1000, 9, PHP_INT_MAX));
		}
		$counts = array_count_values($results);
		self::assertGreaterThan(1700, $counts[1]);
		self::assertLessThan(2300, $counts[1]);
		self::assertTrue($changedSeed);
		$existing = Pattern::parse("1%stone,1%#existing", self::resolver());
		$states = [];
		for($x = 0; $x < 100; ++$x){
			$states[$existing->choose($x, 0, 0, 7)] = true;
		}
		self::assertCount(2, $states);
		self::assertArrayHasKey(7, $states);
	}

	public function testMaskPrecedenceNegationAndProperties() : void{
		$mask = Mask::parse("stone,dirt&!#air", self::resolver(), self::solid());
		self::assertTrue($mask->matches(0, 0, 0, 1));
		self::assertTrue($mask->matches(0, 0, 0, 2));
		self::assertFalse($mask->matches(0, 0, 0, 0));
		$nested = Mask::parse("!(stone,dirt)&(#solid,!#air)", self::resolver(), self::solid());
		self::assertFalse($nested->matches(0, 0, 0, 1));
		self::assertFalse($nested->matches(0, 0, 0, 2));
		self::assertFalse($nested->matches(0, 0, 0, 0));
		self::assertTrue($nested->matches(0, 0, 0, 3));
		$property = Mask::parse('minecraft:oak_log["pillar_axis"="y","stripped_bit"=false],#air', self::resolver(), self::solid());
		self::assertTrue($property->matches(0, 0, 0, 3));
		self::assertTrue($property->matches(0, 0, 0, 0));
		self::assertFalse($property->matches(0, 0, 0, 1));
		self::assertTrue(Mask::parse("#air", self::resolver(), self::solid(), 9)->matches(0, 0, 0, 9));
	}

	public function testQuotedPropertySeparatorsRemainUntouched() : void{
		$block = 'custom:block["value"="a,b&!(])","escaped"="a\"b"]';
		$seen = [];
		$resolve = static function(string $name) use (&$seen) : int{
			$seen[] = $name;
			return 5;
		};
		self::assertSame(5, Pattern::parse($block, $resolve)->choose(0, 0, 0, 0));
		self::assertTrue(Mask::parse($block, $resolve, self::solid())->matches(0, 0, 0, 5));
		self::assertSame([$block, $block], $seen);
	}

	public function testInvalidPatternsAndMasksAreRejected() : void{
		$patterns = ["", "stone,", ",stone", "stone,,dirt", "0%stone", "-1%stone", "nan%stone", "10%", "#unknown", "stone[", "stone]", "stone[[x]]", str_repeat("s", 4097), implode(",", array_fill(0, 129, "stone"))];
		$masks = ["", "stone,", "stone&", "!", "()", "(stone", "stone)", "stone&&dirt", "stone dirt", "#unknown", "#existing", "stone[", "stone]", str_repeat("!", 33) . "stone", str_repeat("(", 33) . "stone" . str_repeat(")", 33), implode(",", array_fill(0, 129, "stone"))];
		foreach($patterns as $expression){
			try{
				Pattern::parse($expression, self::resolver());
				self::fail("Invalid pattern was accepted: " . $expression);
			}catch(InvalidArgumentException){
				self::assertTrue(true);
			}
		}
		foreach($masks as $expression){
			try{
				Mask::parse($expression, self::resolver(), self::solid());
				self::fail("Invalid mask was accepted: " . $expression);
			}catch(InvalidArgumentException){
				self::assertTrue(true);
			}
		}
	}

	public function testOversizedInputIsRejectedWithoutCopyingIt() : void{
		$resolve = self::resolver();
		Pattern::parse("stone", $resolve);
		$input = " " . str_repeat("s", 1048576) . " ";
		memory_reset_peak_usage();
		$before = memory_get_usage();
		try{
			Pattern::parse($input, $resolve);
			self::fail("Oversized expression was accepted");
		}catch(InvalidArgumentException){
			self::assertLessThan(65536, memory_get_peak_usage() - $before);
		}
	}
}
