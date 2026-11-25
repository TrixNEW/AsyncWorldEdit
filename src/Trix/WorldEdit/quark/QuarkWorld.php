<?php

declare(strict_types=1);

namespace Trix\WorldEdit\quark;

use Trix\WorldEdit\engine\BlockValue;
use Trix\WorldEdit\engine\WorldAccess;
use Trix\WorldEdit\schematic\NbtCodec;
use Trix\WorldEdit\schematic\NbtGuard;
use pocketmine\nbt\TreeRoot;
use pocketmine\nbt\tag\CompoundTag;
use quark\block\Block;
use quark\block\Piston;
use quark\block\PistonArmCollision;
use quark\block\RuntimeBlockStateRegistry;
use quark\block\tile\ItemFrame;
use quark\block\tile\Jukebox;
use quark\block\tile\Lectern;
use quark\block\tile\Spawnable;
use quark\block\tile\Tile;
use quark\block\tile\TileFactory;
use quark\inventory\InventoryHolder;
use quark\world\ChunkLoader;
use quark\world\format\Chunk;
use quark\world\World;

/**
 * Edits go through World::setBlockAt(), so lighting, tiles, neighbour updates, persistence and clients stay in sync.
 * The pinned chunk is checked when acquired; the editor releases it at the end of every tick.
 */
final class QuarkWorld implements WorldAccess{
	private const STATE_CACHE = 1024;
	private const MAX_TILE_BYTES = 65536;
	private const ITEM_BEARING = 'Item-bearing and inventory block entities are not supported by async edits because copying them duplicates stored contents';
	/** Saved data carries a data version under either name: early Quark 6 builds wrote QUARKDataVersion. */
	private const IGNORED_TILE_KEYS = [Tile::TAG_ID, Tile::TAG_X, Tile::TAG_Y, Tile::TAG_Z, 'PMMPDataVersion', 'QUARKDataVersion'];

	private ?ChunkLoader $loader = null;
	private ?Chunk $chunk = null;
	private int $chunkX = 0;
	private int $chunkZ = 0;
	private readonly string $id;
	private readonly int $minY;
	private readonly int $maxY;
	/** @var array<int, array{Block, class-string<Tile>|null}|string> block prototype and tile class, or why the state is unsupported */
	private array $states = [];

	public function __construct(private readonly World $world){
		$this->id = self::worldId($world);
		$this->minY = $world->getMinY();
		$this->maxY = $world->getMaxY();
	}

	public static function worldId(World $world) : string{
		return $world->getFolderName() . '#' . $world->getId();
	}

	public function id() : string{
		return $this->id;
	}

	public function isValid() : bool{
		return $this->world->isLoaded();
	}

	public function minY() : int{
		return $this->minY;
	}

	public function maxY() : int{
		return $this->maxY;
	}

	public function acquire(int $chunkX, int $chunkZ) : void{
		if($this->chunk !== null && $this->chunkX === $chunkX && $this->chunkZ === $chunkZ){
			return;
		}
		$this->release();
		if(!$this->world->isLoaded()){
			throw new \RuntimeException('World is no longer loaded');
		}
		$chunk = $this->world->getChunk($chunkX, $chunkZ) ?? throw new \RuntimeException("Chunk $chunkX,$chunkZ is not loaded; edits do not load or generate chunks");
		if(!$chunk->isPopulated()){
			throw new \RuntimeException("Chunk $chunkX,$chunkZ is not populated; wait for generation before editing it");
		}
		$this->loader ??= new ChunkLoader();
		$this->world->registerChunkLoader($this->loader, $chunkX, $chunkZ, false);
		$this->chunk = $chunk;
		$this->chunkX = $chunkX;
		$this->chunkZ = $chunkZ;
	}

	public function release() : void{
		if($this->chunk !== null && $this->loader !== null && $this->world->isLoaded()){
			$this->world->unregisterChunkLoader($this->loader, $this->chunkX, $this->chunkZ);
		}
		$this->chunk = null;
	}

	public function stateAt(int $x, int $y, int $z) : int{
		return $this->chunk($x, $y, $z)->getBlockStateId($x & 15, $y, $z & 15);
	}

	public function read(int $x, int $y, int $z) : BlockValue{
		$chunk = $this->chunk($x, $y, $z);
		$state = $this->primaryState($chunk, $x, $y, $z);
		$tileClass = $this->info($state)[1];
		$tile = $chunk->getTile($x & 15, $y, $z & 15);
		if($tile === null){
			return BlockValue::of($state);
		}
		self::assertSupportedTile($tile::class);
		if($tile::class !== $tileClass){
			throw new \RuntimeException("Block entity does not match block state at x=$x,y=$y,z=$z");
		}
		return new BlockValue($state, self::encodeTile($tile->saveNBT()));
	}

