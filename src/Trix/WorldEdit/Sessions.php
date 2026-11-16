<?php

declare(strict_types=1);

namespace Trix\WorldEdit;

use Trix\WorldEdit\clipboard\Clipboard;
use quark\player\Player;

final class Sessions{
	/** @var array<string, Session> */
	private array $sessions = [];

	public function __construct(private readonly int $clipboardBytes){}

	public static function owner(Player $player) : string{
		return $player->getUniqueId()->toString();
	}

	public function get(Player $player) : Session{
		return $this->sessions[self::owner($player)] ??= new Session();
	}

	public function find(Player $player) : ?Session{
		return $this->sessions[self::owner($player)] ?? null;
	}

	public function remove(Player $player) : ?Session{
		$owner = self::owner($player);
		$session = $this->sessions[$owner] ?? null;
		unset($this->sessions[$owner]);
		return $session;
	}

	/** @return array<string, Session> */
	public function all() : array{
		return $this->sessions;
	}

	public function setClipboard(Session $session, ?Clipboard $clipboard) : void{
		if($session->pending !== null && $session->pending !== $clipboard){
			throw new \RuntimeException('Your clipboard is busy with a copy in progress');
		}
		if($clipboard !== null && $clipboard !== $session->pending){
			$this->reserve($clipboard->bytes() - ($session->clipboard?->bytes() ?? 0));
		}
		$session->clipboard = $clipboard;
		$session->pending = null;
	}

	/** Reserves memory for a clipboard that will replace the current one once its capture completes. */
	public function hold(Session $session, Clipboard $clipboard) : void{
		if($session->pending !== null){
			throw new \RuntimeException('Your clipboard is busy with a copy in progress');
		}
		$this->reserve($clipboard->bytes());
		$session->pending = $clipboard;
	}

	public function release(Session $session) : void{
		$session->pending = null;
	}

	private function reserve(int $bytes) : void{
		foreach($this->sessions as $session){
			$bytes += $session->clipboardBytes();
		}
		if($bytes > $this->clipboardBytes){
			throw new \RuntimeException('Clipboard unchanged: the shared clipboard memory limit is reached');
		}
	}
}
