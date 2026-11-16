<?php

declare(strict_types=1);

namespace Trix\WorldEdit;

use Trix\WorldEdit\clipboard\Clipboard;
use Trix\WorldEdit\engine\ClipboardOperations;
use Trix\WorldEdit\engine\Editor;
use Trix\WorldEdit\engine\Job;
use Trix\WorldEdit\engine\JobStatus;
use Trix\WorldEdit\engine\Operations;
use Trix\WorldEdit\engine\TerrainOperations;
use Trix\WorldEdit\engine\WorldAccess;
use Trix\WorldEdit\geometry\Region;
use Trix\WorldEdit\pattern\Mask;
use Trix\WorldEdit\pattern\Pattern;
use Trix\WorldEdit\quark\BlockStates;
use Trix\WorldEdit\quark\Outlines;
use Trix\WorldEdit\quark\QuarkWorld;
use quark\math\Vector3;
use quark\player\Player;

/** @phpstan-import-type Steps from Job */
final class Edits{
	public const DIRECTIONS = ['up', 'down', 'north', 'south', 'east', 'west'];
	public const SHAPES = ['sphere', 'hsphere', 'cyl', 'hcyl', 'cube', 'hcube', 'pyramid', 'hpyramid'];

	public function __construct(
		public readonly Editor $editor,
		public readonly Sessions $sessions,
		public readonly BlockStates $states,
		private readonly Outlines $outlines
	){}

	public function world(Player $player) : QuarkWorld{
		return new QuarkWorld($player->getWorld());
	}

	public function selection(Player $player) : Region{
		$region = $this->sessions->get($player)->selection(QuarkWorld::worldId($player->getWorld()));
		$this->checkRegion($player, $region);
		return $region;
	}

	public function checkRegion(Player $player, Region $region) : void{
		$world = $player->getWorld();
		if($region->volume() > $this->editor->settings->maxBlocks || $region->minY < $world->getMinY() || $region->maxY >= $world->getMaxY()){
			throw new \InvalidArgumentException('Selection exceeds the block limit or world height');
		}
	}

	public function select(Player $player, int $corner, Vector3 $position) : void{
		$point = [$position->getFloorX(), $position->getFloorY(), $position->getFloorZ()];
		if(!$player->getWorld()->isInWorld(...$point)){
			throw new \InvalidArgumentException('Selection is outside the world');
		}
		$session = $this->sessions->get($player);
		$worldId = QuarkWorld::worldId($player->getWorld());
		if($session->worldId !== $worldId){
			$session->clearSelection();
			$session->worldId = $worldId;
		}
		if($corner === 1){
			$session->first = $point;
		}else{
			$session->second = $point;
		}
		$this->outlines->refresh($player);
		$player->sendMessage("Position $corner: " . implode(', ', $point));
	}

	public function refreshOutline(Player $player) : void{
		$this->outlines->refresh($player);
	}

	public function reselect(Player $player, Region $region) : void{
		$this->checkRegion($player, $region);
		$this->sessions->get($player)->select($region);
		$this->outlines->refresh($player);
		$player->sendMessage('Selection updated: ' . $region->volume() . ' bounding blocks.');
	}

	public function fill(Player $player, Region $region, Pattern $pattern, string $mode = 'set', ?Mask $mask = null) : void{
		$session = $this->sessions->get($player);
		$world = $this->world($player);
		$this->submit($player, $world, Operations::fill($world, $region, $pattern, $mode, $session->seed), $region->volume(), $region, Mask::both($mask, $session->mask));
	}

	public function terrain(Player $player, Region $region, string $mode, ?Pattern $pattern = null, ?Mask $mask = null) : void{
		$session = $this->sessions->get($player);
		$world = $this->world($player);
		$air = $this->states->air();
		[$width, , $length] = $region->size();
		$columns = $width * $length;
		[$steps, $limit, $bounds] = match($mode){
			'overlay' => [
				TerrainOperations::overlay($world, $region, $pattern ?? throw new \InvalidArgumentException('Overlay needs a pattern'), $air, $session->seed),
				$region->volume() + $columns,
				new Region($region->minX, $region->minY, $region->minZ, $region->maxX, min($world->maxY() - 1, $region->maxY + 1), $region->maxZ)
			],
			'naturalize' => [
				TerrainOperations::naturalize($world, $region, $this->states->resolve('grass'), $this->states->resolve('dirt'), $this->states->resolve('stone'), $air),
				$region->volume(),
				$region
			],
			'smooth' => [
				TerrainOperations::smooth($world, $region, $air, $this->snapshotLimit($region)),
				2 * $region->volume() + $columns,
				$region
			],
			default => throw new \InvalidArgumentException("Unknown terrain operation $mode")
		};
		$this->submit($player, $world, $steps, $limit, $bounds, Mask::both($mask, $session->mask));
	}

