<?php

declare(strict_types=1);

namespace asyncworldedit\command;

use asyncworldedit\Main;
use quark\command\argument\Argument;
use quark\command\Command;
use quark\command\CommandArgs;
use quark\command\CommandSender;
use quark\player\Player;
use quark\plugin\Plugin;
use quark\plugin\PluginOwned;

final class EditCommand extends Command implements PluginOwned{
	/**
	 * @param list<Argument<mixed>> $arguments
	 * @param \Closure(Player,CommandArgs):void $handler
	 * @param list<string> $aliases
	 */
	public function __construct(private Main $plugin, string $name, string $description, string $permission, array $arguments, private \Closure $handler, array $aliases = []){
		parent::__construct('/' . $name, $description, aliases: $aliases);
		$this->setPermission('asyncworldedit.' . $permission);
		$this->registerArguments($arguments);
	}

	public function getOwningPlugin() : Plugin{
		return $this->plugin;
	}

	protected function run(CommandSender $sender, CommandArgs $args) : void{
		if(!$sender instanceof Player){
			$sender->sendMessage('Use this command in game.');
			return;
		}
		try{
			($this->handler)($sender, $args);
		}catch(\InvalidArgumentException|\RuntimeException $e){
			$sender->sendMessage('AsyncWorldEdit: ' . $e->getMessage());
		}catch(\Throwable $e){
			$this->plugin->getLogger()->logException($e);
			$sender->sendMessage('AsyncWorldEdit: operation failed; see the server log.');
		}
	}
}
