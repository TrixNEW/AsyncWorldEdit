<?php

declare(strict_types=1);

namespace Trix\WorldEdit\command;

use Trix\WorldEdit\Schematics;
use quark\command\argument\StringArgument;
use quark\player\Player;

final class SchematicCommands{
	public function __construct(private readonly Schematics $schematics){}

	public function register(Commands $commands) : void{
		$description = 'Load a schematic into your clipboard, or save your clipboard';
		$commands->add('schem load', 'schematic', $description, fn(Player $player, string $file) => $this->schematics->load($player, $file), [new StringArgument('file')]);
		$commands->add('schem save', 'schematic', $description, fn(Player $player, string $file) => $this->schematics->save($player, $file), [new StringArgument('file')]);
	}
}