	public function count(Player $player, ?Mask $mask, bool $distribution) : void{
		$region = $this->selection($player);
		$world = $this->world($player);
		$this->submit($player, $world, Operations::distribution($world, $region, $mask), $region->volume(), null, then:function(Job $job) use ($player, $distribution) : void{
			if($job->status !== JobStatus::Completed || !is_array($job->result) || !$player->isConnected()){
				return;
			}
			if(!$distribution){
				$player->sendMessage(array_sum($job->result) . ' matching blocks.');
				return;
			}
			foreach(array_slice($job->result, 0, 20, true) as $state => $count){
				$player->sendMessage($count . ' ' . $this->states->name($state));
			}
		});
	}

	public function copy(Player $player, bool $cut) : void{
		$region = $this->selection($player);
		$session = $this->sessions->get($player);
		$world = $this->world($player);
		$steps = ClipboardOperations::capture($world, $region, self::point($player->getPosition()), $this->snapshotLimit($region), $cut, $this->states->air(), $session->mask, fn(Clipboard $clipboard) => $this->sessions->hold($session, $clipboard));
		$this->submit($player, $world, $steps, $region->volume() * ($cut ? 2 : 1), $region, then: function(Job $job) use ($session) : void{
			try{
				// The clipboard is held before a cut removes anything, so keep it whenever blocks were removed.
				$clipboard = $job->result instanceof Clipboard ? $job->result : $session->pending;
				if($clipboard !== null && ($job->status === JobStatus::Completed || $job->status === JobStatus::Conflicted || $job->changed > 0)){
					$this->sessions->setClipboard($session, $clipboard);
				}
			}finally{
				$this->sessions->release($session);
			}
		});
	}

	public function paste(Player $player, bool $skipAir, bool $atOrigin) : void{
		$session = $this->sessions->get($player);
		$clipboard = $session->clipboard ?? throw new \RuntimeException('Your clipboard is empty');
		$pivot = $atOrigin ? $clipboard->origin : self::point($player->getPosition());
		$world = $this->world($player);
		$steps = ClipboardOperations::paste($world, $clipboard, $pivot, $skipAir, $this->states->air(), $this->states->transform(...));
		$this->submit($player, $world, $steps, $clipboard->volume(), $clipboard->bounds($pivot), $session->mask);
	}

	public function shift(Player $player, int $amount, string $direction, bool $stack, bool $skipAir) : void{
		$region = $this->selection($player);
		$limit = $this->snapshotLimit($region);
		[$dx, $dy, $dz] = self::direction($direction);
		if($stack){
			[$width, $height, $length] = $region->size();
			[$dx, $dy, $dz] = [$dx * $width, $dy * $height, $dz * $length];
		}
		$bounds = $region->union($region->shift($dx * $amount, $dy * $amount, $dz * $amount));
		$world = $this->world($player);
		$air = $this->states->air();
		$session = $this->sessions->get($player);
		$mask = $session->mask;
		// The intermediate clipboard counts against clipboard memory while the job runs.
		$hold = fn(Clipboard $clipboard) => $this->sessions->hold($session, $clipboard);
		$steps = (static function() use ($world, $region, $limit, $amount, $dx, $dy, $dz, $stack, $skipAir, $air, $mask, $hold) : \Generator{
			$clipboard = yield from ClipboardOperations::capture($world, $region, [0, 0, 0], $limit, !$stack, $air, $mask, $hold);
			for($i = $stack ? 1 : $amount; $i <= $amount; ++$i){
				yield from ClipboardOperations::paste($world, $clipboard, [$dx * $i, $dy * $i, $dz * $i], $skipAir, $air, source: $mask);
			}
		})();
		$this->submit($player, $world, $steps, $region->volume() * ($stack ? $amount + 1 : 3), $bounds, then: fn(Job $job) => $this->sessions->release($session));
	}

	public function generate(Player $player, string $shape, Pattern $pattern, int $radius, int $height = 1) : void{
		[$x, $y, $z] = self::point($player->getPosition());
		$hollow = $shape[0] === 'h';
		$session = $this->sessions->get($player);
		$world = $this->world($player);
		if(str_ends_with($shape, 'pyramid')){
			$region = new Region($x - $radius, $y, $z - $radius, $x + $radius, $y + $radius, $z + $radius);
			$this->submit($player, $world, Operations::pyramid($world, $x, $y, $z, $radius, $pattern, $hollow, $session->seed), $region->volume(), $region, $session->mask);
			return;
		}
		[$region, $mode] = match(ltrim($shape, 'h')){
			'sphere' => [new Region($x - $radius, $y - $radius, $z - $radius, $x + $radius, $y + $radius, $z + $radius, 'ellipsoid'), 'faces'],
			'cyl' => [new Region($x - $radius, $y, $z - $radius, $x + $radius, $y + $height - 1, $z + $radius, 'cylinder'), 'walls'],
			'cube' => [new Region($x - $radius, $y - $radius, $z - $radius, $x + $radius, $y + $radius, $z + $radius), 'faces'],
			default => throw new \InvalidArgumentException("Unknown shape $shape")
		};
		$this->fill($player, $region, $pattern, $hollow ? $mode : 'set');
	}

