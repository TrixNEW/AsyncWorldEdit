<?php

declare(strict_types=1);

namespace Trix\WorldEdit\schematic;

use InvalidArgumentException;
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
	private const INDICES_PATH = ['structure', 'block_indices'];

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
			$bytes = $this->decompress($bytes);
		}
		if(strlen($bytes) > $this->maxDecodedBytes){
			throw new UnexpectedValueException("Decoded schematic exceeds size limit");
		}
		// mcstructure block layers are read straight from the bytes instead of as two tags per block.
		$guard = new NbtGuard($bytes, $little, $this->maxNbtNodes, $little ? self::INDICES_PATH : []);
		$guard->validate();
		try{
			if(!$little){
				return $this->readSponge(NbtCodec::read($bytes, false)->mustGetCompoundTag());
			}
			[$start, $payload, $end] = $guard->found ?? throw new UnexpectedValueException('mcstructure must contain block indices');
			$root = NbtCodec::read(substr($bytes, 0, $start) . substr($bytes, $end), true)->mustGetCompoundTag();
			return $this->readBedrock($root, substr($bytes, $payload, $end - $payload));
		}catch(Throwable $e){
			throw new UnexpectedValueException("Invalid schematic: " . $e->getMessage(), 0, $e);
		}
	}

	public function encode(SchematicData $data, string $format = "schem", int $version = 3) : string{
		$little = $this->isBedrock($format);
		$data->placement();
		$this->dimensions($data->width, $data->height, $data->length);
		if(count($data->palette) > $this->maxPalette){
			throw new UnexpectedValueException("Schematic palette exceeds limit");
		}
		if(($little && $data->format !== "mcstructure") || (!$little && $data->format !== "sponge")){
			throw new UnexpectedValueException("Cross-edition export requires an explicit lossless block and tile translator");
		}
		$bytes = $little ? $this->writeBedrock($data) : NbtCodec::write(new TreeRoot($this->writeSponge($data, $version), $version === 2 ? "Schematic" : ""), false);
		if(strlen($bytes) > $this->maxDecodedBytes){
			throw new UnexpectedValueException("Encoded schematic exceeds decoded size limit");
		}
		(new NbtGuard($bytes, $little, $this->maxNbtNodes, $little ? self::INDICES_PATH : []))->validate();
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

	private function decompress(string $bytes) : string{
		$context = inflate_init(ZLIB_ENCODING_GZIP);
		if($context === false){
			throw new UnexpectedValueException('Cannot initialize schematic decompression');
		}
		$decoded = '';
		$length = strlen($bytes);
		for($offset = 0; $offset < $length; $offset += 1024){
			$part = @inflate_add($context, substr($bytes, $offset, 1024), $offset + 1024 >= $length ? ZLIB_FINISH : ZLIB_SYNC_FLUSH);
			if($part === false || strlen($part) > $this->maxDecodedBytes - strlen($decoded)){
				throw new UnexpectedValueException('Invalid or excessive compressed schematic');
			}
			$decoded .= $part;
			if(inflate_get_status($context) === ZLIB_STREAM_END){
				break;
			}
		}
		if(inflate_get_status($context) !== ZLIB_STREAM_END || inflate_get_read_len($context) !== $length){
			throw new UnexpectedValueException('Truncated or trailing compressed schematic data');
		}
		return $decoded;
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
			foreach($placement as $point){
				foreach($point as $coordinate){
					if($coordinate < -0x80000000 || $coordinate > 0x7fffffff){
						throw new UnexpectedValueException('Schematic placement exceeds int32 coordinates for format conversion');
					}
				}
			}
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
			if($decoded === false || $decoded[1] >= count($data->palette)){
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

	/** @param string $layers raw payload of structure.block_indices */
	private function readBedrock(CompoundTag $root, string $layers) : SchematicData{
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
			$properties = [];
			foreach($entry->getCompoundTag("states") ?? throw new UnexpectedValueException("Missing typed Bedrock states") as $key => $value){
				if(!$value instanceof ByteTag && !$value instanceof IntTag && !$value instanceof StringTag){
					throw new UnexpectedValueException("Unsupported Bedrock state property type");
				}
				$properties[] = $key . "=" . ($value instanceof StringTag ? json_encode($value->getValue(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) : $value->getValue());
			}
			$palette[] = $entry->getString("name") . ($properties === [] ? "" : "[" . implode(",", $properties) . "]");
			$bedrock[] = clone $entry;
		}

		// A list of two int lists, each holding one palette index per block in XYZ order.
		$layerBytes = 5 + $count * 4;
		if(strlen($layers) !== 5 + 2 * $layerBytes || $layers[0] !== "\x09" || (unpack("V", $layers, 1)[1] ?? 0) !== 2){
			throw new UnexpectedValueException("mcstructure must contain exactly two block layers");
		}
		foreach([5, 5 + $layerBytes] as $offset){
			if($layers[$offset] !== "\x03" || (unpack("V", $layers, $offset + 1)[1] ?? -1) !== $count){
				throw new UnexpectedValueException("Invalid mcstructure block layer dimensions");
			}
		}
		if(strspn($layers, "\xff", 10 + $layerBytes, $count * 4) !== $count * 4){
			throw new UnexpectedValueException("Secondary block layers require a multi-layer clipboard");
		}
		$paletteCount = count($palette);
		$states = str_repeat("\0", $count * 4);
		$i = 0;
		for($x = 0; $x < $width; ++$x){
			for($y = 0; $y < $height; ++$y){
				$row = unpack("V" . $length, $layers, 10 + $i * 4) ?: throw new UnexpectedValueException("Truncated mcstructure block layer");
				for($z = 0; $z < $length; ++$z, ++$i){
					if($row[$z + 1] >= $paletteCount){
						throw new UnexpectedValueException("Missing structure blocks or palette references are unsupported");
					}
					$source = 10 + $i * 4;
					$target = (($y * $length + $z) * $width + $x) * 4;
					$states[$target] = $layers[$source];
					$states[$target + 1] = $layers[$source + 1];
					$states[$target + 2] = $layers[$source + 2];
					$states[$target + 3] = $layers[$source + 3];
				}
			}
		}

		$tiles = [];
		foreach($container->getCompoundTag("block_position_data") ?? [] as $key => $position){
			$key = (string) $key;
			if(!$position instanceof CompoundTag || preg_match('/\A(?:0|[1-9][0-9]*)\z/', $key) !== 1 || (int) $key >= $count){
				throw new UnexpectedValueException("Block position data references an invalid block");
			}
			if($position->getCount() !== 1){
				throw new UnexpectedValueException("Unsupported extra block position data");
			}
			$i = (int) $key;
			[$x, $y, $z] = [intdiv($i, $height * $length), intdiv($i, $length) % $height, $i % $length];
			$tile = clone ($position->getCompoundTag("block_entity_data") ?? throw new UnexpectedValueException("Missing block entity data"));
			$tiles[($y * $length + $z) * $width + $x] = $tile->setInt("x", $x)->setInt("y", $y)->setInt("z", $z);
		}
		$clipboardOrigin = $root->getTag('asyncworldedit_origin');
		$metadata = $clipboardOrigin === null ? null : CompoundTag::create()->setTag('ClipboardOrigin', clone $clipboardOrigin);
		return new SchematicData($width, $height, $length, $states, $palette, [$origin[0], $origin[1], $origin[2]], $tiles, "mcstructure", $bedrock, metadata: $metadata, validateStates: false, formatVersion: 1);
	}

	private function writeBedrock(SchematicData $data) : string{
		if(count($data->bedrockPalette) !== count($data->palette)){
			throw new UnexpectedValueException("mcstructure export requires the original typed Bedrock palette");
		}
		[$width, $height, $length] = [$data->width, $data->height, $data->length];
		$count = $data->blockCount();
		$paletteCount = count($data->palette);
		$primary = str_repeat("\0", $count * 4);
		$i = 0;
		for($x = 0; $x < $width; ++$x){
			for($y = 0; $y < $height; ++$y){
				for($z = 0; $z < $length; ++$z, ++$i){
					$source = (($y * $length + $z) * $width + $x) * 4;
					if((unpack("V", $data->states, $source)[1] ?? PHP_INT_MAX) >= $paletteCount){
						throw new UnexpectedValueException("Invalid packed schematic state");
					}
					$target = $i * 4;
					$primary[$target] = $data->states[$source];
					$primary[$target + 1] = $data->states[$source + 1];
					$primary[$target + 2] = $data->states[$source + 2];
					$primary[$target + 3] = $data->states[$source + 3];
				}
			}
		}
		$positions = CompoundTag::create();
		foreach($data->tiles as $index => $tile){
			[$x, $y, $z] = $this->position($index, $data);
			$tile = (clone $tile)->setInt("x", $x + $data->origin[0])->setInt("y", $y + $data->origin[1])->setInt("z", $z + $data->origin[2]);
			$positions->setTag((string) (($x * $height + $y) * $length + $z), CompoundTag::create()->setTag("block_entity_data", $tile));
		}
		$palette = CompoundTag::create()->setTag("block_palette", new ListTag($data->bedrockPalette, NBT::TAG_Compound))->setTag("block_position_data", $positions);
		$root = CompoundTag::create()->setInt("format_version", 1)->setTag("size", $this->makeIntList([$width, $height, $length]))->setTag("structure_world_origin", $this->makeIntList($data->origin));
		$clipboardOrigin = $data->metadata?->getTag('ClipboardOrigin');
		if($clipboardOrigin !== null){
			$root->setTag('asyncworldedit_origin', clone $clipboardOrigin);
		}
		// structure goes last so block_indices can be spliced in just before the two closing tags.
		$root->setTag("structure", CompoundTag::create()->setTag("palette", CompoundTag::create()->setTag("default", $palette))->setTag("entities", new ListTag([], NBT::TAG_Compound)));
		$bytes = NbtCodec::write(new TreeRoot($root), true);
		if(!str_ends_with($bytes, "\x00\x00")){
			throw new \LogicException("Unexpected mcstructure encoding");
		}
		$layer = "\x03" . pack("V", $count);
		return substr($bytes, 0, -2) . "\x09" . pack("v", 13) . "block_indices\x09" . pack("V", 2) . $layer . $primary . $layer . str_repeat("\xff", $count * 4) . "\x00\x00";
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
