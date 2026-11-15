<?php

declare(strict_types=1);

namespace asyncworldedit\command;

use asyncworldedit\Main;
use asyncworldedit\clipboard\Clipboard;
use asyncworldedit\engine\BlockValue;
use asyncworldedit\engine\ClipboardOperations;
use asyncworldedit\engine\Editor;
use asyncworldedit\engine\Job;
use asyncworldedit\engine\Operations;
use asyncworldedit\geometry\Region;
use asyncworldedit\pattern\Mask;
use asyncworldedit\pattern\Pattern;
use asyncworldedit\quark\BlockStates;
use asyncworldedit\quark\Tools;
use asyncworldedit\worker\SchematicService;
use quark\command\argument\Argument;
use quark\command\argument\ArgumentParseException;
use quark\command\argument\EnumArgument;
use quark\command\argument\IntegerArgument;
use quark\command\argument\MappedArgument;
use quark\command\argument\PositionArgument;
use quark\command\argument\RelativePosition;
use quark\command\CommandArgs;
use quark\command\CommandSender;
use quark\item\VanillaItems;
use quark\math\Vector3;
use quark\player\Player;

final class Commands{

	public function __construct(
		private Main $plugin,
		private Editor $editor,
		private BlockStates $states,
		private SchematicService $schematics
	){
	}

	/** @return MappedArgument<Pattern> */
	private function patternArgument(string $name = 'pattern', bool $optional = false) : MappedArgument{
		return new MappedArgument($name, function(string $text, CommandSender $sender) : Pattern{
			try{
				return Pattern::parse($text, $this->states->resolve(...));
			}catch(\InvalidArgumentException $e){
				throw new ArgumentParseException($e->getMessage());
			}
		}, Pattern::class, $optional);
	}

	/** @return MappedArgument<Mask> */
	private function maskArgument(string $name = 'mask', bool $optional = false) : MappedArgument{
		return new MappedArgument($name, function(string $text, CommandSender $sender) : Mask{
			try{
				return Mask::parse($text, $this->states->resolve(...), $this->states->solid(...), $this->states->air());
			}catch(\InvalidArgumentException $e){
				throw new ArgumentParseException($e->getMessage());
			}
		}, Mask::class, $optional);
	}

	/**
	 * @param list<Argument<mixed>> $arguments
	 * @param \Closure(Player, CommandArgs) : void $handler
	 * @param list<string> $aliases
	 */
	private function command(string $name, string $description, string $permission, array $arguments, \Closure $handler, array $aliases = []) : void{
		$this->plugin->getServer()->getCommandMap()->register('asyncworldedit', new EditCommand($this->plugin, $name, $description, $permission, $arguments, $handler, $aliases));
	}