	public function restore(Player $player, bool $redo) : void{
		$this->editor->restore(Sessions::owner($player), $this->world($player), $redo, self::report($player));
	}

	public function brush(Player $player) : void{
		$session = $this->sessions->get($player);
		$tick = $player->getServer()->getTick();
		if($tick - $session->brushTick < 5 || $this->editor->active(Sessions::owner($player)) !== null){
			return;
		}
		$session->brushTick = $tick;
		$target = $player->getTargetBlock($session->brushRange)?->getPosition() ?? throw new \RuntimeException('No block within brush range');
		[$x, $y, $z] = self::point($target);
		if($session->brush === 'clipboard'){
			if(!$player->hasPermission('asyncworldedit.clipboard')){
				throw new \RuntimeException('The clipboard brush needs clipboard permission');
			}
			$clipboard = $session->clipboard ?? throw new \RuntimeException('Your clipboard is empty');
			$world = $this->world($player);
			$pivot = [$x, $y + 1, $z];
			$steps = ClipboardOperations::paste($world, $clipboard, $pivot, true, $this->states->air(), $this->states->transform(...));
			$this->submit($player, $world, $steps, $clipboard->volume(), $clipboard->bounds($pivot), Mask::both($session->brushMask, $session->mask));
			return;
		}
		$world = $player->getWorld();
		$size = $session->brushSize;
		$region = new Region($x - $size, max($world->getMinY(), $y - $size), $z - $size, $x + $size, min($world->getMaxY() - 1, $y + $size), $z + $size, match($session->brush){
			'sphere' => 'ellipsoid',
			'cylinder' => 'cylinder',
			default => 'cuboid'
		});
		if($session->brush === 'smooth' || $session->brush === 'overlay'){
			$this->terrain($player, $region, $session->brush, $session->brushPattern, $session->brushMask);
		}else{
			$this->fill($player, $region, $session->brushPattern ?? throw new \RuntimeException('Bind a brush pattern first'), mask: $session->brushMask);
		}
	}

	/**
	 * @param Steps $steps
	 * @param (\Closure(Job) : void)|null $then runs before the player is told the result; a throw fails the job
	 */
	public function submit(Player $player, WorldAccess $world, \Generator $steps, int $limit, ?Region $bounds, ?Mask $mask = null, ?\Closure $then = null) : Job{
		$report = self::report($player);
		$job = $this->editor->submit(Sessions::owner($player), $world, $steps, $limit, $bounds, $mask, static function(Job $job) use ($then, $report) : void{
			if($then !== null){
				try{
					$then($job);
				}catch(\Throwable $e){
					$job->fail($e->getMessage());
				}
			}
			$report($job);
		});
		$player->sendMessage("Queued job {$job->id}. Use //we status or //we cancel.");
		return $job;
	}

	/** @return \Closure(Job) : void */
	private static function report(Player $player) : \Closure{
		return static function(Job $job) use ($player) : void{
			if($player->isConnected()){
				$player->sendMessage("Job {$job->id} {$job->status->value}: {$job->changed} changes, {$job->conflicts} conflicts." . ($job->error === null ? '' : ' ' . $job->error));
			}
		};
	}

	private function snapshotLimit(Region $region) : int{
		$limit = $this->editor->settings->clipboardBlocks;
		if($region->volume() > $limit){
			throw new \InvalidArgumentException("Selection exceeds the $limit block clipboard limit");
		}
		return $limit;
	}

	/** @return array{int, int, int} */
	public static function point(Vector3 $position) : array{
		return [$position->getFloorX(), $position->getFloorY(), $position->getFloorZ()];
	}

	/** @return array{int, int, int} */
	public static function direction(string $direction) : array{
		return match($direction){
			'east' => [1, 0, 0],
			'west' => [-1, 0, 0],
			'up' => [0, 1, 0],
			'down' => [0, -1, 0],
			'south' => [0, 0, 1],
			'north' => [0, 0, -1],
			default => throw new \InvalidArgumentException("Unknown direction $direction")
		};
	}
}
