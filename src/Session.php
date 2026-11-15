<?php

declare(strict_types=1);

namespace asyncworldedit;

use asyncworldedit\clipboard\Clipboard;
use asyncworldedit\geometry\Region;
use asyncworldedit\pattern\Mask;
use asyncworldedit\pattern\Pattern;

final class Session{
	/** @var array{int,int,int}|null */
	public ?array $first = null;
	/** @var array{int,int,int}|null */
	public ?array $second = null;
	public ?string $worldId = null;
	public string $shape = 'cuboid';
	public ?Clipboard $clipboard = null;
	public ?Mask $mask = null;
	public int $seed = 0;
	public ?Pattern $brushPattern = null;
	public ?Mask $brushMask = null;
	public int $brushRange = 64;
	public string $brush = 'sphere';
	public int $brushSize = 3;
	public ?int $brushItem = null;
	public int $brushTick = -20;
	public bool $outline = true;
	public int $outlineTick = -20;
	public ?int $fileRequest = null;

	public function selection(string $worldId) : Region{
		if($worldId !== $this->worldId || $this->first === null || $this->second === null){
			throw new \RuntimeException('Select two positions in this world first');
		}
		return Region::between($this->first, $this->second, $this->shape);
	}

	public function setRegion(Region $region) : void{
		$this->first = [$region->minX, $region->minY, $region->minZ];
		$this->second = [$region->maxX, $region->maxY, $region->maxZ];
		$this->shape = $region->shape;
	}
}
