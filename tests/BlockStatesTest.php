<?php

declare(strict_types=1);

namespace Trix\WorldEdit\tests;

use Trix\WorldEdit\quark\BlockStates;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use quark\block\RuntimeBlockStateRegistry;
use quark\block\Slab;
use quark\block\Stair;
use quark\block\utils\PillarRotation;
use quark\block\utils\SlabType;
use quark\block\utils\StairShape;
use quark\item\StringToItemParser;
use quark\math\Axis;
use quark\math\Facing;

final class BlockStatesTest extends TestCase{
	public static function setUpBeforeClass() : void{
		$quarkRoot = getenv('QUARK_ROOT');
		if($quarkRoot === false || !is_file($quarkRoot . '/vendor/autoload.php')){
			self::markTestSkipped('Set QUARK_ROOT to a Quark checkout to run adapter tests');
		}
		$loader = new \Composer\Autoload\ClassLoader();
		foreach(require $quarkRoot . '/vendor/composer/autoload_psr4.php' as $prefix => $paths){
			if(!str_starts_with($prefix, 'PHPUnit\\') && !str_starts_with($prefix, 'PHPStan\\')){
				$loader->addPsr4($prefix, array_map(static fn(string $path) : string => str_replace('\\', '/', $path), $paths));
			}
		}
		$loader->register();
		require_once $quarkRoot . '/src/CoreConstants.php';
	}

	public function testEveryRegisteredStateRoundtripsThroughBedrockName() : void{
		$states = new BlockStates();
		foreach(RuntimeBlockStateRegistry::getInstance()->getAllKnownStates() as $stateId => $_){
			$name = $states->name($stateId);
			self::assertSame($name, $states->name($states->resolve($name)), $name);
		}
	}

	public function testNativeFacingAndShapeTransformations() : void{
		$states = new BlockStates();
		$stairsItem = StringToItemParser::getInstance()->parse('oak_stairs');
		self::assertNotNull($stairsItem);
		$stairs = $stairsItem->getBlock();
		self::assertInstanceOf(Stair::class, $stairs);
		$stairs->setFacing(Facing::NORTH)->setShape(StairShape::OUTER_LEFT);
		$turned = RuntimeBlockStateRegistry::getInstance()->fromStateId($states->transform($stairs->getStateId(), 1, false, false, false));
		self::assertInstanceOf(Stair::class, $turned);
		self::assertSame(Facing::EAST, $turned->getFacing());
		$mirrored = RuntimeBlockStateRegistry::getInstance()->fromStateId($states->transform($stairs->getStateId(), 0, true, false, false));
		self::assertInstanceOf(Stair::class, $mirrored);
		self::assertSame(StairShape::OUTER_RIGHT, $mirrored->getShape());
		self::assertTrue($mirrored->isUpsideDown() === $stairs->isUpsideDown());
	}

	public function testPillarAndSlabAxisFlips() : void{
		$states = new BlockStates();
		$logItem = StringToItemParser::getInstance()->parse('oak_log');
		self::assertNotNull($logItem);
		$log = $logItem->getBlock();
		self::assertInstanceOf(PillarRotation::class, $log);
		$log->setAxis(Axis::X);
		$rotatedLog = RuntimeBlockStateRegistry::getInstance()->fromStateId($states->transform($log->getStateId(), 1, false, false, false));
		self::assertInstanceOf(PillarRotation::class, $rotatedLog);
		self::assertSame(Axis::Z, $rotatedLog->getAxis());

		$slab = null;
		foreach(RuntimeBlockStateRegistry::getInstance()->getAllKnownStates() as $stateId => $block){
			if($block instanceof Slab && $block->getSlabType() === SlabType::BOTTOM){
				$slab = $block;
				break;
			}
		}
		self::assertInstanceOf(Slab::class, $slab);
		$flippedSlab = RuntimeBlockStateRegistry::getInstance()->fromStateId($states->transform($slab->getStateId(), 0, false, true, false));
		self::assertInstanceOf(Slab::class, $flippedSlab);
		self::assertSame(SlabType::TOP, $flippedSlab->getSlabType());
	}

	public function testMalformedQuotedPropertiesAreRejectedAsUserInput() : void{
		$states = new BlockStates();
		foreach(['minecraft:stone["bad\\q"=0]', 'minecraft:stone["bad"="unfinished]', 'minecraft:stone[unknown_property=1]', 'minecraft:oak_stairs[facing_direction="north"]'] as $expression){
			try{
				$states->resolve($expression);
				self::fail("Malformed property was accepted: $expression");
			}catch(InvalidArgumentException){
				self::assertTrue(true);
			}
		}
	}
}