	public function write(int $x, int $y, int $z, BlockValue $expected, BlockValue $value) : ?BlockValue{
		$chunk = $this->chunk($x, $y, $z);
		$current = $this->primaryState($chunk, $x, $y, $z);
		if($current !== $expected->state){
			return null;
		}
		$oldTile = $chunk->getTile($x & 15, $y, $z & 15);
		if(($oldTile !== null || $expected->nbt !== null || $this->info($current)[1] !== null) && !$this->read($x, $y, $z)->equals($expected)){
			return null;
		}
		[$block, $tileClass] = $this->info($value->state);
		$tag = null;
		if($value->nbt !== null){
			$tag = $this->prepareTile($value->state, $tileClass, $value->nbt);
		}elseif($tileClass !== null && $oldTile !== null){
			throw new \InvalidArgumentException('Tile data is required when replacing an existing tile block');
		}

		$this->world->setBlockAt($x, $y, $z, $block);
		if($tileClass === null){
			return $value;
		}
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
		return $this->read($x, $y, $z);
	}

	private function chunk(int $x, int $y, int $z) : Chunk{
		if($this->chunk === null || ($x >> 4) !== $this->chunkX || ($z >> 4) !== $this->chunkZ){
			throw new \LogicException('Acquire the target chunk before reading or writing it');
		}
		if($y < $this->minY || $y >= $this->maxY){
			throw new \OutOfBoundsException("Y coordinate $y is outside the world bounds");
		}
		return $this->chunk;
	}

	private function primaryState(Chunk $chunk, int $x, int $y, int $z) : int{
		$subChunk = $chunk->getSubChunk($y >> 4);
		$layer1 = $subChunk->getBlockLayer1();
		if($layer1 !== null && $layer1->get($x & 15, $y & 15, $z & 15) !== $subChunk->getEmptyBlockId()){
			throw new \RuntimeException("Cannot edit secondary-layer blocks at x=$x,y=$y,z=$z");
		}
		return $subChunk->getBlockStateId($x & 15, $y & 15, $z & 15);
	}

	/** @return array{Block, class-string<Tile>|null} */
	private function info(int $state) : array{
		$info = $this->states[$state] ?? $this->describe($state);
		return is_string($info) ? throw new \InvalidArgumentException($info) : $info;
	}

	/** @return array{Block, class-string<Tile>|null}|string */
	private function describe(int $state) : array|string{
		$registry = RuntimeBlockStateRegistry::getInstance();
		if(!$registry->hasStateId($state)){
			throw new \InvalidArgumentException("Unknown Quark block state ID $state");
		}
		$block = $registry->fromStateId($state);
		$tileClass = $block->getIdInfo()->getTileClass();
		$info = match(true){
			$block instanceof Piston || $block instanceof PistonArmCollision => 'Pistons are not supported by async edits because they can move blocks outside the edit history',
			self::isItemBearing($tileClass) => self::ITEM_BEARING,
			default => [$block, $tileClass]
		};
		if(count($this->states) < self::STATE_CACHE){
			$this->states[$state] = $info;
		}
		return $info;
	}

	/** @param class-string<Tile>|null $tileClass */
	private function prepareTile(int $state, ?string $tileClass, string $nbt) : CompoundTag{
		if($tileClass === null){
			throw new \InvalidArgumentException("Block state $state does not accept tile data");
		}
		$tag = self::decodeTile($nbt);
		$tile = TileFactory::getInstance()->createFromData($tag);
		if($tile === null || $tile::class !== $tileClass){
			throw new \InvalidArgumentException("Tile data does not match block state $state");
		}
		$roundtrip = $tile->saveNBT();
		foreach($tag as $key => $value){
			if(!in_array($key, self::IGNORED_TILE_KEYS, true) && $roundtrip->getTag($key)?->equals($value) !== true){
				throw new \InvalidArgumentException("Unsupported or changed block entity data: $key");
			}
		}
		return $tag;
	}

	private static function isItemBearing(?string $tileClass) : bool{
		return $tileClass !== null && (is_a($tileClass, InventoryHolder::class, true) || is_a($tileClass, ItemFrame::class, true) || is_a($tileClass, Jukebox::class, true) || is_a($tileClass, Lectern::class, true));
	}

	private static function assertSupportedTile(string $tileClass) : void{
		if(self::isItemBearing($tileClass)){
			throw new \InvalidArgumentException(self::ITEM_BEARING);
		}
	}

	private static function encodeTile(CompoundTag $tag) : string{
		$tag = clone $tag;
		$tag->removeTag(Tile::TAG_X, Tile::TAG_Y, Tile::TAG_Z);
		$data = NbtCodec::write(new TreeRoot($tag), true);
		if(strlen($data) > self::MAX_TILE_BYTES){
			throw new \InvalidArgumentException('Block entity data exceeds the 64 KiB limit');
		}
		return $data;
	}

	private static function decodeTile(string $data) : CompoundTag{
		if($data === '' || strlen($data) > self::MAX_TILE_BYTES){
			throw new \InvalidArgumentException('Invalid block entity data size');
		}
		(new NbtGuard($data, true, 8192))->validate();
		$tag = NbtCodec::read($data, true)->mustGetCompoundTag();
		$tag->removeTag(Tile::TAG_X, Tile::TAG_Y, Tile::TAG_Z);
		return $tag;
	}
}
