<?php

declare(strict_types=1);

namespace Trix\WorldEdit\schematic;

use UnexpectedValueException;

/** Walks untrusted NBT and bounds allocations before any tag objects are built. */
final class NbtGuard{
	private const PRIMITIVE = [1 => 1, 2 => 2, 3 => 4, 4 => 8, 5 => 4, 6 => 8];
	private const ARRAY = [7 => 1, 11 => 4, 12 => 8];

	private int $offset = 0;
	/** @var array{int, int, int}|null tag start, payload start and end of the tag at $path */
	public ?array $found = null;

	/**
	 * @param list<string> $path names of a tag to locate, from the root compound down; its contents cost no nodes
	 *                           because callers read it directly instead of building tags
	 */
	public function __construct(private readonly string $data, private readonly bool $littleEndian, private int $remainingNodes = 262144, private readonly array $path = []){}

	public function validate() : void{
		if($this->byte() !== 10){
			throw new UnexpectedValueException('NBT root must be a compound');
		}
		$this->string();
		$this->payload(10, 0, true, false);
		if($this->offset !== strlen($this->data)){
			throw new UnexpectedValueException('Unexpected trailing NBT data');
		}
	}

	private function skip(int $length) : void{
		if($length < 0 || $length > strlen($this->data) - $this->offset){
			throw new UnexpectedValueException('Truncated NBT payload');
		}
		$this->offset += $length;
	}

	private function byte() : int{
		$this->skip(1);
		return ord($this->data[$this->offset - 1]);
	}

	private function int() : int{
		$this->skip(4);
		$value = unpack($this->littleEndian ? 'V' : 'N', $this->data, $this->offset - 4)[1] ?? throw new UnexpectedValueException('Invalid NBT integer');
		if($value > 0x7fffffff){
			throw new UnexpectedValueException('Negative NBT collection length');
		}
		return $value;
	}

	private function string() : string{
		$this->skip(2);
		$length = unpack($this->littleEndian ? 'v' : 'n', $this->data, $this->offset - 2)[1] ?? throw new UnexpectedValueException('Invalid NBT string length');
		if($length > 32767){
			throw new UnexpectedValueException('NBT string is too large');
		}
		$this->skip($length);
		return substr($this->data, $this->offset - $length, $length);
	}

	private function spend(int $nodes, bool $free) : void{
		if(!$free && ($this->remainingNodes -= $nodes) < 0){
			throw new UnexpectedValueException('NBT complexity limit exceeded');
		}
	}

	private function payload(int $type, int $depth, bool $onPath, bool $free) : void{
		if($depth > 48){
			throw new UnexpectedValueException('NBT nesting limit exceeded');
		}
		$this->spend(1, $free);
		if(isset(self::PRIMITIVE[$type])){
			$this->skip(self::PRIMITIVE[$type]);
		}elseif(isset(self::ARRAY[$type])){
			$count = $this->int();
			if($type !== 7){
				$this->spend($count, $free);
			}
			$this->skip($count * self::ARRAY[$type]);
		}elseif($type === 8){
			$this->string();
		}elseif($type === 9){
			$child = $this->byte();
			$count = $this->int();
			if($child > 12 || ($child === 0 && $count !== 0) || (!$free && $count > $this->remainingNodes)){
				throw new UnexpectedValueException('Invalid or excessive NBT list');
			}
			if(isset(self::PRIMITIVE[$child])){
				$this->spend($count, $free);
				$this->skip($count * self::PRIMITIVE[$child]);
			}else{
				for($i = 0; $i < $count; ++$i){
					$this->payload($child, $depth + 1, false, $free);
				}
			}
		}elseif($type === 10){
			$names = [];
			while(($child = $this->byte()) !== 0){
				$start = $this->offset - 1;
				$name = $this->string();
				if(isset($names[$name])){
					throw new UnexpectedValueException('Duplicate NBT compound name');
				}
				$names[$name] = true;
				$match = $onPath && ($this->path[$depth] ?? null) === $name;
				$target = $match && $depth === count($this->path) - 1;
				$payload = $this->offset;
				$this->payload($child, $depth + 1, $match, $free || $target);
				if($target){
					$this->found = [$start, $payload, $this->offset];
				}
			}
		}else{
			throw new UnexpectedValueException('Unknown NBT tag type');
		}
	}
}
