<?php

declare(strict_types=1);

namespace Trix\WorldEdit;

use Trix\WorldEdit\clipboard\Clipboard;
use Trix\WorldEdit\geometry\Region;
use Trix\WorldEdit\pattern\Mask;
use Trix\WorldEdit\pattern\Pattern;
use Vow\Promise;

final class Session{
	/** @var array{int, int, int}|null */
	public ?array $first = null;
	/** @var array{int, int, int}|null */
	public ?array $second = null;
	public ?string $worldId = null;
	public string $shape = 'cuboid';
	public bool $outline = true;

	public ?Clipboard $clipboard = null;
	/** A clipboard being captured; it counts against the memory limit until it replaces $clipboard. */
	public ?Clipboard $pending = null;
	public ?Mask $mask = null;
	public int $seed = 0;

	public string $brush = 'sphere';
	public ?Pattern $brushPattern = null;
	public ?Mask $brushMask = null;
	public int $brushSize = 3;
	public int $brushRange = 64;
	public ?int $brushItem = null;
	public int $brushTick = -20;

	/** @var Promise<mixed>|null the schematic load or save in progress */
	public ?Promise $file = null;

	public function selection(string $worldId) : Region{
		if($worldId !== $this->worldId || $this->first === null || $this->second === null){
			throw new \RuntimeException('Select two positions in this world first');
		}
		return Region::between($this->first, $this->second, $this->shape);
	}

	public function select(Region $region) : void{
		$this->first = [$region->minX, $region->minY, $region->minZ];
		$this->second = [$region->maxX, $region->maxY, $region->maxZ];
		$this->shape = $region->shape;
	}

	public function clearSelection() : void{
		$this->first = $this->second = null;
	}

	public function clipboardBytes() : int{
		return ($this->clipboard?->bytes() ?? 0) + ($this->pending !== null && $this->pending !== $this->clipboard ? $this->pending->bytes() : 0);
	}
}
