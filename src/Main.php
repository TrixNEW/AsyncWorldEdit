<?php

declare(strict_types=1);

namespace asyncworldedit;

use asyncworldedit\clipboard\Clipboard;
use asyncworldedit\command\Commands;
use asyncworldedit\engine\BlockValue;
use asyncworldedit\engine\ClipboardOperations;
use asyncworldedit\engine\Editor;
use asyncworldedit\engine\Job;
use asyncworldedit\engine\Operations;
use asyncworldedit\engine\TerrainOperations;
use asyncworldedit\geometry\Region;
use asyncworldedit\pattern\Mask;
use asyncworldedit\pattern\Pattern;
use asyncworldedit\quark\BlockStates;
use asyncworldedit\quark\QuarkWorld;
use asyncworldedit\quark\SelectionOutline;
use asyncworldedit\quark\Tools;
use asyncworldedit\worker\SchematicService;
use quark\item\Item;
use quark\math\Vector3;
use quark\player\Player;
use quark\plugin\PluginBase;
use quark\scheduler\ClosureTask;
use quark\world\World;

final class Main extends PluginBase{

	private Editor $editor;
	private BlockStates $states;
	private SchematicService $schematics;
	/** @var array<string,Session> */
	private array $sessions = [];
	private int $outlineCursor = 0;

	protected function onEnable() : void{
		$this->editor = new Editor(Settings::fromArray($this->getConfig()->getAll()));
		$this->states = new BlockStates();
		$this->schematics = new SchematicService($this->getDataFolder() . 'schematics', $this->editor->settings->workers, $this->getServer()->getTickSleeper());
		(new Commands($this, $this->editor, $this->states, $this->schematics))->register();
		$this->getServer()->getPluginManager()->registerEvents(new Tools($this), $this);
		$this->getScheduler()->scheduleRepeatingTask(new ClosureTask(function() : void{
			$this->editor->tick();
			$this->schematics->tick();
			$this->outlines();
		}), 1);
	}

	protected function onDisable() : void{
		if(isset($this->editor)){
			$this->editor->shutdown();
		}
		if(isset($this->schematics)){
			$this->schematics->shutdown();
		}
		$this->sessions = [];
	}

	public function getEditor() : Editor{
		return $this->editor;
	}

	public function getBlockStates() : BlockStates{
		return $this->states;
	}

	public function world(World $world) : QuarkWorld{
		return new QuarkWorld($world);
	}

	public function session(Player $player) : Session{
		return $this->sessions[$player->getUniqueId()->toString()] ??= new Session();
	}

	public function setClipboard(Session $session, ?Clipboard $clipboard) : void{
		$bytes = $clipboard?->bytes() ?? 0;
		foreach($this->sessions as $other){
			if($other !== $session){
				$bytes += $other->clipboard?->bytes() ?? 0;
			}
		}
		if($bytes > $this->editor->settings->clipboardBytes){
			throw new \RuntimeException('Clipboard unchanged: global clipboard memory limit exceeded');
		}
		$session->clipboard = $clipboard;
	}

	public function select(Player $player, int $corner, Vector3 $position) : void{
		$point = [$position->getFloorX(), $position->getFloorY(), $position->getFloorZ()];
		Region::between($point, $point);
		if(!$player->getWorld()->isInWorld(...$point)){
			throw new \InvalidArgumentException('Selection is outside the world');
		}
		$session = $this->session($player);
		$worldId = $this->world($player->getWorld())->id();
		if($session->worldId !== $worldId){
			$session->first = $session->second = null;
		}
		$session->worldId = $worldId;
		if($corner === 1){
			$session->first = $point;
		}else{
			$session->second = $point;
		}
		$session->outlineTick = -20;
		$player->sendMessage('Position ' . $corner . ': ' . implode(', ', $point));
	}

	public function disconnect(Player $player) : void{
		$owner = $player->getUniqueId()->toString();
		$session = $this->sessions[$owner] ?? null;
		if($session?->fileRequest !== null){
			$this->schematics->cancel($session->fileRequest);
		}
		$this->editor->forget($owner);
		unset($this->sessions[$owner]);
	}

