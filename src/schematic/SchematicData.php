<?php

declare(strict_types=1);

namespace asyncworldedit\schematic;

use InvalidArgumentException;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\IntArrayTag;
use pocketmine\nbt\tag\IntTag;

final readonly class SchematicData{

	/**
	 * @param list<string> $palette
	 * @param array{int, int, int} $origin Exact format offset (Sponge) or world origin (mcstructure).
	 * @param array<int, CompoundTag> $tiles Local YZX indices; tile coordinates are local.
	 * @param list<CompoundTag> $bedrockPalette Original typed Bedrock palette entries.
	 * @param bool $validateStates Only trusted producers that already check every index may disable this scan.
	 */
	public function __construct(
		public int $width,
		public int $height,
		public int $length,
		public string $states,
		public array $palette,
		public array $origin = [0, 0, 0],
		public array $tiles = [],
		public string $format = "sponge",
		public array $bedrockPalette = [],
		public int $dataVersion = 0,
		public ?CompoundTag $metadata = null,
		bool $validateStates = true,
		public int $formatVersion = 3
	){
		if($width < 1 || $height < 1 || $length < 1 || $width > 65535 || $height > 65535 || $length > 65535 || strlen($states) !== $width * $height * $length * 4){
			throw new InvalidArgumentException("Invalid schematic dimensions or packed states");
		}
		if(!array_is_list($palette) || $palette === []){
			throw new InvalidArgumentException("Schematic palette must be a nonempty list");
		}
		foreach($palette as $state){
			if(!is_string($state) || $state === "" || strlen($state) > 32767){
				throw new InvalidArgumentException("Invalid schematic palette state");
			}
		}
		if(!array_is_list($origin) || count($origin) !== 3){
			throw new InvalidArgumentException("Schematic origin must contain three integers");
		}
		foreach($origin as $coordinate){
			if(!is_int($coordinate) || $coordinate < -2147483648 || $coordinate > 2147483647){
				throw new InvalidArgumentException("Schematic origin exceeds int32 coordinates");
			}
		}
		if($validateStates){
			$count = count($palette);
			for($offset = 0, $size = strlen($states); $offset < $size; $offset += 4){
				$decoded = unpack("V", $states, $offset);
				if($decoded === false || $decoded[1] >= $count){
					throw new InvalidArgumentException("Schematic state references missing palette entry");
				}
			}
		}
		foreach($tiles as $index => $tile){
			if(!is_int($index) || $index < 0 || $index >= $width * $height * $length || !$tile instanceof CompoundTag){
				throw new InvalidArgumentException("Invalid schematic block entity");
			}
		}
		$this->placement();
	}

	public function blockCount() : int{
		return $this->width * $this->height * $this->length;
	}

	/** @return array{min:array{int,int,int},origin:array{int,int,int}} */
	public function placement() : array{
		if($this->format === 'mcstructure'){
			$tag = $this->metadata?->getTag('ClipboardOrigin');
			return ['min' => $this->origin, 'origin' => $tag === null ? $this->origin : self::point($tag)];
		}
		if($this->formatVersion === 2){
			$offset = [];
			foreach(['WEOffsetX', 'WEOffsetY', 'WEOffsetZ'] as $key){
				$tag = $this->metadata?->getTag($key);
				if($tag !== null && !$tag instanceof IntTag){ throw new InvalidArgumentException('Invalid WorldEdit offset metadata'); }
				$offset[] = $tag?->getValue();
			}
			if($offset === [null, null, null]){ return ['min' => $this->origin, 'origin' => $this->origin]; }
			if(in_array(null, $offset, true)){ throw new InvalidArgumentException('Incomplete WorldEdit offset metadata'); }
			return ['min' => $this->origin, 'origin' => [$this->origin[0] - $offset[0], $this->origin[1] - $offset[1], $this->origin[2] - $offset[2]]];
		}
		$worldEdit = $this->metadata?->getTag('WorldEdit');
		if($worldEdit !== null && !$worldEdit instanceof CompoundTag){ throw new InvalidArgumentException('Invalid WorldEdit metadata'); }
		$tag = $worldEdit?->getTag('Origin');
		$origin = $tag === null ? [0, 0, 0] : self::point($tag);
		return ['min' => [$this->origin[0] + $origin[0], $this->origin[1] + $origin[1], $this->origin[2] + $origin[2]], 'origin' => $origin];
	}

	/** @return array{int,int,int} */
	private static function point(\pocketmine\nbt\tag\Tag $tag) : array{
		if(!$tag instanceof IntArrayTag){ throw new InvalidArgumentException('Origin metadata must be an int array'); }
		$values = array_values($tag->getValue());
		if(count($values) !== 3){ throw new InvalidArgumentException('Origin metadata must have three coordinates'); }
		foreach($values as &$value){ if($value > 0x7fffffff){ $value -= 0x100000000; } }
		return [$values[0], $values[1], $values[2]];
	}
}
