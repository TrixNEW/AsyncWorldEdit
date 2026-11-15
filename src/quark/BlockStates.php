<?php

declare(strict_types=1);

namespace asyncworldedit\quark;

use pocketmine\nbt\tag\ByteTag;
use pocketmine\nbt\tag\IntTag;
use pocketmine\nbt\tag\StringTag;
use pocketmine\nbt\tag\Tag;
use quark\block\Air;
use quark\block\Block;
use quark\block\RuntimeBlockStateRegistry;
use quark\block\Stair;
use quark\block\utils\AnyFacing;
use quark\block\utils\HorizontalFacing;
use quark\block\utils\StairShape;
use quark\data\bedrock\block\BlockStateDeserializeException;
use quark\data\bedrock\block\BlockStateData;
use quark\world\format\io\GlobalBlockStateHandlers;
use quark\item\StringToItemParser;
use quark\math\Facing;

final class BlockStates{
	public function resolve(string $expression) : int{
		if(strlen($expression) > 512 || !preg_match('/^([a-zA-Z0-9_.:-]+)(?:\[(.*)\])?$/sD', $expression, $match)){
			throw new \InvalidArgumentException("Invalid Bedrock block state: $expression");
		}

		$name = strtolower($match[1]);
		$hasProperties = str_contains($expression, '[');
		if(!$hasProperties){
			if(!str_contains($name, ':')){
				$item = StringToItemParser::getInstance()->parse($name);
				if($item === null){
					throw new \InvalidArgumentException("Unknown Quark block state: $name");
				}
				$block = $item->getBlock();
				if($block instanceof Air && $name !== 'air' && $name !== 'minecraft:air'){
					throw new \InvalidArgumentException("Not a block identifier: $name");
				}
				return $this->checkedState($block->getStateId());
			}
			return $this->deserialize(BlockStateData::current($name, []));
		}

		$properties = $match[2] ?? '';
		$states = [];
		$seen = [];
		if($properties !== ''){
			foreach(self::splitProperties($properties) as $property){
				if(!preg_match('/^\s*("(?:[^"\\\\]|\\\\.)*"|[a-zA-Z0-9_.:-]+)\s*=\s*(.*?)\s*$/sD', $property, $parts) || $parts[2] === ''){
					throw new \InvalidArgumentException("Invalid Bedrock block property in: $expression");
				}
				$key = $parts[1][0] === '"' ? self::parseString($parts[1]) : $parts[1];
				if(isset($seen[$key])){
					throw new \InvalidArgumentException("Duplicate Bedrock block property: $key");
				}
				$seen[$key] = true;
				$states[$key] = self::parseValue($parts[2]);
			}
		}
		return $this->deserialize(BlockStateData::current($name, $states));
	}

	public function name(int $state) : string{
		$this->checkedState($state);
		$data = GlobalBlockStateHandlers::getSerializer()->serialize($state);
		$states = $data->getStates();
		ksort($states);
		if($states === []){
			return $data->getName();
		}
		$properties = [];
		foreach($states as $key => $tag){
			$properties[] = $key . '=' . self::formatValue($tag);
		}
		return $data->getName() . '[' . implode(',', $properties) . ']';
	}

	public function solid(int $state) : bool{
		return RuntimeBlockStateRegistry::getInstance()->fromStateId($this->checkedState($state))->isSolid();
	}

	public function air() : int{
		return $this->checkedState(\quark\block\VanillaBlocks::AIR()->getStateId());
	}

