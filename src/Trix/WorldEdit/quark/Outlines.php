<?php

declare(strict_types=1);

namespace Trix\WorldEdit\quark;

use Trix\WorldEdit\Sessions;
use Trix\WorldEdit\geometry\Region;
use quark\color\Color;
use quark\math\Vector3;
use quark\player\Player;
use quark\scheduler\ClosureTask;
use quark\scheduler\TaskHandler;
use quark\scheduler\TaskScheduler;
use quark\world\particle\DustParticle;

/** Draws selection outlines, one viewer per run so a full cycle takes about a second. Idle with no viewers. */
final class Outlines{
	private const RANGE = 96;

	/** @var array<string, Player> */
	private array $viewers = [];
	/** @var TaskHandler<ClosureTask>|null */
	private ?TaskHandler $task = null;
	private bool $closed = false;

	public function __construct(private readonly TaskScheduler $scheduler, private readonly Sessions $sessions){}

	public function refresh(Player $player) : void{
		$owner = Sessions::owner($player);
		$session = $this->sessions->find($player);
		if(!$this->closed && $player->isConnected() && $session !== null && $session->outline && $session->first !== null && $session->second !== null &&
			$session->worldId === QuarkWorld::worldId($player->getWorld()) && $player->hasPermission('asyncworldedit.selection')){
			$this->viewers[$owner] = $player;
		}else{
			unset($this->viewers[$owner]);
		}
		$this->schedule();
	}

	public function refreshAll() : void{
		foreach($this->viewers as $player){
			$this->refresh($player);
		}
	}

	/** Teleport events fire before the move, so check the new world a tick later. */
	public function refreshLater(Player $player) : void{
		if(!$this->closed && ($this->sessions->find($player)->outline ?? false)){
			$this->scheduler->scheduleDelayedTask(new ClosureTask(function() use ($player) : void{ $this->refresh($player); }), 1);
		}
	}

	public function forget(Player $player) : void{
		unset($this->viewers[Sessions::owner($player)]);
		$this->schedule();
	}

	public function close() : void{
		$this->closed = true;
		$this->viewers = [];
		$this->schedule();
	}

	private function schedule() : void{
		$count = count($this->viewers);
		$period = $count === 0 ? 0 : max(1, (int) ceil(20 / $count));
		if($this->task !== null && $this->task->getPeriod() !== $period){
			$this->scheduler->cancelTask($this->task);
			$this->task = null;
		}
		if($period > 0 && $this->task === null){
			$this->task = $this->scheduler->scheduleDelayedRepeatingTask(new ClosureTask($this->draw(...)), $period, $period);
		}
	}

	private function draw() : void{
		$player = current($this->viewers);
		if($player === false){
			$player = reset($this->viewers);
			if($player === false){
				return;
			}
		}
		next($this->viewers);
		$this->refresh($player);
		$session = $this->sessions->find($player);
		if($session === null || !isset($this->viewers[Sessions::owner($player)])){
			return;
		}
		$region = $session->selection(QuarkWorld::worldId($player->getWorld()));
		$particle = new DustParticle(new Color(40, 220, 230));
		$position = $player->getPosition();
		foreach(self::points($region) as [$x, $y, $z]){
			if(($position->x - $x) ** 2 + ($position->y - $y) ** 2 + ($position->z - $z) ** 2 <= self::RANGE ** 2){
				$position->getWorld()->addParticle(new Vector3($x, $y, $z), $particle, [$player]);
			}
		}
	}

	/** @return \Generator<int, array{float, float, float}, void, void> */
	public static function points(Region $region) : \Generator{
		$lo = [(float) $region->minX, (float) $region->minY, (float) $region->minZ];
		$hi = [$region->maxX + 1.0, $region->maxY + 1.0, $region->maxZ + 1.0];
		if($region->shape === 'cuboid'){
			for($axis = 0; $axis < 3; ++$axis){
				$a = ($axis + 1) % 3;
				$b = ($axis + 2) % 3;
				for($edge = 0; $edge < 4; ++$edge){
					for($i = 0; $i < 6; ++$i){
						$point = $lo;
						$point[$a] = ($edge & 1) === 0 ? $lo[$a] : $hi[$a];
						$point[$b] = ($edge & 2) === 0 ? $lo[$b] : $hi[$b];
						$point[$axis] += ($hi[$axis] - $lo[$axis]) * $i / 5;
						yield $point;
					}
				}
			}
			return;
		}
		for($ring = 0; $ring < 3; ++$ring){
			for($i = 0; $i < 24; ++$i){
				$angle = $i * M_PI / 12;
				$point = [($lo[0] + $hi[0]) / 2, ($lo[1] + $hi[1]) / 2, ($lo[2] + $hi[2]) / 2];
				if($region->shape === 'cylinder'){
					$point[0] += cos($angle) * ($hi[0] - $lo[0]) / 2;
					$point[2] += sin($angle) * ($hi[2] - $lo[2]) / 2;
					$point[1] = $lo[1] + ($hi[1] - $lo[1]) * $ring / 2;
				}else{
					$a = ($ring + 1) % 3;
					$b = ($ring + 2) % 3;
					$point[$a] += cos($angle) * ($hi[$a] - $lo[$a]) / 2;
					$point[$b] += sin($angle) * ($hi[$b] - $lo[$b]) / 2;
				}
				yield $point;
			}
		}
	}
}
