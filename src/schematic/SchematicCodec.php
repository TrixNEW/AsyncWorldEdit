<?php

declare(strict_types=1);

namespace asyncworldedit\schematic;

use InvalidArgumentException;
use pocketmine\nbt\BigEndianNbtSerializer;
use pocketmine\nbt\LittleEndianNbtSerializer;
use pocketmine\nbt\NBT;
use pocketmine\nbt\TreeRoot;
use pocketmine\nbt\tag\ByteTag;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\IntTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\nbt\tag\StringTag;
use Throwable;
use UnexpectedValueException;

final class SchematicCodec{

	public function __construct(
		private int $maxFileBytes = 16777216,
		private int $maxDecodedBytes = 67108864,
		private int $maxBlocks = 1000000,
		private int $maxNbtNodes = 262144,
		private int $maxPalette = 4096
	){
		if(min($maxFileBytes, $maxDecodedBytes, $maxBlocks, $maxNbtNodes, $maxPalette) < 1){
			throw new InvalidArgumentException("Schematic limits must be positive");
		}
	}

	public function decode(string $bytes, string $format = "schem") : SchematicData{
		$little = $this->isBedrock($format);
		if(strlen($bytes) > $this->maxFileBytes){
			throw new UnexpectedValueException("Schematic file exceeds size limit");
		}
		if(str_starts_with($bytes, "\x1f\x8b")){
			$bytes = @gzdecode($bytes, $this->maxDecodedBytes);
			if($bytes === false){
				throw new UnexpectedValueException("Invalid or excessive compressed schematic");
			}
		}
		if(strlen($bytes) > $this->maxDecodedBytes){
			throw new UnexpectedValueException("Decoded schematic exceeds size limit");
		}
		(new NbtGuard($bytes, $little, $this->maxNbtNodes))->validate();
		try{
			$serializer = $little ? new LittleEndianNbtSerializer() : new BigEndianNbtSerializer();
			$offset = 0;
			$root = $serializer->read($bytes, $offset, 48)->mustGetCompoundTag();
			return $little ? $this->readBedrock($root) : $this->readSponge($root);
		}catch(Throwable $e){
			throw new UnexpectedValueException("Invalid schematic: " . $e->getMessage(), 0, $e);
		}
	}

	public function encode(SchematicData $data, string $format = "schem", int $version = 3) : string{
		$little = $this->isBedrock($format);
		$this->dimensions($data->width, $data->height, $data->length);
		if(count($data->palette) > $this->maxPalette){
			throw new UnexpectedValueException("Schematic palette exceeds limit");
		}
		if(($little && $data->format !== "mcstructure") || (!$little && $data->format !== "sponge")){
			throw new UnexpectedValueException("Cross-edition export requires an explicit lossless block and tile translator");
		}
		$serializer = $little ? new LittleEndianNbtSerializer() : new BigEndianNbtSerializer();
		$root = $little ? $this->writeBedrock($data) : $this->writeSponge($data, $version);
		$bytes = $serializer->write(new TreeRoot($root, !$little && $version === 2 ? "Schematic" : ""));
		if(strlen($bytes) > $this->maxDecodedBytes){
			throw new UnexpectedValueException("Encoded schematic exceeds decoded size limit");
		}
		(new NbtGuard($bytes, $little, $this->maxNbtNodes))->validate();
		if(!$little){
			$bytes = gzencode($bytes);
			if($bytes === false){
				throw new UnexpectedValueException("Schematic compression failed");
			}
		}
		if(strlen($bytes) > $this->maxFileBytes){
			throw new UnexpectedValueException("Encoded schematic exceeds file size limit");
		}
		return $bytes;
	}

	private function isBedrock(string $format) : bool{
		return match(strtolower(ltrim($format, "."))){
			"mcstructure" => true,
			"schem", "sponge" => false,
			"schematic" => throw new UnexpectedValueException("Legacy .schematic requires an explicit ID/data translation table"),
			default => throw new UnexpectedValueException("Unsupported schematic format")
		};
	}