	public function worldUnloaded(World $world) : void{
		$worldId = $this->world($world)->id();
		$this->editor->cancelWorld($worldId);
		foreach($this->sessions as $session){
			if($session->worldId === $worldId){
				$session->first = $session->second = null;
			}
		}
	}

	public function isBrush(Player $player, Item $item) : bool{
		return $player->hasPermission('asyncworldedit.brush') && $this->session($player)->brushItem === $item->getTypeId();
	}

	public function useBrush(Player $player) : void{
		$session = $this->session($player);
		$tick = $this->getServer()->getTick();
		if($tick - $session->brushTick < 5 || $this->editor->active($player->getUniqueId()->toString()) !== null){
			return;
		}
		$session->brushTick = $tick;
		try{
			$target = $player->getTargetBlock($session->brushRange)?->getPosition() ?? throw new \RuntimeException('No block within brush range');
			if($session->brush === 'clipboard'){
				if(!$player->hasPermission('asyncworldedit.clipboard')){ throw new \RuntimeException('Clipboard permission is required'); }
				$clipboard = $session->clipboard ?? throw new \RuntimeException('Clipboard is empty');
				$pivot = [$target->getFloorX(), $target->getFloorY() + 1, $target->getFloorZ()];
				$world = $this->world($player->getWorld());
				$steps = ClipboardOperations::paste($world, $clipboard, $pivot, true, $this->states->air(), $this->states->transform(...), $session->mask);
				$this->submit($player, $world, self::masked($steps, $session->brushMask), $clipboard->volume(), $clipboard->bounds($pivot));
				return;
			}
			$radius = $session->brushSize;
			$region = new Region($target->getFloorX() - $radius, max($player->getWorld()->getMinY(), $target->getFloorY() - $radius), $target->getFloorZ() - $radius, $target->getFloorX() + $radius, min($player->getWorld()->getMaxY() - 1, $target->getFloorY() + $radius), $target->getFloorZ() + $radius, $session->brush === 'cylinder' ? 'cylinder' : ($session->brush === 'sphere' ? 'ellipsoid' : 'cuboid'));
			if($session->brush === 'smooth' || $session->brush === 'overlay'){
				$this->terrain($player, $region, $session->brush, $session->brushPattern, $session->brushMask);
			}else{
				$this->fill($player, $region, $session->brushPattern ?? throw new \RuntimeException('Configure a brush pattern first'), mask: $session->brushMask);
			}
		}catch(\Throwable $e){
			$player->sendMessage('AsyncWorldEdit: ' . $e->getMessage());
		}
	}

	private function outlines() : void{
		$players = array_values($this->getServer()->getOnlinePlayers());
		if($players === []){
			return;
		}
		$player = $players[$this->outlineCursor++ % count($players)];
		$session = $this->sessions[$player->getUniqueId()->toString()] ?? null;
		if($session === null || !$session->outline || !$player->hasPermission('asyncworldedit.selection') || $this->getServer()->getTick() - $session->outlineTick < 20){
			return;
		}
		$session->outlineTick = $this->getServer()->getTick();
		try{
			SelectionOutline::send($player, $session->selection($this->world($player->getWorld())->id()));
		}catch(\RuntimeException|\InvalidArgumentException){
		}
	}

	public function region(Player $player) : Region{
		$region = $this->session($player)->selection($this->world($player->getWorld())->id());
		$this->validateRegion($player, $region);
		return $region;
	}

	public function validateRegion(Player $player, Region $region) : void{
		if($region->volume() > $this->editor->settings->maxBlocks || $region->minY < $player->getWorld()->getMinY() || $region->maxY >= $player->getWorld()->getMaxY()){
			throw new \InvalidArgumentException('Selection exceeds operation or world height limits');
		}
	}

