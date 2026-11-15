<?php

declare(strict_types=1);

namespace asyncworldedit\quark;

use asyncworldedit\engine\BlockValue;
use asyncworldedit\engine\WorldAccess;
use asyncworldedit\schematic\NbtGuard;
use pocketmine\nbt\LittleEndianNbtSerializer;
use pocketmine\nbt\TreeRoot;
use pocketmine\nbt\tag\CompoundTag;
use quark\block\Block;
use quark\block\Piston;
use quark\block\PistonArmCollision;
use quark\block\RuntimeBlockStateRegistry;
use quark\block\tile\ItemFrame as ItemFrameTile;
use quark\block\tile\Jukebox as JukeboxTile;
use quark\block\tile\Lectern as LecternTile;
use quark\block\tile\Spawnable;
use quark\block\tile\Tile;
use quark\block\tile\TileFactory;
use quark\inventory\InventoryHolder;
use quark\VersionInfo;
use quark\world\ChunkLoader;
use quark\world\World;

final class QuarkWorld implements WorldAccess{
	private const MAX_STATE_CACHE = 256;

	private ?ChunkLoader $loader = null;
	private ?\quark\world\format\Chunk $chunk = null;
	private int $chunkX = 0;
	private int $chunkZ = 0;
	private readonly string $worldId;
	/** @var array<int, class-string<Tile>|null> */
	private array $tileClasses = [];
	/** @var array<int, Block> */
	private array $blockPrototypes = [];
	/** @var array<int, 'piston'|'tile'> */
	private array $unsupportedStates = [];

	public function __construct(private readonly World $world){
		$this->worldId = $world->getFolderName() . "#" . $world->getId();
	}

	public function id() : string{
		return $this->worldId;
	}

	public function isValid() : bool{
		return $this->world->isLoaded();
	}

	public function minY() : int{
		return $this->world->getMinY();
	}

	public function maxY() : int{
		return $this->world->getMaxY();
	}

	public function acquire(int $chunkX, int $chunkZ) : void{
		$this->assertValid();
		if($this->loader !== null){
			if($this->chunkX === $chunkX && $this->chunkZ === $chunkZ){
				return;
			}
			$this->release();
		}

		$chunk = $this->world->getChunk($chunkX, $chunkZ);
		if($chunk === null){
			throw new \RuntimeException("Chunk $chunkX,$chunkZ is not loaded; async edits do not load or generate chunks");
		}
		if(!$chunk->isPopulated()){
			throw new \RuntimeException("Chunk $chunkX,$chunkZ is not populated; wait for generation before editing it");
		}

		$loader = new ChunkLoader();
		$this->world->registerChunkLoader($loader, $chunkX, $chunkZ, false);
		$this->loader = $loader;
		$this->chunk = $chunk;
		$this->chunkX = $chunkX;
		$this->chunkZ = $chunkZ;
	}

	public function release() : void{
		if($this->loader !== null && $this->world->isLoaded()){
			$this->world->unregisterChunkLoader($this->loader, $this->chunkX, $this->chunkZ);
		}
		$this->loader = null;
		$this->chunk = null;
	}

	public function read(int $x, int $y, int $z) : BlockValue{
		$chunk = $this->getAcquiredChunk($x, $z);
		if($y < $this->minY() || $y >= $this->maxY()){
			throw new \OutOfBoundsException("Y coordinate $y is outside the world bounds");
		}

		$localX = $x & 15;
		$localZ = $z & 15;
		$subChunk = $chunk->getSubChunk($y >> 4);
		$layer1 = $subChunk->getBlockLayer1();
		if($layer1 !== null && $layer1->get($localX, $y & 15, $localZ) !== $subChunk->getEmptyBlockId()){
			throw new \RuntimeException("Cannot edit secondary-layer blocks at x=$x,y=$y,z=$z");
		}

		$state = $chunk->getBlockStateId($localX, $y, $localZ);
		$tileClass = $this->tileClass($state);
		$tile = $chunk->getTile($localX, $y, $localZ);
		if($tile === null){
			return new BlockValue($state);
		}
		$this->assertSupportedTile($tile);
		if($tileClass === null || $tile::class !== $tileClass){
			throw new \RuntimeException("Block entity does not match block state at x=$x,y=$y,z=$z");
		}
		return new BlockValue($state, self::encodeTile($tile->saveNBT()));
	}