	private function dimensions(int $width, int $height, int $length) : int{
		if(min($width, $height, $length) < 1 || max($width, $height, $length) > 65535 || $width * $height * $length > $this->maxBlocks){
			throw new UnexpectedValueException("Invalid or excessive schematic dimensions");
		}
		return $width * $height * $length;
	}

	private function readSponge(CompoundTag $root) : SchematicData{
		$root = $root->getCompoundTag("Schematic") ?? $root;
		$version = $root->getInt("Version");
		if($version !== 2 && $version !== 3){
			throw new UnexpectedValueException("Only Sponge schematic versions 2 and 3 are supported");
		}
		$width = $root->getShort("Width") & 0xffff;
		$height = $root->getShort("Height") & 0xffff;
		$length = $root->getShort("Length") & 0xffff;
		$count = $this->dimensions($width, $height, $length);
		$container = $version === 3 ? $root->getCompoundTag("Blocks") : $root;
		$paletteTag = $container?->getCompoundTag("Palette");
		if($paletteTag === null || $paletteTag->getCount() === 0 || $paletteTag->getCount() > $this->maxPalette){
			throw new UnexpectedValueException("Missing or excessive block palette");
		}
		$palette = [];
		foreach($paletteTag as $name => $entry){
			if(!$entry instanceof IntTag || $entry->getValue() < 0 || $entry->getValue() >= $paletteTag->getCount() || isset($palette[$entry->getValue()])){
				throw new UnexpectedValueException("Invalid, duplicate, or sparse palette index");
			}
			$palette[$entry->getValue()] = (string) $name;
		}
		ksort($palette);
		if($version === 2 && $root->getInt("PaletteMax", count($palette)) !== count($palette)){
			throw new UnexpectedValueException("PaletteMax does not match palette");
		}
		$encoded = $container->getByteArray($version === 3 ? "Data" : "BlockData");
		$states = "";
		$offset = 0;
		for($i = 0; $i < $count; ++$i){
			$value = 0;
			for($shift = 0; ; $shift += 7){
				if($offset >= strlen($encoded) || $shift > 28){
					throw new UnexpectedValueException("Truncated or oversized palette VarInt");
				}
				$byte = ord($encoded[$offset++]);
				if($shift === 28 && ($byte & 0xf8) !== 0){
					throw new UnexpectedValueException("Palette VarInt overflows int32");
				}
				$value |= ($byte & 0x7f) << $shift;
				if(($byte & 0x80) === 0){
					if($shift > 0 && $byte === 0){
						throw new UnexpectedValueException("Noncanonical palette VarInt");
					}
					break;
				}
			}
			if(!isset($palette[$value])){
				throw new UnexpectedValueException("Block references missing palette entry");
			}
			$states .= pack("V", $value);
		}
		if($offset !== strlen($encoded)){
			throw new UnexpectedValueException("Block data length does not match dimensions");
		}
		$this->rejectEntities($root, "Entities");
		foreach(["Biomes", "BiomeData", "BiomePalette"] as $name){
			if($root->getTag($name) !== null){
				throw new UnexpectedValueException("Biome preservation is not supported");
			}
		}
		$tiles = [];
		$entries = $container->getListTag("BlockEntities", CompoundTag::class);
		if($container->getTag("BlockEntities") !== null && $entries === null){
			throw new UnexpectedValueException("Invalid block entity list");
		}
		foreach($entries ?? [] as $entry){
			$pos = array_values($entry->getIntArray("Pos"));
			$index = $this->tileIndex($pos, $width, $height, $length);
			if(isset($tiles[$index])){
				throw new UnexpectedValueException("Duplicate block entity position");
			}
			$tile = $version === 3 ? clone ($entry->getCompoundTag("Data") ?? throw new UnexpectedValueException("Missing block entity Data")) : clone $entry;
			$tile->removeTag("Pos", "Id");
			$tile->setString("id", $entry->getString("Id"));
			$tile->setInt("x", $pos[0])->setInt("y", $pos[1])->setInt("z", $pos[2]);
			$tiles[$index] = $tile;
		}
		$origin = array_values(array_map(static fn(int $value) : int => $value > 0x7fffffff ? $value - 0x100000000 : $value, $root->getIntArray("Offset", [0, 0, 0])));
		if(count($origin) !== 3){
			throw new UnexpectedValueException("Schematic offset must contain three coordinates");
		}
		$metadata = $root->getTag('Metadata');
		if($metadata !== null && !$metadata instanceof CompoundTag){ throw new UnexpectedValueException('Schematic Metadata must be a compound'); }
		return new SchematicData($width, $height, $length, $states, array_values($palette), [$origin[0], $origin[1], $origin[2]], $tiles, "sponge", [], $root->getInt("DataVersion", 0), $metadata, formatVersion: $version);
	}

