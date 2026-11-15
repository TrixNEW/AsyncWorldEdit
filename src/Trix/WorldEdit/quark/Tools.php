<?php

declare(strict_types=1);

namespace Trix\WorldEdit\quark;

use Trix\WorldEdit\Main;
use pocketmine\nbt\tag\ByteTag;
use quark\event\block\BlockBreakEvent;
use quark\event\entity\EntityTeleportEvent;
use quark\event\Listener;
use quark\event\player\PlayerInteractEvent;
use quark\event\player\PlayerItemUseEvent;
use quark\event\player\PlayerQuitEvent;
use quark\event\world\WorldUnloadEvent;
use quark\item\Item;
use quark\player\Player;

final class Tools implements Listener{
	public function __construct(private Main $plugin){}

	public static function isWand(Item $item) : bool{
		$tag = $item->getNamedTag()->getTag('asyncworldedit:wand');
		return $tag instanceof ByteTag && $tag->getValue() === 1;
	}

	public function onInteract(PlayerInteractEvent $event) : void{
		$player = $event->getPlayer();
		$item = $event->getItem();
		if(self::isWand($item) && $player->hasPermission('asyncworldedit.selection')){
			$event->cancel();
			try{ $this->plugin->select($player, $event->getAction() === PlayerInteractEvent::LEFT_CLICK_BLOCK ? 1 : 2, $event->getBlock()->getPosition()); }
			catch(\InvalidArgumentException $error){ $player->sendMessage('AsyncWorldEdit: ' . $error->getMessage()); }
		}elseif($event->getAction() === PlayerInteractEvent::RIGHT_CLICK_BLOCK && $this->plugin->isBrush($player, $item)){
			$event->cancel();
			$this->plugin->useBrush($player);
		}
	}

	public function onBreak(BlockBreakEvent $event) : void{
		if(self::isWand($event->getItem()) && $event->getPlayer()->hasPermission('asyncworldedit.selection')){
			$event->cancel();
			try{ $this->plugin->select($event->getPlayer(), 1, $event->getBlock()->getPosition()); }
			catch(\InvalidArgumentException $error){ $event->getPlayer()->sendMessage('AsyncWorldEdit: ' . $error->getMessage()); }
		}
	}

	public function onUse(PlayerItemUseEvent $event) : void{
		if($this->plugin->isBrush($event->getPlayer(), $event->getItem())){
			$event->cancel();
			$this->plugin->useBrush($event->getPlayer());
		}
	}

	public function onQuit(PlayerQuitEvent $event) : void{
		$this->plugin->disconnect($event->getPlayer());
	}

	public function onWorldUnload(WorldUnloadEvent $event) : void{
		$this->plugin->worldUnloaded($event->getWorld());
	}

	public function onTeleport(EntityTeleportEvent $event) : void{
		$entity = $event->getEntity();
		if($entity instanceof Player){ $this->plugin->outlineAfterTeleport($entity); }
	}
}
