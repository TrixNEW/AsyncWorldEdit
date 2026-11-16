<?php

declare(strict_types=1);

namespace Trix\WorldEdit\command;

use quark\command\Command;
use quark\command\CommandSender;
use quark\command\utils\CommandException;
use quark\plugin\Plugin;
use quark\plugin\PluginOwned;
use quark\utils\TextFormat;

/** A native syntax command whose handlers report expected failures to the sender. */
final class WorldEditCommand extends Command implements PluginOwned{
	/** @param list<string> $aliases */
	public function __construct(private readonly Plugin $plugin, string $name, string $description, array $aliases = []){
		parent::__construct($name, $description, aliases: $aliases);
	}

	public function getOwningPlugin() : Plugin{
		return $this->plugin;
	}

	public function dispatchLine(CommandSender $sender, string $commandLabel, array $args, ?string $commandLine = null){
		try{
			return parent::dispatchLine($sender, $commandLabel, $args, $commandLine);
		}catch(\Throwable $e){
			if($e instanceof CommandException){
				// Usage and parse errors are reported by the command map.
				throw $e;
			}
			if($e instanceof \InvalidArgumentException || $e instanceof \RuntimeException){
				$sender->sendMessage(TextFormat::RED . 'AsyncWorldEdit: ' . $e->getMessage());
			}else{
				$this->plugin->getLogger()->logException($e);
				$sender->sendMessage(TextFormat::RED . 'AsyncWorldEdit: the command failed; see the server log.');
			}
			return null;
		}
	}
}
