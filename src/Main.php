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
use quark\scheduler\TaskHandler;
use quark\world\World;

final class Main extends PluginBase{

	private Editor $editor;
	private BlockStates $states;
	private SchematicService $schematics;
	/** @var array<string,Session> */
	private array $sessions = [];
	/** @var TaskHandler<ClosureTask>|null */
	private ?TaskHandler $editTask = null;
	/** @var TaskHandler<ClosureTask>|null */
	private ?TaskHandler $workerTask = null;
	/** @var TaskHandler<ClosureTask>|null */
	private ?TaskHandler $outlineTask = null;
	/** @var array<string,Player> */
	private array $outlineViewers = [];
	/** @var array<int,Clipboard> */
	private array $clipboardReservations = [];
	private bool $closing = false;

	protected function onEnable() : void{
		$this->closing = false;
		$this->editor = new Editor(Settings::fromArray($this->getConfig()->getAll()), $this->editActivity(...));
		$this->states = new BlockStates();
		$this->schematics = new SchematicService($this->getDataFolder() . 'schematics', $this->editor->settings->workers, $this->getServer()->getTickSleeper(), $this->workerActivity(...));
		(new Commands($this, $this->editor, $this->states, $this->schematics))->register();
		$this->getServer()->getPluginManager()->registerEvents(new Tools($this), $this);
	}

	private function editActivity(bool $active) : void{
		if(!$active || $this->closing){
			if($this->editTask !== null){ $this->getScheduler()->cancelTask($this->editTask); $this->editTask = null; }
		}elseif($this->editTask === null){
			$this->editTask = $this->getScheduler()->scheduleRepeatingTask(new ClosureTask($this->editor->tick(...)), 1);
		}
	}

	private function workerActivity(bool $active) : void{
		if(!$active || $this->closing){
			if($this->workerTask !== null){ $this->getScheduler()->cancelTask($this->workerTask); $this->workerTask = null; }
		}elseif($this->workerTask === null){
			$this->workerTask = $this->getScheduler()->scheduleDelayedRepeatingTask(new ClosureTask($this->schematics->tick(...)), 20, 20);
		}
	}

	protected function onDisable() : void{
		$this->closing = true;
		$this->editActivity(false);
		$this->workerActivity(false);
		if($this->outlineTask !== null){ $this->getScheduler()->cancelTask($this->outlineTask); $this->outlineTask = null; }
		if(isset($this->editor)){
			$this->editor->shutdown();
		}
		if(isset($this->schematics)){
			$this->schematics->shutdown();
		}
		$this->sessions = [];
		$this->outlineViewers = [];
		$this->clipboardReservations = [];
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
		$reserved = $this->clipboardReservations[spl_object_id($session)] ?? null;
		if($reserved !== null && $reserved !== $clipboard){ throw new \RuntimeException('Clipboard is busy'); }
		$this->checkClipboardCapacity($session, $clipboard);
		$session->clipboard = $clipboard;
	}

	private function checkClipboardCapacity(Session $session, ?Clipboard $clipboard) : void{
		$bytes = $clipboard?->bytes() ?? 0;
		foreach($this->sessions as $other){
			if($other !== $session){
				$current = $other->clipboard?->bytes() ?? 0;
				$reserved = $this->clipboardReservations[spl_object_id($other)] ?? null;
				$bytes += max($current, $reserved?->bytes() ?? 0);
			}
		}
		if($bytes > $this->editor->settings->clipboardBytes){
			throw new \RuntimeException('Clipboard unchanged: global clipboard memory limit exceeded');
		}
	}

	public function reserveClipboard(Session $session, Clipboard $clipboard) : void{
		$this->checkClipboardCapacity($session, $clipboard);
		$this->clipboardReservations[spl_object_id($session)] = $clipboard;
	}

	public function releaseClipboard(Session $session) : void{
		unset($this->clipboardReservations[spl_object_id($session)]);
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
		$this->refreshOutline($player);
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
		if($session !== null){ $this->releaseClipboard($session); }
		unset($this->outlineViewers[$owner]);
		$this->scheduleOutlines();
	}

	public function worldUnloaded(World $world) : void{
		$worldId = $this->world($world)->id();
		$this->editor->cancelWorld($worldId);
		foreach($this->sessions as $session){
			if($session->worldId === $worldId){
				$session->first = $session->second = null;
			}
		}
		foreach($this->outlineViewers as $player){ $this->refreshOutline($player); }
		if(!$this->closing){
			$this->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use ($world, $worldId) : void{
				if(!$world->isLoaded()){ $this->editor->forgetWorld($worldId); }
			}), 1);
		}
	}

	public function isBrush(Player $player, Item $item) : bool{
		$session = $this->sessions[$player->getUniqueId()->toString()] ?? null;
		return $session !== null && $player->hasPermission('asyncworldedit.brush') && $session->brushItem === $item->getTypeId();
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

	public function refreshOutline(Player $player) : void{
		$owner = $player->getUniqueId()->toString();
		$session = $this->sessions[$owner] ?? null;
		if(!$this->closing && $player->isConnected() && $session !== null && $session->outline && $session->first !== null && $session->second !== null && $session->worldId === $this->world($player->getWorld())->id() && $player->hasPermission('asyncworldedit.selection')){
			$this->outlineViewers[$owner] = $player;
		}else{ unset($this->outlineViewers[$owner]); }
		$this->scheduleOutlines();
	}

	public function outlineAfterTeleport(Player $player) : void{
		$session = $this->sessions[$player->getUniqueId()->toString()] ?? null;
		if($this->closing || $session === null || !$session->outline || $session->second === null){ return; }
		$this->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use ($player) : void{ $this->refreshOutline($player); }), 1);
	}

	private function scheduleOutlines() : void{
		$count = count($this->outlineViewers);
		$period = $count === 0 ? 0 : max(1, (int) ceil(20 / $count));
		if($this->outlineTask !== null && $this->outlineTask->getPeriod() !== $period){
			$this->getScheduler()->cancelTask($this->outlineTask);
			$this->outlineTask = null;
		}
		if($period > 0 && $this->outlineTask === null){
			$this->outlineTask = $this->getScheduler()->scheduleDelayedRepeatingTask(new ClosureTask($this->outlines(...)), $period, $period);
		}
	}

	private function outlines() : void{
		$player = current($this->outlineViewers);
		if($player === false){ $player = reset($this->outlineViewers); }
		if($player === false){ return; }
		next($this->outlineViewers);
		$this->refreshOutline($player);
		$owner = $player->getUniqueId()->toString();
		if(!isset($this->outlineViewers[$owner])){ return; }
		try{
			SelectionOutline::send($player, $this->sessions[$owner]->selection($this->world($player->getWorld())->id()));
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
			'smooth' => TerrainOperations::smooth($world, $region, $air, $this->editor->settings->clipboardBlocks),
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