	private function writeSponge(SchematicData $data, int $version) : CompoundTag{
		if($version !== 2 && $version !== 3){
			throw new UnexpectedValueException("Only Sponge schematic versions 2 and 3 are supported");
		}
		$root = CompoundTag::create()->setInt("Version", $version)->setInt("DataVersion", $data->dataVersion);
		foreach(["Width" => $data->width, "Height" => $data->height, "Length" => $data->length] as $name => $size){
			$root->setShort($name, $size > 32767 ? $size - 65536 : $size);
		}
		$root->setIntArray("Offset", $data->origin);
		if($data->metadata !== null){
			$root->setTag("Metadata", clone $data->metadata);
		}
		if($data->formatVersion !== $version){
			$placement = $data->placement();
			$min = $placement['min']; $origin = $placement['origin'];
			$metadata = $root->getCompoundTag('Metadata') ?? CompoundTag::create();
			if($version === 2){
				$root->setIntArray('Offset', $min);
				foreach(['WEOffsetX', 'WEOffsetY', 'WEOffsetZ'] as $axis => $key){ $metadata->setInt($key, $min[$axis] - $origin[$axis]); }
			}else{
				$root->setIntArray('Offset', [$min[0] - $origin[0], $min[1] - $origin[1], $min[2] - $origin[2]]);
				$worldEdit = $metadata->getCompoundTag('WorldEdit') ?? CompoundTag::create();
				$metadata->setTag('WorldEdit', $worldEdit->setIntArray('Origin', $origin));
			}
			$root->setTag('Metadata', $metadata);
		}
		$palette = CompoundTag::create();
		foreach($data->palette as $index => $name){
			if($palette->getTag($name) !== null){
				throw new UnexpectedValueException("Sponge palettes cannot contain duplicate state names");
			}
			$palette->setInt($name, $index);
		}
		$encoded = "";
		for($offset = 0; $offset < strlen($data->states); $offset += 4){
			$decoded = unpack("V", $data->states, $offset);
			if($decoded === false){
				throw new UnexpectedValueException("Invalid packed schematic state");
			}
			$value = $decoded[1];
			do{
				$next = $value >> 7;
				$encoded .= chr(($value & 0x7f) | ($next !== 0 ? 0x80 : 0));
				$value = $next;
			}while($value !== 0);
		}
		$tiles = [];
		foreach($data->tiles as $index => $tile){
			$pos = $this->position($index, $data);
			$copy = clone $tile;
			$id = $copy->getString("id");
			$copy->removeTag("id", "x", "y", "z");
			$entry = $version === 3 ? CompoundTag::create()->setTag("Data", $copy) : $copy;
			$tiles[] = $entry->setString("Id", $id)->setIntArray("Pos", $pos);
		}
		$container = $version === 3 ? CompoundTag::create() : $root;
		$container->setTag("Palette", $palette)->setByteArray($version === 3 ? "Data" : "BlockData", $encoded)->setTag("BlockEntities", new ListTag($tiles, NBT::TAG_Compound));
		if($version === 2){
			$root->setInt("PaletteMax", count($data->palette));
			return $root;
		}
		$root->setTag("Blocks", $container);
		return CompoundTag::create()->setTag("Schematic", $root);
	}

