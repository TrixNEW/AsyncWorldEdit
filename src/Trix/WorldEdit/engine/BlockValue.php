<?php

declare(strict_types=1);

namespace Trix\WorldEdit\engine;

final class BlockValue{
	private const SHARED_LIMIT = 4096;

	/** @var array<int, self> */
	private static array $shared = [];

	public function __construct(public readonly int $state, public readonly ?string $nbt = null){
		if($state < 0 || $state > 0xffffffff || ($nbt !== null && ($nbt === '' || strlen($nbt) > 65536))){
			throw new \InvalidArgumentException('Invalid block value');
		}
	}

	/** Returns a shared instance for a state without block entity data. */
	public static function of(int $state) : self{
		if(isset(self::$shared[$state])){
			return self::$shared[$state];
		}
		$value = new self($state);
		if(count(self::$shared) < self::SHARED_LIMIT){
			self::$shared[$state] = $value;
		}
		return $value;
	}

	public function equals(self $other) : bool{
		return $this === $other || ($this->state === $other->state && $this->nbt === $other->nbt);
	}
}