	public function write(int $x, int $y, int $z, BlockValue $value) : void{
		if($y < $this->minY() || $y >= $this->maxY()){
			throw new \OutOfBoundsException("Y coordinate $y is outside the world bounds");
		}
		$block = $this->blockPrototypes[$value->state] ?? null;
		if($block === null){
			if(!RuntimeBlockStateRegistry::getInstance()->hasStateId($value->state)){
				throw new \InvalidArgumentException("Unknown Quark block state ID {$value->state}");
			}
			$block = RuntimeBlockStateRegistry::getInstance()->fromStateId($value->state);
			if(count($this->blockPrototypes) < self::MAX_STATE_CACHE){
				$this->blockPrototypes[$value->state] = $block;
			}
		}
		$this->assertSupportedState($value->state, $block);

		$chunk = $this->getAcquiredChunk($x, $z);
		$this->tileClass($chunk->getBlockStateId($x & 15, $y, $z & 15));

		$tileClass = $block->getIdInfo()->getTileClass();
		$tag = $value->nbt === null ? null : self::decodeTile($value->nbt);
		$preparedTile = null;
		if($tileClass === null && $tag !== null){
			throw new \InvalidArgumentException("Block state {$value->state} does not accept tile data");
		}
		if($tag !== null){
			$preparedTile = TileFactory::getInstance()->createFromData($tag);
			if($preparedTile === null || $preparedTile::class !== $tileClass){
				throw new \InvalidArgumentException("Tile data does not match block state {$value->state}");
			}
			$this->assertSupportedTile($preparedTile);
			$roundtrip = $preparedTile->saveNBT();
			foreach($tag as $key => $tagValue){
				if(in_array($key, [Tile::TAG_ID, Tile::TAG_X, Tile::TAG_Y, Tile::TAG_Z, VersionInfo::TAG_WORLD_DATA_VERSION], true)){
					continue;
				}
				if(($roundtrip->getTag($key))?->equals($tagValue) !== true){
					throw new \InvalidArgumentException("Unsupported or changed block entity data: $key");
				}
			}
		}

		$oldTile = $chunk->getTile($x & 15, $y, $z & 15);
		if($oldTile !== null){
			$this->assertSupportedTile($oldTile);
		}
		if($tag === null && $tileClass !== null && $oldTile !== null){
			throw new \InvalidArgumentException("Tile data is required when replacing an existing tile block");
		}

		$subChunk = $chunk->getSubChunk($y >> 4);
		$layer1 = $subChunk->getBlockLayer1();
		if($layer1 !== null && $layer1->get($x & 15, $y & 15, $z & 15) !== $subChunk->getEmptyBlockId()){
			throw new \RuntimeException("Cannot edit secondary-layer blocks at x=$x,y=$y,z=$z");
		}

		$this->world->setBlockAt($x, $y, $z, $block);
		if($tag !== null){
			$tile = $chunk->getTile($x & 15, $y, $z & 15);
			if($tile === null || $tile::class !== $tileClass){
				throw new \RuntimeException("Quark failed to create the expected tile at x=$x,y=$y,z=$z");
			}
			$tile->readSaveData($tag);
			if($tile instanceof Spawnable){
				$tile->clearSpawnCompoundCache();
			}
			// Chunk listeners can cache the temporary tile state during setBlockAt().
			$this->world->getBlockAt($x, $y, $z, false);
		}
	}

	private function assertValid() : void{
		if(!$this->isValid()){
			throw new \RuntimeException("World is no longer loaded");
		}
	}