	public function transform(int $state, int $turns, bool $flipX, bool $flipY, bool $flipZ) : int{
		$this->checkedState($state);
		$turns = (($turns % 4) + 4) % 4;
		if($turns === 0 && !$flipX && !$flipY && !$flipZ){
			return $state;
		}

		$block = RuntimeBlockStateRegistry::getInstance()->fromStateId($state);
		$states = GlobalBlockStateHandlers::getSerializer()->serialize($state)->getStates();
		$horizontalFlip = $flipX !== $flipZ;

		if($block instanceof HorizontalFacing || $block instanceof AnyFacing){
			$allowed = ['direction', 'facing_direction', 'minecraft:facing_direction', 'minecraft:cardinal_direction', 'weirdo_direction', 'torch_facing_direction'];
			if($block instanceof Stair){
				$allowed = [...$allowed, 'shape', 'upside_down_bit', 'upside_down'];
			}
			self::assertOnlyProperties($states, $allowed);
			foreach(array_keys($states) as $property){
				if(preg_match('/(?:hinge|rail)/i', $property)){
					throw new \InvalidArgumentException("Cannot safely transform block state property $property");
				}
			}
			$facing = $block->getFacing();
			if($flipX){
				$facing = match($facing){Facing::EAST => Facing::WEST, Facing::WEST => Facing::EAST, default => $facing};
			}
			if($flipY){
				$facing = match($facing){Facing::UP => Facing::DOWN, Facing::DOWN => Facing::UP, default => $facing};
			}
			if($flipZ){
				$facing = match($facing){Facing::NORTH => Facing::SOUTH, Facing::SOUTH => Facing::NORTH, default => $facing};
			}
			for($i = 0; $i < $turns; ++$i){
				$facing = Facing::rotateY($facing, true);
			}
			$block->setFacing($facing);
		}elseif($block instanceof \quark\block\Slab){
			self::assertOnlyProperties($states, ['top_slot_bit', 'minecraft:vertical_half']);
			if($flipY){
				$block->setSlabType(match($block->getSlabType()){
					\quark\block\utils\SlabType::TOP => \quark\block\utils\SlabType::BOTTOM,
					\quark\block\utils\SlabType::BOTTOM => \quark\block\utils\SlabType::TOP,
					default => $block->getSlabType()
				});
			}
		}elseif(isset($states['minecraft:cardinal_direction']) && $states['minecraft:cardinal_direction'] instanceof StringTag){
			self::assertOnlyProperties($states, ['minecraft:cardinal_direction']);
			$direction = self::transformDirection($states['minecraft:cardinal_direction']->getValue(), $turns, $flipX, $flipZ);
			$states['minecraft:cardinal_direction'] = new StringTag($direction);
		}elseif(isset($states['pillar_axis']) && $states['pillar_axis'] instanceof StringTag){
			self::assertOnlyProperties($states, ['pillar_axis']);
			$axis = $states['pillar_axis']->getValue();
			if(($turns & 1) !== 0 && ($axis === 'x' || $axis === 'z')){
				$states['pillar_axis'] = new StringTag($axis === 'x' ? 'z' : 'x');
			}
		}elseif(isset($states['portal_axis']) && $states['portal_axis'] instanceof StringTag){
			self::assertOnlyProperties($states, ['portal_axis']);
			$axis = $states['portal_axis']->getValue();
			if(($turns & 1) !== 0 && ($axis === 'x' || $axis === 'z')){
				$states['portal_axis'] = new StringTag($axis === 'x' ? 'z' : 'x');
			}
		}else{
			self::assertOnlyProperties($states, []);
		}

		if($block instanceof Stair){
			if($flipY){
				$block->setUpsideDown(!$block->isUpsideDown());
			}
			if($horizontalFlip){
				$block->setShape(match($block->getShape()){
					StairShape::OUTER_LEFT => StairShape::OUTER_RIGHT,
					StairShape::OUTER_RIGHT => StairShape::OUTER_LEFT,
					StairShape::INNER_LEFT => StairShape::INNER_RIGHT,
					StairShape::INNER_RIGHT => StairShape::INNER_LEFT,
					default => $block->getShape()
				});
			}
		}

		if($block->getStateId() !== $state){
			return $this->checkedState($block->getStateId());
		}
		if($states !== GlobalBlockStateHandlers::getSerializer()->serialize($state)->getStates()){
			return $this->deserialize(BlockStateData::current(GlobalBlockStateHandlers::getSerializer()->serialize($state)->getName(), $states));
		}
		return $state;
	}

	private function deserialize(BlockStateData $data) : int{
		$states = $data->getStates();
		$retried = [];
		for($attempt = 0; ; ++$attempt){
			$data = BlockStateData::current($data->getName(), $states);
			try{
				$state = $this->checkedState(GlobalBlockStateHandlers::getDeserializer()->deserialize($data));
				$encoded = GlobalBlockStateHandlers::getSerializer()->serialize($state);
				if(!$encoded->equals($data)){
					if($encoded->getName() === $data->getName() && self::normalizeNumericPropertyTypes($states, $encoded->getStates())){
						continue;
					}
					throw new \InvalidArgumentException("Block state cannot roundtrip exactly: " . self::formatState($data));
				}
				return $state;
			}catch(BlockStateDeserializeException $e){
				if($attempt < count($states) && preg_match('/Property "([^"]+)" has unexpected type .*\\b(ByteTag|IntTag)$/', $e->getMessage(), $match) && !isset($retried[$match[1]]) && isset($states[$match[1]])){
					$property = $match[1];
					$value = $states[$property]->getValue();
					if($states[$property] instanceof IntTag && $match[2] === 'IntTag'){
						$states[$property] = new ByteTag($value);
					}elseif($states[$property] instanceof ByteTag && $match[2] === 'ByteTag'){
						$states[$property] = new IntTag($value);
					}else{
						throw new \InvalidArgumentException("Invalid Quark block state: " . $data->getName(), 0, $e);
					}
					$retried[$property] = true;
					continue;
				}
				throw new \InvalidArgumentException("Invalid Quark block state: " . $data->getName(), 0, $e);
			}
		}
	}

