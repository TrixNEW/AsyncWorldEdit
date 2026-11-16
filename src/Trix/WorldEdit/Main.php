<?php

declare(strict_types=1);

namespace Trix\WorldEdit;

use Trix\WorldEdit\command\Commands;
use Trix\WorldEdit\engine\Editor;
use Trix\WorldEdit\quark\BlockStates;
use Trix\WorldEdit\quark\Outlines;
use Trix\WorldEdit\quark\QuarkWorld;
use Trix\WorldEdit\quark\Tools;
use Trix\WorldEdit\worker\SchematicService;
use quark\player\Player;
use quark\plugin\PluginBase;
use quark\scheduler\ClosureTask;
use quark\scheduler\TaskHandler;
use quark\utils\Filesystem;
use quark\world\World;

final class Main extends PluginBase{
	private Editor $editor;
	private SchematicService $files;
	private Outlines $outlines;
	private Edits $edits;
	private Schematics $schematics;
	/** @var TaskHandler<ClosureTask>|null runs the editor every tick while jobs exist */
	private ?TaskHandler $editTask = null;
	/** @var TaskHandler<ClosureTask>|null checks for crashed workers while requests are pending */
	private ?TaskHandler $workerTask = null;

	protected function onEnable() : void{
		$settings = $this->loadSettings();
		$sessions = new Sessions($settings->clipboardBytes);
		$this->outlines = new Outlines($this->getScheduler(), $sessions);
		$this->editor = new Editor($settings, $this->editActivity(...));
		$this->files = new SchematicService($this->getDataFolder() . 'schematics', $settings->workers, $settings->clipboardBlocks, $this->getServer()->getTickSleeper(), $this->workerActivity(...));
		$this->edits = new Edits($this->editor, $sessions, new BlockStates(), $this->outlines);
		$this->schematics = new Schematics($this->files, $this->edits);
		Commands::register($this, $this->edits, $this->schematics);
		$this->getServer()->getPluginManager()->registerEvents(new Tools($this), $this);
	}

	protected function onDisable() : void{
		if(isset($this->editor)){
			$this->outlines->close();
			$this->editor->shutdown();
			$this->files->shutdown();
		}
	}

	public function editor() : Editor{
		return $this->editor;
	}

	public function edits() : Edits{
		return $this->edits;
	}

	public function outlines() : Outlines{
		return $this->outlines;
	}

	public function disconnect(Player $player) : void{
		$session = $this->edits->sessions->remove($player);
		if($session !== null){
			$this->schematics->cancel($session);
		}
		$this->editor->forget(Sessions::owner($player));
		$this->outlines->forget($player);
	}

	public function worldUnloaded(World $world) : void{
		$worldId = QuarkWorld::worldId($world);
		$this->editor->cancelWorld($worldId);
		foreach($this->edits->sessions->all() as $session){
			if($session->worldId === $worldId){
				$session->clearSelection();
			}
		}
		$this->outlines->refreshAll();
		// Keep history if a later check stops the unload.
		$this->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use ($world, $worldId) : void{
			if(!$world->isLoaded()){
				$this->editor->forgetWorld($worldId);
			}
		}), 1);
	}

	private function loadSettings() : Settings{
		$config = $this->getConfig();
		$migrated = Settings::migrate($config->getAll(), Filesystem::fileGetContents($this->getResourcePath('config.toml')));
		if($migrated !== null){
			$path = $config->getPath();
			$original = Filesystem::fileGetContents($path);
			Filesystem::atomicWrite($path . '.bak', $original);
			Filesystem::atomicWrite($path, $migrated, $original);
			$this->reloadConfig();
			$this->getLogger()->notice('Moved config.toml settings into sections and kept the old file as config.toml.bak. blocksPerTick is gone: budget-micros alone limits each tick.');
		}
		return Settings::fromConfig($this->getConfig()->getAll());
	}

	private function editActivity(bool $active) : void{
		if(!$active){
			if($this->editTask !== null){
				$this->getScheduler()->cancelTask($this->editTask);
				$this->editTask = null;
			}
		}elseif($this->editTask === null){
			$this->editTask = $this->getScheduler()->scheduleRepeatingTask(new ClosureTask(function() : void{ $this->editor->tick(); }), 1);
		}
	}

	private function workerActivity(bool $active) : void{
		if(!$active){
			if($this->workerTask !== null){
				$this->getScheduler()->cancelTask($this->workerTask);
				$this->workerTask = null;
			}
		}elseif($this->workerTask === null){
			$this->workerTask = $this->getScheduler()->scheduleDelayedRepeatingTask(new ClosureTask($this->files->tick(...)), 20, 20);
		}
	}
}