	private function getAcquiredChunk(int $x, int $z) : \quark\world\format\Chunk{
		$this->assertValid();
		$chunk = $this->chunk;
		if($this->loader === null || $chunk === null || ($x >> 4) !== $this->chunkX || ($z >> 4) !== $this->chunkZ){
			throw new \LogicException("Acquire the target loaded chunk before reading or writing it");
		}
		if(!$this->world->isChunkLoaded($this->chunkX, $this->chunkZ) || $this->world->getChunk($this->chunkX, $this->chunkZ) !== $chunk){
			$this->release();
			throw new \RuntimeException("Acquired chunk was unloaded");
		}
		return $chunk;
	}

	private function assertSupportedTile(Tile $tile) : void{
		$this->assertSupportedTileClass($tile::class);
	}

	/** @return class-string<Tile>|null */
	private function tileClass(int $state) : ?string{
		if(!array_key_exists($state, $this->tileClasses)){
			if(!RuntimeBlockStateRegistry::getInstance()->hasStateId($state)){
				throw new \RuntimeException("Chunk contains an unknown block state ID $state");
			}
			$block = RuntimeBlockStateRegistry::getInstance()->fromStateId($state);
			$this->assertSupportedState($state, $block);
			$class = $block->getIdInfo()->getTileClass();
			if(count($this->tileClasses) < self::MAX_STATE_CACHE){
				$this->tileClasses[$state] = $class;
			}
			return $class;
		}
		return $this->tileClasses[$state];
	}

	private function assertSupportedState(int $state, Block $block) : void{
		if(isset($this->unsupportedStates[$state])){
			throw self::unsupportedStateException($this->unsupportedStates[$state]);
		}
		if($block instanceof Piston || $block instanceof PistonArmCollision){
			if(count($this->unsupportedStates) < self::MAX_STATE_CACHE){
				$this->unsupportedStates[$state] = 'piston';
			}
			throw self::unsupportedStateException('piston');
		}
		try{
			$this->assertSupportedTileClass($block->getIdInfo()->getTileClass());
		}catch(\InvalidArgumentException $e){
			if(count($this->unsupportedStates) < self::MAX_STATE_CACHE){
				$this->unsupportedStates[$state] = 'tile';
			}
			throw $e;
		}
	}

	private static function unsupportedStateException(string $kind) : \InvalidArgumentException{
		$message = $kind === 'piston'
			? 'Pistons and piston arms are not supported by async edits because they can move blocks outside the edit history'
			: 'Item-bearing and inventory block entities are not supported by async edits because copying them duplicates stored contents';
		return new \InvalidArgumentException($message);
	}

	private function assertSupportedTileClass(?string $tileClass) : void{
		if($tileClass !== null && (
			is_a($tileClass, InventoryHolder::class, true) ||
			is_a($tileClass, ItemFrameTile::class, true) ||
			is_a($tileClass, JukeboxTile::class, true) ||
			is_a($tileClass, LecternTile::class, true)
		)){
			throw new \InvalidArgumentException("Item-bearing and inventory block entities are not supported by async edits because copying them duplicates stored contents");
		}
	}

	private static function encodeTile(CompoundTag $tag) : string{
		$tag = clone $tag;
		$tag->removeTag(Tile::TAG_X);
		$tag->removeTag(Tile::TAG_Y);
		$tag->removeTag(Tile::TAG_Z);
		$data = (new LittleEndianNbtSerializer())->write(new TreeRoot($tag));
		if(strlen($data) > 65536){
			throw new \InvalidArgumentException("Block entity data exceeds the 64 KiB limit");
		}
		return $data;
	}

	private static function decodeTile(string $data) : CompoundTag{
		if($data === "" || strlen($data) > 65536){
			throw new \InvalidArgumentException("Invalid block entity data size");
		}
		(new NbtGuard($data, true, 8192))->validate();
		$tag = (new LittleEndianNbtSerializer())->read($data)->mustGetCompoundTag();
		$tag->removeTag(Tile::TAG_X);
		$tag->removeTag(Tile::TAG_Y);
		$tag->removeTag(Tile::TAG_Z);
		return $tag;
	}
}