	private function checkedState(int $state) : int{
		if(!RuntimeBlockStateRegistry::getInstance()->hasStateId($state)){
			throw new \InvalidArgumentException("Unknown Quark block state ID: $state");
		}
		return $state;
	}

	private static function parseValue(string $value) : Tag{
		if($value === 'true'){
			return new ByteTag(1);
		}
		if($value === 'false'){
			return new ByteTag(0);
		}
		if(preg_match('/^-?(?:0|[1-9][0-9]*)$/D', $value)){
			$int = filter_var($value, FILTER_VALIDATE_INT);
			if($int === false){
				throw new \InvalidArgumentException("Integer block property is out of range: $value");
			}
			return new IntTag($int);
		}
		return new StringTag(self::parseString($value));
	}

	private static function parseString(string $value) : string{
		if($value[0] !== '"'){
			return $value;
		}
		try{
			$decoded = json_decode($value, true, 2, JSON_THROW_ON_ERROR);
		}catch(\JsonException $e){
			throw new \InvalidArgumentException("Invalid quoted block property: $value", 0, $e);
		}
		if(!is_string($decoded)){
			throw new \InvalidArgumentException("String block property must be quoted: $value");
		}
		return $decoded;
	}

	private static function formatValue(Tag $tag) : string{
		if($tag instanceof ByteTag){
			return match($tag->getValue()){
				0, 1 => (string) $tag->getValue(),
				default => throw new \InvalidArgumentException("Cannot encode non-boolean ByteTag property")
			};
		}
		if($tag instanceof IntTag){
			return (string) $tag->getValue();
		}
		if($tag instanceof StringTag){
			return json_encode($tag->getValue(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
		}
		throw new \InvalidArgumentException("Unsupported Quark block state property type " . $tag::class);
	}

	private static function formatState(BlockStateData $data) : string{
		$properties = [];
		foreach($data->getStates() as $key => $tag){
			$properties[] = $key . '=' . $tag->getValue() . '(' . $tag::class . ')';
		}
		return $data->getName() . '[' . implode(',', $properties) . ']';
	}

	/**
	 * @param array<string, Tag> $states
	 * @param array<string, Tag> $encoded
	 */
	private static function normalizeNumericPropertyTypes(array &$states, array $encoded) : bool{
		if(count($states) !== count($encoded)){
			return false;
		}
		$changed = false;
		foreach($states as $key => $tag){
			$actual = $encoded[$key] ?? null;
			if($actual === null || $tag->getValue() !== $actual->getValue()){
				return false;
			}
			if($tag::class !== $actual::class){
				if(!(($tag instanceof ByteTag && $actual instanceof IntTag) || ($tag instanceof IntTag && $actual instanceof ByteTag))){
					return false;
				}
				$states[$key] = $actual;
				$changed = true;
			}
		}
		return $changed;
	}

	private static function transformDirection(string $direction, int $turns, bool $flipX, bool $flipZ) : string{
		if($flipX){
			$direction = match($direction){'east' => 'west', 'west' => 'east', default => $direction};
		}
		if($flipZ){
			$direction = match($direction){'north' => 'south', 'south' => 'north', default => $direction};
		}
		for($i = 0; $i < $turns; ++$i){
			$direction = match($direction){
				'north' => 'east', 'east' => 'south', 'south' => 'west', 'west' => 'north',
				default => $direction
			};
		}
		return $direction;
	}

	/** @return list<string> */
	private static function splitProperties(string $properties) : array{
		$result = [];
		$start = 0;
		$quoted = false;
		$escaped = false;
		for($i = 0, $length = strlen($properties); $i < $length; ++$i){
			$char = $properties[$i];
			if($quoted){
				if($escaped){
					$escaped = false;
				}elseif($char === '\\'){
					$escaped = true;
				}elseif($char === '"'){
					$quoted = false;
				}
			}elseif($char === '"'){
				$quoted = true;
			}elseif($char === ','){
				$result[] = substr($properties, $start, $i - $start);
				$start = $i + 1;
			}
		}
		if($quoted || $escaped){
			throw new \InvalidArgumentException("Unclosed quoted block property");
		}
		$result[] = substr($properties, $start);
		return $result;
	}

	/**
	 * @param array<string, Tag> $states
	 * @param list<string> $supported
	 */
	private static function assertOnlyProperties(array $states, array $supported) : void{
		foreach(array_keys($states) as $property){
			if(!in_array($property, $supported, true) && preg_match('/(?:direction|facing|hinge|rail|shape|axis|rotation|top_slot|upper_block|upside_down|multi_face|attachment)/i', $property)){
				throw new \InvalidArgumentException("Cannot safely transform block state property $property");
			}
		}
	}
}
