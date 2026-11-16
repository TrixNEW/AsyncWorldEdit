<?php

declare(strict_types=1);

namespace Trix\WorldEdit\command;

use Trix\WorldEdit\Edits;
use Trix\WorldEdit\pattern\Mask;
use Trix\WorldEdit\quark\Tools;
use quark\command\argument\EnumArgument;
use quark\command\argument\IntegerArgument;
use quark\command\argument\PositionArgument;
use quark\command\argument\RelativePosition;
use quark\player\Player;

final class SelectionCommands{
	public function __construct(private readonly Edits $edits){}

	public function register(Commands $commands) : void{
		$commands->add('wand', 'selection', 'Get a selection wand', $this->wand(...));
		foreach([1, 2] as $corner){
			$commands->add("pos$corner", 'selection', "Set selection position $corner", fn(Player $player, ?RelativePosition $position = null) => $this->edits->select($player, $corner, $position?->resolve($player->getPosition()) ?? $player->getPosition()), [new PositionArgument('position', true)]);
			$commands->add("hpos$corner", 'selection', "Set position $corner to the block you look at", fn(Player $player) => $this->edits->select($player, $corner, $player->getTargetBlock(128)?->getPosition() ?? throw new \RuntimeException('No block within 128 blocks')));
		}
		$commands->add('sel', 'selection', 'Choose a selection shape, or clear the selection', $this->shape(...), [new EnumArgument('shape', ['cuboid', 'ellipsoid', 'cylinder', 'clear'], true)]);
		$commands->add('outline', 'selection', 'Toggle selection outlines', $this->outline(...));
		foreach(['expand', 'contract', 'shift'] as $mode){
			$commands->add($mode, 'selection', ucfirst($mode) . ' the selection', fn(Player $player, int $amount, string $direction) => $this->resize($player, $mode, $amount, $direction), [new IntegerArgument('amount', 0, 30000000), $commands->direction()]);
		}
		$commands->add('size', 'selection', 'Show selection bounds and volume', $this->size(...));
		$commands->add('count', 'selection', 'Count selected blocks matching a mask', fn(Player $player, Mask $mask) => $this->edits->count($player, $mask, false), [$commands->mask()]);
		$commands->add('distr', 'selection', 'Show the most common selected blocks', fn(Player $player) => $this->edits->count($player, null, true));
	}

	private function wand(Player $player) : void{
		$player->getInventory()->addItem(Tools::wand());
		$player->sendMessage('Left click: position 1. Right click: position 2.');
	}

	private function shape(Player $player, string $shape = 'cuboid') : void{
		$session = $this->edits->sessions->get($player);
		if($shape === 'clear'){
			$session->clearSelection();
			$player->sendMessage('Selection cleared.');
		}else{
			$session->shape = $shape;
			$player->sendMessage("Selection shape: $shape");
		}
		$this->edits->refreshOutline($player);
	}

	private function outline(Player $player) : void{
		$session = $this->edits->sessions->get($player);
		$session->outline = !$session->outline;
		$this->edits->refreshOutline($player);
		$player->sendMessage('Selection outlines ' . ($session->outline ? 'enabled.' : 'disabled.'));
	}

	private function resize(Player $player, string $mode, int $amount, string $direction) : void{
		$region = $this->edits->selection($player);
		if($mode === 'shift'){
			[$dx, $dy, $dz] = Edits::direction($direction);
			$region = $region->shift($dx * $amount, $dy * $amount, $dz * $amount);
		}else{
			$region = $mode === 'expand' ? $region->expand($amount, $direction) : $region->contract($amount, $direction);
		}
		$this->edits->reselect($player, $region);
	}

	private function size(Player $player) : void{
		$region = $this->edits->selection($player);
		[$width, $height, $length] = $region->size();
		$player->sendMessage("$width × $height × $length; {$region->volume()} bounding blocks ($region->shape).");
	}
}
