<?php

declare(strict_types=1);

namespace Trix\WorldEdit\command;

use Trix\WorldEdit\Edits;
use Trix\WorldEdit\Schematics;
use Trix\WorldEdit\Sessions;
use quark\player\Player;

final class HistoryCommands{
	public function __construct(private readonly Edits $edits, private readonly Schematics $schematics){}

	public function register(Commands $commands) : void{
		$commands->add('undo', 'history', 'Undo your last edit in this world', fn(Player $player) => $this->edits->restore($player, false));
		$commands->add('redo', 'history', 'Redo your last undone edit in this world', fn(Player $player) => $this->edits->restore($player, true));
		$commands->add('cancel', 'edit', 'Stop your operation; applied changes stay undoable', $this->cancel(...));
		$commands->add('status', 'edit', 'Show the progress of your operation', $this->status(...));
	}

	private function cancel(Player $player) : void{
		$this->edits->editor->active(Sessions::owner($player))?->cancel();
		$this->schematics->cancel($this->edits->sessions->get($player));
		$player->sendMessage('Cancellation requested. Applied changes remain undoable.');
	}

	private function status(Player $player) : void{
		$job = $this->edits->editor->active(Sessions::owner($player));
		$player->sendMessage($job === null ? 'No active operation.' : "Job {$job->id}: {$job->visited}/{$job->limit} work units, {$job->changed} changes.");
	}
}