	public function fill(Player $player, Region $region, Pattern $pattern, string $mode = 'set', ?Mask $mask = null) : void{
		$world = $this->world($player->getWorld());
		$globalMask = $this->session($player)->mask;
		$steps = Operations::fill($world, $region, $pattern, $mask ?? $globalMask, $mode, $this->session($player)->seed);
		if($mask !== null && $globalMask !== null){
			$steps = (static function() use ($steps, $globalMask) : \Generator{
				foreach($steps as $step){
					yield $step !== null && $step[4] !== null && !$globalMask->matches($step[0], $step[1], $step[2], $step[4]->state) ? null : $step;
				}
			})();
		}
		$this->submit($player, $world, $steps, $region->volume(), $region);
	}

	public function terrain(Player $player, Region $region, string $mode, ?Pattern $pattern = null, ?Mask $toolMask = null) : void{
		$world = $this->world($player->getWorld());
		$air = $this->states->air();
		$mask = $this->session($player)->mask;
		$steps = match($mode){
			'overlay' => TerrainOperations::overlay($world, $region, $pattern ?? throw new \InvalidArgumentException('A pattern is required'), null, $air, $this->session($player)->seed),
			'naturalize' => TerrainOperations::naturalize($world, $region, $this->states->resolve('grass'), $this->states->resolve('dirt'), $this->states->resolve('stone'), $air),
			'smooth' => TerrainOperations::smooth($world, $region, $air),
			default => throw new \InvalidArgumentException('Unknown terrain operation'),
		};
		if($mode === 'smooth' && $region->volume() > $this->editor->settings->clipboardBlocks){
			throw new \InvalidArgumentException('Smoothing exceeds snapshot limit');
		}
		if($mask !== null){
			$steps = (static function() use ($steps, $mask) : \Generator{
				foreach($steps as $step){
					yield $step !== null && !$mask->matches($step[0], $step[1], $step[2], $step[4]->state) ? null : $step;
				}
			})();
		}
		$bounds = $mode === 'overlay' ? new Region($region->minX, $region->minY, $region->minZ, $region->maxX, min($player->getWorld()->getMaxY() - 1, $region->maxY + 1), $region->maxZ) : $region;
		$this->submit($player, $world, self::masked($steps, $toolMask), $region->volume() * 3, $bounds);
	}

	/**
	 * @param \Generator<int,array{int,int,int,BlockValue,?BlockValue}|null,void,void> $steps
	 * @return \Generator<int,array{int,int,int,BlockValue,?BlockValue}|null,void,void>
	 */
	private static function masked(\Generator $steps, ?Mask $mask) : \Generator{
		foreach($steps as $step){
			yield $step !== null && $mask !== null && $step[4] !== null && !$mask->matches($step[0], $step[1], $step[2], $step[4]->state) ? null : $step;
		}
	}

	/**
	 * @param \Generator<int,array{int,int,int,BlockValue,?BlockValue}|null,void,void> $steps
	 * @param \Closure(Job) : void|null $after
	 */
	public function submit(Player $player, QuarkWorld $world, \Generator $steps, int $limit, ?Region $bounds, ?\Closure $after = null) : Job{
		$completion = $this->completion($player);
		$job = $this->editor->submit($player->getUniqueId()->toString(), $world, $steps, $limit, function(Job $job) use ($completion, $after) : void{
			try{
				$after?->__invoke($job);
			}catch(\Throwable $e){
				$job->status = 'failed';
				$job->error = $e->getMessage();
			}
			$completion($job);
		}, bounds: $bounds);
		$player->sendMessage('Queued job ' . $job->id . '. Use //status or //cancel.');
		return $job;
	}

	/** @return \Closure(Job) : void */
	public function completion(Player $player) : \Closure{
		return static function(Job $job) use ($player) : void{
			if($player->isConnected()){
				$player->sendMessage("Job {$job->id} {$job->status}: {$job->changed} changes, {$job->conflicts} conflicts." . ($job->error === null ? '' : ' ' . $job->error));
			}
		};
	}
}