	private function readBedrock(CompoundTag $root) : SchematicData{
		if($root->getInt("format_version") !== 1){
			throw new UnexpectedValueException("Unsupported mcstructure version");
		}
		$size = $this->intList($root->getListTag("size", IntTag::class));
		$origin = $this->intList($root->getListTag("structure_world_origin", IntTag::class));
		if(count($size) !== 3 || count($origin) !== 3){
			throw new UnexpectedValueException("Invalid mcstructure size or origin");
		}
		[$width, $height, $length] = $size;
		$count = $this->dimensions($width, $height, $length);
		$structure = $root->getCompoundTag("structure") ?? throw new UnexpectedValueException("Missing structure");
		$this->rejectEntities($structure, "entities");
		$palettes = $structure->getCompoundTag("palette");
		if($palettes === null || $palettes->getCount() !== 1){
			throw new UnexpectedValueException("Multiple or missing structure palettes are unsupported");
		}
		$container = $palettes->getCompoundTag("default") ?? throw new UnexpectedValueException("Missing default palette");
		$entries = $container->getListTag("block_palette", CompoundTag::class);
		if($entries === null || $entries->getCount() < 1 || $entries->getCount() > $this->maxPalette){
			throw new UnexpectedValueException("Missing Bedrock palette");
		}
		$palette = [];
		$bedrock = [];
		foreach($entries as $entry){
			$name = $entry->getString("name");
			$properties = [];
			foreach($entry->getCompoundTag("states") ?? throw new UnexpectedValueException("Missing typed Bedrock states") as $key => $value){
				if(!$value instanceof ByteTag && !$value instanceof IntTag && !$value instanceof StringTag){
					throw new UnexpectedValueException("Unsupported Bedrock state property type");
				}
				$properties[] = $key . "=" . ($value instanceof StringTag ? json_encode($value->getValue(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) : $value->getValue());
			}
			$palette[] = $name . ($properties === [] ? "" : "[" . implode(",", $properties) . "]");
			$bedrock[] = clone $entry;
		}
		$layers = $structure->getListTag("block_indices", ListTag::class);
		if($layers === null || $layers->getCount() !== 2){
			throw new UnexpectedValueException("mcstructure must contain exactly two block layers");
		}
		$primary = $layers->get(0);
		$secondary = $layers->get(1);
		if($primary->getTagType() !== NBT::TAG_Int || $secondary->getTagType() !== NBT::TAG_Int || $primary->getCount() !== $count || $secondary->getCount() !== $count){
			throw new UnexpectedValueException("Invalid mcstructure block layer dimensions");
		}
		$states = str_repeat("\0", $count * 4);
		$tiles = [];
		$positionData = $container->getCompoundTag("block_position_data") ?? CompoundTag::create();
		foreach($secondary as $entry){
			if($entry->getValue() !== -1){
				throw new UnexpectedValueException("Secondary block layers require a multi-layer clipboard");
			}
		}
		foreach($primary as $i => $entry){
			$value = $entry->getValue();
			if(!isset($palette[$value])){
				throw new UnexpectedValueException("Missing structure blocks or palette references are unsupported");
			}
			$x = intdiv($i, $height * $length);
			$y = intdiv($i, $length) % $height;
			$z = $i % $length;
			$index = ($y * $length + $z) * $width + $x;
			$packed = pack("V", $value);
			for($byte = 0; $byte < 4; ++$byte){
				$states[$index * 4 + $byte] = $packed[$byte];
			}
			$position = $positionData->getCompoundTag((string) $i);
			if($position !== null){
				if($position->getCount() !== 1){
					throw new UnexpectedValueException("Unsupported extra block position data");
				}
				$tile = clone ($position->getCompoundTag("block_entity_data") ?? throw new UnexpectedValueException("Missing block entity data"));
				$tile->setInt("x", $x)->setInt("y", $y)->setInt("z", $z);
				$tiles[$index] = $tile;
			}
		}
		if(count($tiles) !== $positionData->getCount()){
			throw new UnexpectedValueException("Block position data references an invalid block");
		}
		$clipboardOrigin = $root->getTag('asyncworldedit_origin');
		$metadata = $clipboardOrigin === null ? null : CompoundTag::create()->setTag('ClipboardOrigin', clone $clipboardOrigin);
		return new SchematicData($width, $height, $length, $states, $palette, [$origin[0], $origin[1], $origin[2]], $tiles, "mcstructure", $bedrock, metadata: $metadata, formatVersion: 1);
	}

	private function writeBedrock(SchematicData $data) : CompoundTag{
		if($data->blockCount() * 2 > $this->maxNbtNodes){
			throw new UnexpectedValueException("mcstructure exceeds NBT allocation limit");
		}
		if(count($data->bedrockPalette) !== count($data->palette)){
			throw new UnexpectedValueException("mcstructure export requires the original typed Bedrock palette");
		}
		$primary = [];
		$positions = CompoundTag::create();
		for($x = 0; $x < $data->width; ++$x){
			for($y = 0; $y < $data->height; ++$y){
				for($z = 0; $z < $data->length; ++$z){
					$index = ($y * $data->length + $z) * $data->width + $x;
					if(isset($data->tiles[$index])){
						$tile = clone $data->tiles[$index];
						$tile->setInt("x", $x + $data->origin[0])->setInt("y", $y + $data->origin[1])->setInt("z", $z + $data->origin[2]);
						$positions->setTag((string) count($primary), CompoundTag::create()->setTag("block_entity_data", $tile));
					}
					$decoded = unpack("V", $data->states, $index * 4);
					if($decoded === false){
						throw new UnexpectedValueException("Invalid packed schematic state");
					}
					$primary[] = new IntTag($decoded[1]);
				}
			}
		}
		$palette = CompoundTag::create()->setTag("block_palette", new ListTag($data->bedrockPalette, NBT::TAG_Compound))->setTag("block_position_data", $positions);
		$structure = CompoundTag::create()->setTag("palette", CompoundTag::create()->setTag("default", $palette));
		$structure->setTag("block_indices", new ListTag([new ListTag($primary, NBT::TAG_Int), new ListTag(array_fill(0, $data->blockCount(), new IntTag(-1)), NBT::TAG_Int)], NBT::TAG_List));
		$structure->setTag("entities", new ListTag([], NBT::TAG_Compound));
		$root = CompoundTag::create()->setInt("format_version", 1)->setTag("size", $this->makeIntList([$data->width, $data->height, $data->length]))->setTag("structure_world_origin", $this->makeIntList($data->origin))->setTag("structure", $structure);
		$clipboardOrigin = $data->metadata?->getTag('ClipboardOrigin');
		if($clipboardOrigin !== null){ $root->setTag('asyncworldedit_origin', clone $clipboardOrigin); }
		return $root;
	}

	private function rejectEntities(CompoundTag $root, string $name) : void{
		$tag = $root->getTag($name);
		if($tag !== null && (!$tag instanceof ListTag || $tag->getCount() !== 0)){
			throw new UnexpectedValueException("Entity preservation is not supported");
		}
	}

	/** @return list<int> */
	private function intList(?ListTag $list) : array{
		if($list === null){
			return [];
		}
		$values = [];
		foreach($list as $tag){
			if(!$tag instanceof IntTag){
				throw new UnexpectedValueException("Expected an integer list");
			}
			$values[] = $tag->getValue();
		}
		return $values;
	}

	/** @param list<int> $values */
	private function makeIntList(array $values) : ListTag{
		return new ListTag(array_map(static fn(int $value) : IntTag => new IntTag($value), $values), NBT::TAG_Int);
	}

	/** @param list<int> $pos */
	private function tileIndex(array $pos, int $width, int $height, int $length) : int{
		if(count($pos) !== 3 || $pos[0] < 0 || $pos[0] >= $width || $pos[1] < 0 || $pos[1] >= $height || $pos[2] < 0 || $pos[2] >= $length){
			throw new UnexpectedValueException("Block entity position is outside schematic");
		}
		return ($pos[1] * $length + $pos[2]) * $width + $pos[0];
	}

	/** @return array{int, int, int} */
	private function position(int $index, SchematicData $data) : array{
		return [$index % $data->width, intdiv($index, $data->width * $data->length), intdiv($index, $data->width) % $data->length];
	}
}
