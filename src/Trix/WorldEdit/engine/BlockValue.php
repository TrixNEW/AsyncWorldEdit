<?php

declare(strict_types=1);

namespace Trix\WorldEdit\engine;

final readonly class BlockValue{
	public function __construct(public int $state, public ?string $nbt = null){
		if($state < 0 || $state > 0xffffffff || ($nbt !== null && strlen($nbt) > 65536)){
			throw new \InvalidArgumentException("Invalid block value");
		}
	}

	public function equals(self $other) : bool{
		return $this->state === $other->state && $this->nbt === $other->nbt;
	}
}
