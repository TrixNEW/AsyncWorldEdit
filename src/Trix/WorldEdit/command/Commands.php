<?php

declare(strict_types=1);

namespace Trix\WorldEdit\command;

use Trix\WorldEdit\Edits;
use Trix\WorldEdit\Schematics;
use Trix\WorldEdit\pattern\Mask;
use Trix\WorldEdit\pattern\Pattern;
use quark\command\argument\Argument;
use quark\command\argument\ArgumentParseException;
use quark\command\argument\EnumArgument;
use quark\command\argument\IntegerArgument;
use quark\command\argument\MappedArgument;
use quark\command\CommandSender;
use quark\plugin\PluginBase;
use quark\utils\TextFormat;

/**
 * Declares every subcommand once under //we and again as a familiar shortcut such as //set,
 * both as native syntaxes so clients see the same arguments and permissions.
 */
final class Commands{
	private const PERMISSION_PREFIX = 'asyncworldedit.';

	private WorldEditCommand $root;
	/** @var array<string, WorldEditCommand> */
	private array $shortcuts = [];
	/** @var array<string, array<string, true>> permissions used by each command */
	private array $permissions = [];

	public function __construct(private readonly PluginBase $plugin, private readonly Edits $edits){
		$this->root = new WorldEditCommand($plugin, '/we', 'AsyncWorldEdit commands; //we lists what you can use', ['/worldedit']);
		$this->root->syntax()->handler($this->help(...));
	}

	public static function register(PluginBase $plugin, Edits $edits, Schematics $schematics) : void{
		$commands = new self($plugin, $edits);
		(new SelectionCommands($edits))->register($commands);
		(new EditCommands($edits))->register($commands);
		(new ClipboardCommands($edits))->register($commands);
		(new HistoryCommands($edits, $schematics))->register($commands);
		(new BrushCommands($edits))->register($commands);
		(new SchematicCommands($schematics))->register($commands);
		$all = [$commands->root, ...array_values($commands->shortcuts)];
		foreach($all as $command){
			$command->setPermissions(array_keys($commands->permissions[$command->getName()] ?? []));
		}
		$plugin->getServer()->getCommandMap()->registerAll('asyncworldedit', $all);
	}

	/**
	 * Adds "//we $path". The shortcut defaults to "//$path"; pass false for none or another path.
	 *
	 * @param list<Argument<mixed>> $arguments
	 */
	public function add(string $path, string $permission, string $description, \Closure $handler, array $arguments = [], string|false|null $shortcut = null) : void{
		$permission = self::PERMISSION_PREFIX . $permission;
		$this->root->syntax($path)->registerArguments($arguments)->permission($permission)->handler($handler);
		$this->permissions[$this->root->getName()][$permission] = true;
		if($shortcut === false){
			return;
		}
		[$name, $rest] = explode(' ', $shortcut ?? $path, 2) + [1 => ''];
		$command = $this->shortcuts[$name] ??= new WorldEditCommand($this->plugin, '/' . $name, $description);
		$command->syntax($rest)->registerArguments($arguments)->permission($permission)->handler($handler);
		$this->permissions[$command->getName()][$permission] = true;
	}

	/** @return MappedArgument<Pattern> */
	public function pattern(string $name = 'pattern', bool $optional = false) : MappedArgument{
		$states = $this->edits->states;
		return new MappedArgument($name, static function(string $text) use ($states) : Pattern{
			try{
				return Pattern::parse($text, $states->resolve(...));
			}catch(\InvalidArgumentException $e){
				throw new ArgumentParseException($e->getMessage());
			}
		}, Pattern::class, $optional);
	}

	/** @return MappedArgument<Mask> */
	public function mask(string $name = 'mask', bool $optional = false) : MappedArgument{
		$states = $this->edits->states;
		return new MappedArgument($name, static function(string $text) use ($states) : Mask{
			try{
				return Mask::parse($text, $states->resolve(...), $states->solid(...), $states->air());
			}catch(\InvalidArgumentException $e){
				throw new ArgumentParseException($e->getMessage());
			}
		}, Mask::class, $optional);
	}

	public function direction() : EnumArgument{
		return new EnumArgument('direction', Edits::DIRECTIONS);
	}

	public function amount(int $max = 1024) : IntegerArgument{
		return new IntegerArgument('amount', 1, $max);
	}

	private function help(CommandSender $sender) : void{
		$usages = [];
		foreach($this->root->getSyntaxes() as $syntax){
			if($syntax->getPath() !== '' && $syntax->testPermissionSilent($sender)){
				$usages[] = $syntax->getUsage($this->root->getLabel());
			}
		}
		$sender->sendMessage(TextFormat::AQUA . 'AsyncWorldEdit' . TextFormat::GRAY . ' (shortcuts drop "we", as in //set):');
		$sender->sendMessage(TextFormat::GRAY . implode("\n", $usages));
	}
}