	public function register() : void{
		$this->command('seed', 'Set your deterministic pattern seed', 'edit', [new IntegerArgument('seed', min: -2147483648, max: 2147483647)], function(Player $player, CommandArgs $args) : void{
			$this->plugin->session($player)->seed = $args->int('seed');
			$player->sendMessage('Pattern seed updated.');
		});
		$this->command('wand', 'Get a selection wand', 'selection', [], function(Player $player, CommandArgs $args) : void{
			$item = VanillaItems::WOODEN_AXE()->setCustomName('WorldEdit wand');
			$item->getNamedTag()->setByte('asyncworldedit:wand', 1);
			$player->getInventory()->addItem($item);
			$player->sendMessage('Left click: position 1. Right click: position 2.');
		});
		foreach([1, 2] as $corner){
			$this->command('pos' . $corner, 'Set selection position ' . $corner, 'selection', [new PositionArgument('position', true)], function(Player $player, CommandArgs $args) use ($corner) : void{
				$position = $player->getPosition();
				if($args->has('position')){
					$value = $args->get('position');
					if($value instanceof RelativePosition){
						$position = $value->resolve($position);
					}
				}
				$this->plugin->select($player, $corner, $position);
			});
			$this->command('hpos' . $corner, 'Select the block in your crosshair', 'selection', [], function(Player $player, CommandArgs $args) use ($corner) : void{
				$this->plugin->select($player, $corner, $player->getTargetBlock(128)?->getPosition() ?? throw new \RuntimeException('No block within 128 blocks'));
			});
		}
		$this->command('sel', 'Choose a selector', 'selection', [new EnumArgument('shape', ['cuboid', 'ellipsoid', 'cylinder', 'clear'], true)], function(Player $player, CommandArgs $args) : void{
			$session = $this->plugin->session($player);
			$shape = $args->string('shape', 'cuboid');
			if($shape === 'clear'){
				$session->first = $session->second = null;
			}else{
				$session->shape = $shape;
			}
			$player->sendMessage('Selector: ' . $shape);
		});
		$this->command('outline', 'Toggle selection outlines', 'selection', [], function(Player $player, CommandArgs $args) : void{
			$session = $this->plugin->session($player);
			$session->outline = !$session->outline;
			$player->sendMessage('Selection outlines ' . ($session->outline ? 'enabled' : 'disabled'));
		});
		foreach(['expand', 'contract', 'shift'] as $mode){
			$this->command($mode, ucfirst($mode) . ' the selection', 'selection', [new IntegerArgument('amount', min: 0, max: 30000000), new EnumArgument('direction', ['up', 'down', 'north', 'south', 'east', 'west'])], function(Player $player, CommandArgs $args) use ($mode) : void{
				$region = $this->plugin->region($player);
				$amount = $args->int('amount');
				$direction = $args->string('direction');
				if($mode === 'shift'){
					[$deltaX, $deltaY, $deltaZ] = self::direction($direction);
					$region = $region->shift($deltaX * $amount, $deltaY * $amount, $deltaZ * $amount);
				}else{
					$region = $region->{$mode}($amount, $direction);
				}
				$this->plugin->validateRegion($player, $region);
				$this->plugin->session($player)->setRegion($region);
				$player->sendMessage('Selection updated: ' . $region->volume() . ' bounding blocks.');
			});
		}
		$this->command('size', 'Show selection bounds and volume', 'selection', [], function(Player $player, CommandArgs $args) : void{
			$region = $this->plugin->region($player);
			$player->sendMessage($region->maxX - $region->minX + 1 . ' × ' . ($region->maxY - $region->minY + 1) . ' × ' . ($region->maxZ - $region->minZ + 1) . '; ' . $region->volume() . ' bounding blocks (' . $region->shape . ').');
		});
		foreach(['set', 'walls', 'faces'] as $mode){
			$this->command($mode, ucfirst($mode) . ' selected blocks', 'edit', [$this->patternArgument()], function(Player $player, CommandArgs $args) use ($mode) : void{
				$pattern = $args->get('pattern');
				if(!$pattern instanceof Pattern){
					throw new \LogicException('Invalid parsed pattern');
				}
				$this->plugin->fill($player, $this->plugin->region($player), $pattern, $mode);
			});
		}
		$this->command('replace', 'Replace matching blocks', 'edit', [$this->maskArgument(), $this->patternArgument()], function(Player $player, CommandArgs $args) : void{
			$pattern = $args->get('pattern');
			$mask = $args->get('mask');
			if(!$pattern instanceof Pattern || !$mask instanceof Mask){
				throw new \LogicException('Invalid parsed pattern or mask');
			}
			$this->plugin->fill($player, $this->plugin->region($player), $pattern, mask: $mask);
		});
		$this->command('gmask', 'Set or clear your global mask', 'edit', [$this->maskArgument(optional: true)], function(Player $player, CommandArgs $args) : void{
			$mask = $args->has('mask') ? $args->get('mask') : null;
			if($mask !== null && !$mask instanceof Mask){
				throw new \LogicException('Invalid parsed mask');
			}
			$this->plugin->session($player)->mask = $mask;
			$player->sendMessage($mask === null ? 'Global mask cleared.' : 'Global mask updated.');
		});
		$this->command('hollow', 'Clear the interior, keeping a one-block shell', 'edit', [], function(Player $player, CommandArgs $args) : void{
			$this->plugin->fill($player, $this->plugin->region($player), Pattern::parse('air', $this->states->resolve(...)), 'hollow');
		});
		foreach(['overlay', 'naturalize', 'smooth'] as $mode){
			$this->command($mode, ucfirst($mode) . ' selected terrain', 'edit', $mode === 'overlay' ? [$this->patternArgument()] : [], function(Player $player, CommandArgs $args) use ($mode) : void{
				$pattern = $mode === 'overlay' ? $args->get('pattern') : null;
				if($pattern !== null && !$pattern instanceof Pattern){
					throw new \LogicException('Invalid parsed pattern');
				}
				$this->plugin->terrain($player, $this->plugin->region($player), $mode, $pattern);
			});
		}
		foreach(['count', 'distr'] as $mode){
			$this->command($mode, 'Count selected block states', 'selection', $mode === 'count' ? [$this->maskArgument()] : [], function(Player $player, CommandArgs $args) use ($mode) : void{
				$region = $this->plugin->region($player);
				$world = $this->plugin->world($player->getWorld());
				$mask = $mode === 'count' ? $args->get('mask') : null;
				if($mask !== null && !$mask instanceof Mask){
					throw new \LogicException('Invalid parsed mask');
				}
				$this->plugin->submit($player, $world, Operations::distribution($world, $region, function(array $counts) use ($player, $mode) : void{
					if(!$player->isConnected()){
						return;
					}
					if($mode === 'count'){
						$player->sendMessage(array_sum($counts) . ' matching blocks.');
						return;
					}
					foreach(array_slice($counts, 0, 20, true) as $state => $count){
						$player->sendMessage($count . ' ' . $this->states->name($state));
					}
				}, $mask), $region->volume(), $region);
			});
		}
		$this->clipboardCommands();
		$this->generationCommands();
		foreach(['undo', 'redo'] as $mode){
			$this->command($mode, ucfirst($mode) . ' your last edit', 'history', [], function(Player $player, CommandArgs $args) use ($mode) : void{
				$this->editor->restore($player->getUniqueId()->toString(), $this->plugin->world($player->getWorld()), $mode === 'redo', $this->plugin->completion($player));
			});
		}
		$this->command('cancel', 'Cancel your operation, retaining undo history', 'edit', [], function(Player $player, CommandArgs $args) : void{
			$this->editor->active($player->getUniqueId()->toString())?->cancel();
			$session = $this->plugin->session($player);
			if($session->fileRequest !== null){
				$this->schematics->cancel($session->fileRequest);
				$session->fileRequest = null;
			}
			$player->sendMessage('Cancellation requested. Applied changes remain undoable.');
		});
		$this->command('status', 'Show your current operation', 'edit', [], function(Player $player, CommandArgs $args) : void{
			$job = $this->editor->active($player->getUniqueId()->toString());
			$player->sendMessage($job === null ? 'No active edit.' : "Job {$job->id}: {$job->visited}/{$job->limit} work units, {$job->changed} changes.");
		});
		$this->command('mask', 'Set or clear your brush mask', 'brush', [$this->maskArgument(optional: true)], function(Player $player, CommandArgs $args) : void{
			$mask = $args->has('mask') ? $args->get('mask') : null;
			if($mask !== null && !$mask instanceof Mask){ throw new \InvalidArgumentException('Invalid brush mask'); }
			$this->plugin->session($player)->brushMask = $mask;
			$player->sendMessage($mask === null ? 'Brush mask cleared.' : 'Brush mask updated.');
		});
		$this->command('range', 'Set your brush targeting range', 'brush', [new IntegerArgument('blocks', min: 1, max: 128)], function(Player $player, CommandArgs $args) : void{
			$this->plugin->session($player)->brushRange = $args->int('blocks');
			$player->sendMessage('Brush range updated.');
		});
		$this->command('brush', 'Bind a brush to your held item', 'brush', [new EnumArgument('brush', ['sphere', 'cylinder', 'overlay', 'smooth', 'clipboard', 'none']), $this->patternArgument(optional: true), new IntegerArgument('radius', min: 1, max: 32, optional: true)], function(Player $player, CommandArgs $args) : void{
			$session = $this->plugin->session($player);
			$brush = $args->string('brush');
			if($brush === 'clipboard' && !$player->hasPermission('asyncworldedit.clipboard')){
				throw new \RuntimeException('Clipboard permission is required');
			}
			if($brush === 'none'){
				$session->brushItem = null;
				$player->sendMessage('Brush binding cleared.');
				return;
			}
			$pattern = $args->has('pattern') ? $args->get('pattern') : null;
			if($pattern !== null && !$pattern instanceof Pattern){
				throw new \InvalidArgumentException('Invalid brush pattern');
			}
			if($brush !== 'smooth' && $brush !== 'clipboard' && $pattern === null){
				throw new \InvalidArgumentException('This brush requires a pattern');
			}
			$item = $player->getMainHandItem();
			if($item->isNull() || Tools::isWand($item)){
				throw new \InvalidArgumentException('Hold a non-wand item');
			}
			$session->brush = $brush;
			$session->brushSize = $args->int('radius', 3);
			$session->brushPattern = $pattern;
			$session->brushItem = $item->getTypeId();
			$player->sendMessage('Brush binding updated. Right click to apply.');
		});
		(new SchematicCommands($this->plugin, $this->editor, $this->states, $this->schematics))->register();
	}

