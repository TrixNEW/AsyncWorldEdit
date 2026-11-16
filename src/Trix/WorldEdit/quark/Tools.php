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
use quark\item\VanillaItems;
use quark\player\Player;
use quark\math\Vector3;

final class Tools implements Listener{
	private const WAND_TAG = 'asyncworldedit:wand';

	public function __construct(private readonly Main $plugin){}

	public static function wand() : Item{
		$item = VanillaItems::WOODEN_AXE()->setCustomName('WorldEdit wand');
		$item->getNamedTag()->setByte(self::WAND_TAG, 1);
		return $item;
	}

	public static function isWand(Item $item) : bool{
		$tag = $item->getNamedTag()->getTag(self::WAND_TAG);
		return $tag instanceof ByteTag && $tag->getValue() === 1;
	}

	public function onInteract(PlayerInteractEvent $event) : void{
		$player = $event->getPlayer();
		$item = $event->getItem();
		if(self::isWand($item) && $player->hasPermission('asyncworldedit.selection')){
			$event->cancel();
			$this->select($player, $event->getAction() === PlayerInteractEvent::LEFT_CLICK_BLOCK ? 1 : 2, $event->getBlock()->getPosition());
		}elseif($event->getAction() === PlayerInteractEvent::RIGHT_CLICK_BLOCK && $this->isBrush($player, $item)){
			$event->cancel();
			$this->brush($player);
		}
	}

	public function onBreak(BlockBreakEvent $event) : void{
		if(self::isWand($event->getItem()) && $event->getPlayer()->hasPermission('asyncworldedit.selection')){
			$event->cancel();
			$this->select($event->getPlayer(), 1, $event->getBlock()->getPosition());
		}
	}

	public function onUse(PlayerItemUseEvent $event) : void{
		if($this->isBrush($event->getPlayer(), $event->getItem())){
			$event->cancel();
			$this->brush($event->getPlayer());
		}
	}

	public function onQuit(PlayerQuitEvent $event) : void{
		$this->plugin->disconnect($event->getPlayer());
	}

	/** @priority MONITOR */
	public function onWorldUnload(WorldUnloadEvent $event) : void{
		$this->plugin->worldUnloaded($event->getWorld());
	}

	public function onTeleport(EntityTeleportEvent $event) : void{
		$entity = $event->getEntity();
		if($entity instanceof Player){
			$this->plugin->outlines()->refreshLater($entity);
		}
	}

	private function isBrush(Player $player, Item $item) : bool{
		$session = $this->plugin->edits()->sessions->find($player);
		return $session !== null && $session->brushItem === $item->getTypeId() && $player->hasPermission('asyncworldedit.brush');
	}

	private function select(Player $player, int $corner, Vector3 $position) : void{
		try{
			$this->plugin->edits()->select($player, $corner, $position);
		}catch(\InvalidArgumentException $e){
			$player->sendMessage('AsyncWorldEdit: ' . $e->getMessage());
		}
	}

	private function brush(Player $player) : void{
		try{
			$this->plugin->edits()->brush($player);
		}catch(\InvalidArgumentException|\RuntimeException $e){
			$player->sendMessage('AsyncWorldEdit: ' . $e->getMessage());
		}
	}
}
