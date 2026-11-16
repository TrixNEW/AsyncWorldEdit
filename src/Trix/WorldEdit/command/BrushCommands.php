<?php

declare(strict_types=1);

namespace Trix\WorldEdit\command;

use Trix\WorldEdit\Edits;
use Trix\WorldEdit\pattern\Mask;
use Trix\WorldEdit\pattern\Pattern;
use Trix\WorldEdit\quark\Tools;
use quark\command\argument\IntegerArgument;
use quark\player\Player;

final class BrushCommands{
	public function __construct(private readonly Edits $edits){}

	public function register(Commands $commands) : void{
		$description = 'Bind a brush to the held item; right click to use it';
		foreach(['sphere', 'cylinder', 'overlay'] as $brush){
			$commands->add("brush $brush", 'brush', $description, fn(Player $player, Pattern $pattern, int $radius = 3) => $this->bind($player, $brush, $pattern, $radius), [$commands->pattern(), $this->radius()]);
		}
		$commands->add('brush smooth', 'brush', $description, fn(Player $player, int $radius = 3) => $this->bind($player, 'smooth', null, $radius), [$this->radius()]);
		$commands->add('brush clipboard', 'brush', $description, fn(Player $player) => $this->bind($player, 'clipboard', null, 0));
		$commands->add('brush none', 'brush', $description, $this->unbind(...));
		$commands->add('brush mask', 'brush', 'Set or clear your brush mask', $this->mask(...), [$commands->mask(optional: true)], 'mask');
		$commands->add('brush range', 'brush', 'Set how far brushes reach', $this->range(...), [new IntegerArgument('blocks', 1, 128)], 'range');
	}

	private function radius() : IntegerArgument{
		return new IntegerArgument('radius', 1, 32, true);
	}

	private function bind(Player $player, string $brush, ?Pattern $pattern, int $radius) : void{
		if($brush === 'clipboard' && !$player->hasPermission('asyncworldedit.clipboard')){
			throw new \RuntimeException('The clipboard brush needs clipboard permission');
		}
		$item = $player->getMainHandItem();
		if($item->isNull() || Tools::isWand($item)){
			throw new \InvalidArgumentException('Hold an item other than the wand');
		}
		$session = $this->edits->sessions->get($player);
		$session->brush = $brush;
		$session->brushPattern = $pattern;
		$session->brushSize = $radius;
		$session->brushItem = $item->getTypeId();
		$player->sendMessage("Bound a $brush brush. Right click to apply it.");
	}

	private function unbind(Player $player) : void{
		$this->edits->sessions->get($player)->brushItem = null;
		$player->sendMessage('Brush unbound.');
	}

	private function mask(Player $player, ?Mask $mask = null) : void{
		$this->edits->sessions->get($player)->brushMask = $mask;
		$player->sendMessage($mask === null ? 'Brush mask cleared.' : 'Brush mask updated.');
	}

	private function range(Player $player, int $blocks) : void{
		$this->edits->sessions->get($player)->brushRange = $blocks;
		$player->sendMessage('Brush range updated.');
	}
}