	private function clipboardCommands() : void{
		foreach(['copy', 'cut'] as $mode){
			$this->command($mode, ucfirst($mode) . ' to your clipboard', 'clipboard', [], function(Player $player, CommandArgs $args) use ($mode) : void{
				$region = $this->plugin->region($player);
				$world = $this->plugin->world($player->getWorld());
				$session = $this->plugin->session($player);
				$pivot = self::point($player->getPosition());
				$candidate = null;
				$steps = ClipboardOperations::capture($world, $region, $pivot, $this->editor->settings->clipboardBlocks, static function(Clipboard $clipboard) use (&$candidate) : void{
					$candidate = $clipboard;
				}, $mode === 'cut', $this->states->air());
				$this->plugin->submit($player, $world, $steps, $region->volume() * ($mode === 'cut' ? 2 : 1), $region, function(Job $job) use ($session, &$candidate) : void{
					if($job->status === 'completed' && $job->conflicts === 0 && $candidate !== null){
						$this->plugin->setClipboard($session, $candidate);
					}
				});
			});
		}
		$this->command('paste', 'Paste clipboard (-a skips air, -o uses original position)', 'clipboard', [new EnumArgument('flags', ['-a', '-o', '-ao', '-oa'], true)], function(Player $player, CommandArgs $args) : void{
			$clipboard = $this->plugin->session($player)->clipboard ?? throw new \RuntimeException('Clipboard is empty');
			$flags = $args->string('flags', '');
			$pivot = str_contains($flags, 'o') ? $clipboard->origin : self::point($player->getPosition());
			$world = $this->plugin->world($player->getWorld());
			$region = $clipboard->bounds($pivot);
			$this->plugin->submit($player, $world, ClipboardOperations::paste($world, $clipboard, $pivot, str_contains($flags, 'a'), $this->states->air(), $this->states->transform(...), $this->plugin->session($player)->mask), $clipboard->volume(), $region);
		});
		$this->command('rotate', 'Rotate your clipboard clockwise around its origin', 'clipboard', [new IntegerArgument('degrees', min: -3600, max: 3600)], function(Player $player, CommandArgs $args) : void{
			$session = $this->plugin->session($player);
			$this->plugin->setClipboard($session, ($session->clipboard ?? throw new \RuntimeException('Clipboard is empty'))->rotate($args->int('degrees')));
			$player->sendMessage('Clipboard rotated.');
		});
		$this->command('flip', 'Mirror your clipboard', 'clipboard', [new EnumArgument('axis', ['x', 'y', 'z'])], function(Player $player, CommandArgs $args) : void{
			$session = $this->plugin->session($player);
			$this->plugin->setClipboard($session, ($session->clipboard ?? throw new \RuntimeException('Clipboard is empty'))->flip($args->string('axis')));
			$player->sendMessage('Clipboard mirrored.');
		});
		$this->command('clearclipboard', 'Release your clipboard', 'clipboard', [], function(Player $player, CommandArgs $args) : void{
			$this->plugin->setClipboard($this->plugin->session($player), null);
			$player->sendMessage('Clipboard cleared.');
		});
		foreach(['move', 'stack'] as $mode){
			$this->command($mode, ucfirst($mode) . ' selected blocks', 'edit', [new IntegerArgument('amount', min: 1, max: 1024), new EnumArgument('direction', ['up', 'down', 'north', 'south', 'east', 'west']), new EnumArgument('flags', ['-a'], true)], function(Player $player, CommandArgs $args) use ($mode) : void{
				$region = $this->plugin->region($player);
				$world = $this->plugin->world($player->getWorld());
				$amount = $args->int('amount');
				[$deltaX, $deltaY, $deltaZ] = self::direction($args->string('direction'));
				if($mode === 'stack'){
					$deltaX *= $region->maxX - $region->minX + 1;
					$deltaY *= $region->maxY - $region->minY + 1;
					$deltaZ *= $region->maxZ - $region->minZ + 1;
				}
				$end = $region->shift($deltaX * $amount, $deltaY * $amount, $deltaZ * $amount);
				$bounds = new Region(min($region->minX, $end->minX), min($region->minY, $end->minY), min($region->minZ, $end->minZ), max($region->maxX, $end->maxX), max($region->maxY, $end->maxY), max($region->maxZ, $end->maxZ));
				$ignoreAir = $args->string('flags', '') === '-a';
				$mask = $this->plugin->session($player)->mask;
				$steps = (function() use ($world, $region, $amount, $deltaX, $deltaY, $deltaZ, $mode, $ignoreAir, $mask) : \Generator{
					$clipboard = null;
					yield from ClipboardOperations::capture($world, $region, [0, 0, 0], $this->editor->settings->clipboardBlocks, static function(Clipboard $capturedClipboard) use (&$clipboard) : void{
						$clipboard = $capturedClipboard;
					}, $mode === 'move', $this->states->air());
					if(!$clipboard instanceof Clipboard){
						throw new \LogicException('Clipboard capture did not complete');
					}
					for($i = $mode === 'move' ? $amount : 1; $i <= $amount; ++$i){
						yield from ClipboardOperations::paste($world, $clipboard, [$deltaX * $i, $deltaY * $i, $deltaZ * $i], $ignoreAir, $this->states->air(), $this->states->transform(...), $mask);
					}
				})();
				$this->plugin->submit($player, $world, $steps, $region->volume() * ($mode === 'move' ? 3 : $amount + 1), $bounds);
			});
		}
	}

