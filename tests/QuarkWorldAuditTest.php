<?php

declare(strict_types=1);

namespace Trix\WorldEdit\tests;

use Trix\WorldEdit\engine\BlockValue;
use Trix\WorldEdit\quark\QuarkWorld;
use PHPUnit\Framework\TestCase;
use quark\block\Block;
use quark\block\BaseSign;
use quark\block\Piston;
use quark\block\PistonArmCollision;
use quark\block\RuntimeBlockStateRegistry;
use quark\block\tile\ItemFrame as ItemFrameTile;
use quark\block\tile\Jukebox as JukeboxTile;
use quark\block\tile\Lectern as LecternTile;
use quark\world\ChunkLoader;
use quark\world\World;
use quark\world\format\Chunk;

final class QuarkWorldAuditTest extends TestCase{
	public static function setUpBeforeClass() : void{
		$quarkRoot = getenv('QUARK_ROOT');
		if($quarkRoot === false || !is_file($quarkRoot . '/vendor/autoload.php')){
			self::markTestSkipped('Set QUARK_ROOT to a Quark checkout to run adapter tests');
		}
		$loader = new \Composer\Autoload\ClassLoader();
		foreach(require $quarkRoot . '/vendor/composer/autoload_psr4.php' as $prefix => $paths){
			if(!str_starts_with($prefix, 'PHPUnit\\') && !str_starts_with($prefix, 'PHPStan\\')){
				$loader->addPsr4($prefix, array_values(array_map(static fn(string $path) : string => str_replace('\\', '/', $path), $paths)));
			}
		}
		$loader->register();
		require_once $quarkRoot . '/src/CoreConstants.php';
	}

	public function testReadRejectsPistonsAndItemBearingTilesBeforeReturningValues() : void{
		$adapter = self::adapter();
		foreach(self::states(static fn(Block $block) : bool => $block instanceof Piston || $block instanceof PistonArmCollision || self::isItemTile($block->getIdInfo()->getTileClass())) as $state){
			$chunk = new Chunk([], true);
			$chunk->setBlockStateId(0, 64, 0, $state);
			$world = self::world($chunk);
			$adapter = new QuarkWorld($world);
			$adapter->acquire(0, 0);
			try{
				try{
					$adapter->read(0, 64, 0);
					self::fail('Unsafe state was readable: ' . $state);
				}catch(\InvalidArgumentException $e){
					self::assertStringContainsString('not supported by async edits', $e->getMessage());
				}
				// Counting only needs the state, so it still works on blocks edits refuse.
				self::assertSame($state, $adapter->stateAt(0, 64, 0));
			}finally{
				$adapter->release();
			}
		}
	}

	public function testWriteRejectsPistonsAndItemBearingTilesBeforeChunkAccess() : void{
		$adapter = self::adapter();
		$adapter->acquire(0, 0);
		$air = BlockValue::of(\quark\block\VanillaBlocks::AIR()->getStateId());
		$unsafe = self::states(static fn(Block $block) : bool => $block instanceof Piston || $block instanceof PistonArmCollision || self::isItemTile($block->getIdInfo()->getTileClass()));
		self::assertNotEmpty($unsafe);
		foreach($unsafe as $state){
			try{
				$adapter->write(0, 64, 0, $air, new BlockValue($state));
				self::fail('Unsafe state was writable: ' . $state);
			}catch(\InvalidArgumentException $e){
				self::assertStringContainsString('not supported by async edits', $e->getMessage());
			}
		}
		$adapter->release();
	}

	public function testSafeSignTilesRemainEditable() : void{
		$adapter = self::adapter();
		$signState = self::firstState(static fn(Block $block) : bool => $block instanceof BaseSign);
		try{
			$adapter->write(0, 64, 0, BlockValue::of(\quark\block\VanillaBlocks::AIR()->getStateId()), new BlockValue($signState));
			self::fail('Expected a missing acquired chunk');
		}catch(\LogicException $e){
			self::assertStringContainsString('Acquire the target chunk', $e->getMessage());
		}
	}

	public function testDirectWritesCannotRemoveExistingPistonArms() : void{
		$arm = self::firstState(static fn(Block $block) : bool => $block instanceof PistonArmCollision);
		$chunk = new Chunk([],true); $chunk->setBlockStateId(0,64,0,$arm);
		$adapter = new QuarkWorld(self::world($chunk)); $adapter->acquire(0,0);
		try{
			try{ $adapter->write(0,64,0,new BlockValue($arm),new BlockValue(\quark\block\VanillaBlocks::AIR()->getStateId())); self::fail('Existing piston arm was removed'); }
			catch(\InvalidArgumentException){ self::assertSame($arm,$chunk->getBlockStateId(0,64,0)); }
		}finally{ $adapter->release(); }
	}

	/** @return list<int> */
	private static function states(callable $predicate) : array{
		$result = [];
		foreach(RuntimeBlockStateRegistry::getInstance()->getAllKnownStates() as $state => $block){
			if($predicate($block)){
				$result[] = $state;
			}
		}
		return array_values(array_unique($result));
	}

	private static function firstState(callable $predicate) : int{
		foreach(RuntimeBlockStateRegistry::getInstance()->getAllKnownStates() as $state => $block){
			if($predicate($block)){
				return $state;
			}
		}
		self::fail('No matching Quark block state was registered');
	}

	private static function isItemTile(?string $tileClass) : bool{
		return $tileClass !== null && (
			is_a($tileClass, ItemFrameTile::class, true) ||
			is_a($tileClass, JukeboxTile::class, true) ||
			is_a($tileClass, LecternTile::class, true)
		);
	}

	private static function adapter() : QuarkWorld{
		return new QuarkWorld(self::world(new Chunk([], true)));
	}

	private static function world(Chunk $chunk) : World{
		$world = new class($chunk) extends World{
			public function __construct(private Chunk $chunk){}
			public function getFolderName() : string{ return 'asyncworldedit-audit'; }
			public function isLoaded() : bool{ return true; }
			public function getMinY() : int{ return -64; }
			public function getMaxY() : int{ return 320; }
			public function getChunk(int $chunkX, int $chunkZ) : Chunk{ return $this->chunk; }
			public function isChunkLoaded(int $x, int $z) : bool{ return true; }
			public function registerChunkLoader(ChunkLoader $loader, int $chunkX, int $chunkZ, bool $autoLoad = true) : void{}
			public function unregisterChunkLoader(ChunkLoader $loader, int $chunkX, int $chunkZ) : void{}
		};
		(new \ReflectionProperty(World::class, 'worldId'))->setValue($world, 1001);
		return $world;
	}
}
