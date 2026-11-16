<?php

declare(strict_types=1);

namespace Trix\WorldEdit\command;

use Trix\WorldEdit\Edits;
use quark\command\argument\EnumArgument;
use quark\command\argument\IntegerArgument;
use quark\player\Player;

final class ClipboardCommands{
	public function __construct(private readonly Edits $edits){}

	public function register(Commands $commands) : void{
		$commands->add('copy', 'clipboard', 'Copy the selection, relative to where you stand', fn(Player $player) => $this->edits->copy($player, false));
		$commands->add('cut', 'clipboard', 'Copy the selection, then clear it', fn(Player $player) => $this->edits->copy($player, true));
		$commands->add('paste', 'clipboard', 'Paste your clipboard (-a skips air, -o pastes where it was copied)', fn(Player $player, string $flags = '') => $this->edits->paste($player, str_contains($flags, 'a'), str_contains($flags, 'o')), [new EnumArgument('flags', ['-a', '-o', '-ao', '-oa'], true)]);
		$commands->add('rotate', 'clipboard', 'Rotate your clipboard clockwise around where you copied it', $this->rotate(...), [new IntegerArgument('degrees', -3600, 3600)]);
		$commands->add('flip', 'clipboard', 'Mirror your clipboard', $this->flip(...), [new EnumArgument('axis', ['x', 'y', 'z'])]);
		$commands->add('clearclipboard', 'clipboard', 'Release your clipboard', $this->clear(...));
	}

	private function rotate(Player $player, int $degrees) : void{
		$session = $this->edits->sessions->get($player);
		$this->edits->sessions->setClipboard($session, ($session->clipboard ?? throw new \RuntimeException('Your clipboard is empty'))->rotate($degrees));
		$player->sendMessage('Clipboard rotated.');
	}

	private function flip(Player $player, string $axis) : void{
		$session = $this->edits->sessions->get($player);
		$this->edits->sessions->setClipboard($session, ($session->clipboard ?? throw new \RuntimeException('Your clipboard is empty'))->flip($axis));
		$player->sendMessage('Clipboard mirrored.');
	}

	private function clear(Player $player) : void{
		$this->edits->sessions->setClipboard($this->edits->sessions->get($player), null);
		$player->sendMessage('Clipboard cleared.');
	}
}