	private function generationCommands() : void{
		foreach(['sphere', 'hsphere', 'cyl', 'hcyl', 'cube', 'hcube', 'pyramid', 'hpyramid'] as $name){
			$args = [$this->patternArgument(), new IntegerArgument('radius', min: 1, max: 100)];
			if($name === 'cyl' || $name === 'hcyl'){
				$args[] = new IntegerArgument('height', min: 1, max: 256, optional: true);
			}
			$this->command($name, 'Generate a ' . $name, 'edit', $args, function(Player $player, CommandArgs $args) use ($name) : void{
				[$x, $y, $z] = self::point($player->getPosition());
				$radius = $args->int('radius');
				$pattern = $args->get('pattern');
				if(!$pattern instanceof Pattern){
					throw new \LogicException('Invalid parsed pattern');
				}
				$isCylinder = str_contains($name, 'cyl');
				$pyramid = str_contains($name, 'pyramid');
				$bottom = $isCylinder || $pyramid ? $y : $y - $radius;
				$top = $isCylinder ? $y + $args->int('height', 1) - 1 : $y + $radius;
				$shape = str_contains($name, 'sphere') ? 'ellipsoid' : ($isCylinder ? 'cylinder' : 'cuboid');
				$region = new Region($x - $radius, $bottom, $z - $radius, $x + $radius, $top, $z + $radius, $shape);
				if(!$pyramid){
					$mode = !str_starts_with($name, 'h') ? 'set' : ($isCylinder ? 'walls' : 'faces');
					$this->plugin->fill($player, $region, $pattern, $mode);
					return;
				}
				$world = $this->plugin->world($player->getWorld());
				$mask = $this->plugin->session($player)->mask;
				$seed = $this->plugin->session($player)->seed;
				$steps = (function() use ($region, $world, $pattern, $mask, $seed, $x, $y, $z, $radius, $name) : \Generator{
					foreach($region->positions() as [$blockX, $blockY, $blockZ]){
						$edge = $radius - ($blockY - $y);
						$distance = max(abs($blockX - $x), abs($blockZ - $z));
						if($distance > $edge || $name === 'hpyramid' && $blockY !== $y && $distance !== $edge){
							yield null;
							continue;
						}
						$world->acquire($blockX >> 4, $blockZ >> 4);
						$before = $world->read($blockX, $blockY, $blockZ);
						if($mask !== null && !$mask->matches($blockX, $blockY, $blockZ, $before->state)){
							yield null;
							continue;
						}
						$state = $pattern->choose($blockX, $blockY, $blockZ, $before->state, $seed);
						yield $state === $before->state ? null : [$blockX, $blockY, $blockZ, new BlockValue($state), $before];
					}
				})();
				$this->plugin->submit($player, $world, $steps, $region->volume(), $region);
			});
		}
	}

	/** @return array{int,int,int} */
	private static function point(Vector3 $player) : array{
		return [$player->getFloorX(), $player->getFloorY(), $player->getFloorZ()];
	}

	/** @return array{int,int,int} */
	private static function direction(string $direction) : array{
		return match($direction){
			'east' => [1, 0, 0],
			'west' => [-1, 0, 0],
			'up' => [0, 1, 0],
			'down' => [0, -1, 0],
			'south' => [0, 0, 1],
			'north' => [0, 0, -1],
			default => throw new \InvalidArgumentException('Unknown direction'),
		};
	}
}
