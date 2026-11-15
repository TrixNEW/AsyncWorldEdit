<?php

declare(strict_types=1);

namespace asyncworldedit\schematic;

use UnexpectedValueException;

/** Bounds allocations before the NBT library constructs tag objects. */
final class NbtGuard{

	private int $offset = 0;

	public function __construct(private string $data, private bool $littleEndian, private int $remainingNodes = 262144){
	}

	public function validate() : void{
		if($this->byte() !== 10){
			throw new UnexpectedValueException("NBT root must be a compound");
		}
		$this->string();
		$this->payload(10, 0);
		if($this->offset !== strlen($this->data)){
			throw new UnexpectedValueException("Unexpected trailing NBT data");
		}
	}

	private function skip(int $length) : void{
		if($length < 0 || $length > strlen($this->data) - $this->offset){
			throw new UnexpectedValueException("Truncated NBT payload");
		}
		$this->offset += $length;
	}

	private function byte() : int{
		$this->skip(1);
		return ord($this->data[$this->offset - 1]);
	}

	private function int() : int{
		$this->skip(4);
		$decoded = unpack($this->littleEndian ? "V" : "N", $this->data, $this->offset - 4);
		if($decoded === false){
			throw new UnexpectedValueException("Invalid NBT integer");
		}
		$value = $decoded[1];
		if($value > 0x7fffffff){
			throw new UnexpectedValueException("Negative NBT collection length");
		}
		return $value;
	}

	private function string() : string{
		$this->skip(2);
		$decoded = unpack($this->littleEndian ? "v" : "n", $this->data, $this->offset - 2);
		if($decoded === false){
			throw new UnexpectedValueException("Invalid NBT string length");
		}
		$length = $decoded[1];
		if($length > 32767){
			throw new UnexpectedValueException("NBT string is too large");
		}
		$this->skip($length);
		return substr($this->data, $this->offset - $length, $length);
	}

	private function payload(int $type, int $depth) : void{
		if(--$this->remainingNodes < 0 || $depth > 48){
			throw new UnexpectedValueException("NBT complexity limit exceeded");
		}
		if($type >= 1 && $type <= 6){
			$this->skip([1 => 1, 2 => 2, 3 => 4, 4 => 8, 5 => 4, 6 => 8][$type]);
		}elseif($type === 7 || $type === 11 || $type === 12){
			$count = $this->int();
			if($type !== 7){
				$this->remainingNodes -= $count;
				if($this->remainingNodes < 0){
					throw new UnexpectedValueException("NBT array complexity limit exceeded");
				}
			}
			$this->skip($count * [7 => 1, 11 => 4, 12 => 8][$type]);
		}elseif($type === 8){
			$this->string();
		}elseif($type === 9){
			$child = $this->byte();
			$count = $this->int();
			if($child > 12 || ($child === 0 && $count !== 0) || $count > $this->remainingNodes){
				throw new UnexpectedValueException("Invalid or excessive NBT list");
			}
			if($child >= 1 && $child <= 6){
				$this->remainingNodes -= $count;
				$this->skip($count * [1 => 1, 2 => 2, 3 => 4, 4 => 8, 5 => 4, 6 => 8][$child]);
			}else{
				for($i = 0; $i < $count; ++$i){
					$this->payload($child, $depth + 1);
				}
			}
		}elseif($type === 10){
			$names = [];
			while(($child = $this->byte()) !== 0){
				$name = $this->string();
				if(isset($names[$name])){
					throw new UnexpectedValueException("Duplicate NBT compound name");
				}
				$names[$name] = true;
				$this->payload($child, $depth + 1);
			}
		}else{
			throw new UnexpectedValueException("Unknown NBT tag type");
		}
	}
}
