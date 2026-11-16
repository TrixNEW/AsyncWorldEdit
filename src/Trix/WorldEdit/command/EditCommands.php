<?php

declare(strict_types=1);

namespace Trix\WorldEdit\command;

use Trix\WorldEdit\Edits;
use Trix\WorldEdit\pattern\Mask;
use Trix\WorldEdit\pattern\Pattern;
use quark\command\argument\EnumArgument;
use quark\command\argument\IntegerArgument;
use quark\player\Player;

final class EditCommands{
	public function __construct(private readonly Edits $edits){}

	public function register(Commands $commands) : void{
		foreach(['set' => 'Fill the selection', 'walls' => 'Fill the sides of the selection', 'faces' => 'Fill the outer faces of the selection'] as $mode => $description){
			$commands->add($mode, 'edit', $description, fn(Player $player, Pattern $pattern) => $this->edits->fill($player, $this->edits->selection($player), $pattern, $mode), [$commands->pattern()]);
		}
		$commands->add('replace', 'edit', 'Replace blocks matching a mask', fn(Player $player, Mask $mask, Pattern $pattern) => $this->edits->fill($player, $this->edits->selection($player), $pattern, mask: $mask), [$commands->mask(), $commands->pattern()]);
		$commands->add('hollow', 'edit', 'Clear the inside, keeping a one-block shell', fn(Player $player) => $this->edits->fill($player, $this->edits->selection($player), Pattern::parse('air', $this->edits->states->resolve(...)), 'hollow'));
		$commands->add('overlay', 'edit', 'Cover the top of each column', fn(Player $player, Pattern $pattern) => $this->edits->terrain($player, $this->edits->selection($player), 'overlay', $pattern), [$commands->pattern()]);
		foreach(['naturalize' => 'Turn terrain into grass, dirt and stone', 'smooth' => 'Smooth the terrain surface'] as $mode => $description){
			$commands->add($mode, 'edit', $description, fn(Player $player) => $this->edits->terrain($player, $this->edits->selection($player), $mode));
		}
		foreach(Edits::SHAPES as $shape){
			$description = ($shape[0] === 'h' ? 'Generate a hollow ' : 'Generate a ') . ltrim($shape, 'h') . ' at your feet';
			$arguments = [$commands->pattern(), new IntegerArgument('radius', 1, 100)];
			if(str_ends_with($shape, 'cyl')){
				$commands->add($shape, 'edit', $description, fn(Player $player, Pattern $pattern, int $radius, int $height = 1) => $this->edits->generate($player, $shape, $pattern, $radius, $height), [...$arguments, new IntegerArgument('height', 1, 256, true)]);
			}else{
				$commands->add($shape, 'edit', $description, fn(Player $player, Pattern $pattern, int $radius) => $this->edits->generate($player, $shape, $pattern, $radius), $arguments);
			}
		}
		foreach(['move' => 'Move the selection', 'stack' => 'Repeat the selection'] as $mode => $description){
			$commands->add($mode, 'edit', "$description (-a skips air)", fn(Player $player, int $amount, string $direction, string $flags = '') => $this->edits->shift($player, $amount, $direction, $mode === 'stack', $flags === '-a'), [$commands->amount(), $commands->direction(), new EnumArgument('flags', ['-a'], true)]);
		}
		$commands->add('gmask', 'edit', 'Set or clear the mask applied to your edits', $this->globalMask(...), [$commands->mask(optional: true)]);
		$commands->add('seed', 'edit', 'Set the seed for random patterns', $this->seed(...), [new IntegerArgument('seed', -2147483648, 2147483647)]);
	}

	private function globalMask(Player $player, ?Mask $mask = null) : void{
		$this->edits->sessions->get($player)->mask = $mask;
		$player->sendMessage($mask === null ? 'Global mask cleared.' : 'Global mask updated.');
	}

	private function seed(Player $player, int $seed) : void{
		$this->edits->sessions->get($player)->seed = $seed;
		$player->sendMessage('Pattern seed updated.');
	}
}
