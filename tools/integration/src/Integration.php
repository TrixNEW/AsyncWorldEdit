<?php

declare(strict_types=1);

namespace aweintegration;

use Trix\WorldEdit\Main;
use Trix\WorldEdit\clipboard\Clipboard;
use Trix\WorldEdit\engine\BlockValue;
use Trix\WorldEdit\engine\ClipboardOperations;
use Trix\WorldEdit\engine\Editor;
use Trix\WorldEdit\engine\Job;
use Trix\WorldEdit\engine\JobStatus;
use Trix\WorldEdit\engine\Operations;
use Trix\WorldEdit\geometry\Region;
use Trix\WorldEdit\pattern\Pattern;
use Trix\WorldEdit\quark\Outlines;
use Trix\WorldEdit\quark\QuarkWorld;
use Trix\WorldEdit\schematic\SchematicCodec;
use Trix\WorldEdit\schematic\SchematicData;
use quark\block\VanillaBlocks;
use quark\block\tile\Sign;
use quark\block\utils\SignText;
use quark\command\argument\MappedArgument;
use quark\plugin\PluginBase;
use quark\scheduler\ClosureTask;
use quark\world\ChunkLoader;
use quark\world\ChunkTicker;
use quark\world\World;
use quark\world\format\SubChunk;
use quark\world\format\io\GlobalBlockStateHandlers;
use pocketmine\world\format\PalettedBlockArray;

final class Integration extends PluginBase{
	private Main $target;
	private World $world;
	private QuarkWorld $access;
	private Editor $editor;
	private ?\Generator $suite = null;
	private ?\Closure $waiting = null;
	private float $deadline = 0;
	private bool $finished = false;
	private ?ChunkTicker $ticker = null;
	/** @var list<array{ChunkLoader, int, int}> */
	private array $pins = [];
	/** @var array<string, int> */
	private array $loaderCounts = [];
	/** @var array<string, bool> */
	private array $checks = [];
	/** @var list<array<string, int|float>> */
	private array $benchmarks = [];
	/** @var list<int> */
	private array $tickSamples = [];
	private bool $sampling = false;
	private float $started;
	private int $stepTimeout = 30;
	private string $phase = "startup";

	protected function onEnable() : void{
		$this->started = microtime(true);
		$this->getScheduler()->scheduleDelayedTask(new ClosureTask(function() : void{
			try{
				$plugin = $this->getServer()->getPluginManager()->getPlugin("AsyncWorldEdit");
				if(!$plugin instanceof Main || !$plugin->isEnabled()){
					throw new \RuntimeException("AsyncWorldEdit did not enable");
				}
				$this->target = $plugin;
				$this->editor = $plugin->editor();
				$this->world = $this->getServer()->getWorldManager()->getDefaultWorld() ?? throw new \RuntimeException("No default world");
				$this->access = new QuarkWorld($this->world);
				$this->suite = $this->run();
				$this->getScheduler()->scheduleRepeatingTask(new ClosureTask($this->advance(...)), 1);
			}catch(\Throwable $error){
				$this->finish($error);
			}
		}), 2);
	}

	private function advance() : void{
		if($this->finished){ return; }
		try{
			$suite = $this->suite ?? throw new \LogicException("Integration suite was not initialized");
			if($this->sampling){ $this->tickSamples[] = $this->editor->lastTickNanos; }
			if($this->waiting !== null){
				if(!($this->waiting)()){
					if(microtime(true) > $this->deadline){ throw new \RuntimeException("Integration step exceeded " . $this->stepTimeout . " seconds: " . $this->phase); }
					return;
				}
				$this->waiting = null;
				$suite->next();
			}
			if(!$suite->valid()){
				$this->finish();
				return;
			}
			$this->waiting = $suite->current();
			$this->deadline = microtime(true) + $this->stepTimeout;
		}catch(\Throwable $error){
			$this->finish($error);
		}
	}

	/** @phpstan-assert true $condition */
	private function check(bool $condition, string $name) : void{
		$this->checks[$name] = $condition;
		if(!$condition){ throw new \RuntimeException($name); }
		$this->getLogger()->info("PASS: " . $name);
		$this->checkpoint();
	}

	private function fill(Region $region, string $name = "stone", string $owner = "integration") : Job{
		$pattern = Pattern::parse($name, $this->target->edits()->states->resolve(...));
		return $this->editor->submit($owner, $this->access, Operations::fill($this->access, $region, $pattern), $region->volume(), bounds: $region);
	}

	private function await(Job $job) : \Closure{
		return static function() use ($job) : bool{
			if(!$job->isFinished()){ return false; }
			if($job->status !== JobStatus::Completed){ throw new \RuntimeException("Job {$job->id}: {$job->status->value}: {$job->error}"); }
			return true;
		};
	}

	private function snapshot(Region $region) : string{
		$packed = "";
		foreach($region->positions() as [$x, $y, $z]){
			$this->access->acquire($x >> 4, $z >> 4);
			$block = $this->access->read($x, $y, $z);
			$packed .= pack("V", $block->state) . pack("V", strlen($block->nbt ?? "")) . ($block->nbt ?? "");
		}
		$this->access->release();
		return hash("sha256", $packed);
	}

	private function run() : \Generator{
		$command = $this->getServer()->getCommandMap()->getCommand("/set");
		$this->check($command !== null, "Double-slash set command mapping exists");
		$arguments = $command->getSyntaxes()[0]->getArguments();
		$this->check(count($arguments) === 1 && $arguments[0] instanceof MappedArgument, "Set command uses a typed pattern argument");
		$this->check($this->target->getDescription()->getCompatibleApis() === ["6.0.0"], "Manifest declares Quark API 6");
		$this->commandChecks();
		foreach(['editTask', 'workerTask'] as $property){
			$this->check((new \ReflectionProperty(Main::class, $property))->getValue($this->target) === null, 'Idle plugin has no ' . $property);
		}
		$this->check((new \ReflectionProperty(Outlines::class, 'task'))->getValue($this->target->outlines()) === null, 'Idle plugin has no outline task');
		$service = (new \ReflectionProperty(Main::class, 'files'))->getValue($this->target);
		$workerError = null;
		$service->load('missing-audit-file.schem')->observe(null, static function(\Throwable $error) use (&$workerError) : void{ $workerError = $error->getMessage(); });
		$this->check((new \ReflectionProperty(Main::class,'workerTask'))->getValue($this->target) !== null, 'Pending schematic request wakes its watchdog');
		yield static function() use (&$workerError) : bool{ return $workerError !== null; };
		$this->check((new \ReflectionProperty(Main::class,'workerTask'))->getValue($this->target) === null && !$service->hasPending(), 'Schematic completion stops its watchdog');

		$pending = 0;
		$populationError = null;
		for($x = -2; $x <= 1; ++$x){
			for($z = -2; $z <= 1; ++$z){
				$loader = new ChunkLoader();
				$this->world->registerChunkLoader($loader, $x, $z, true);
				$this->pins[] = [$loader, $x, $z];
				++$pending;
				$this->world->requestChunkPopulation($x, $z, $loader)->observe(
					static function() use (&$pending) : void{ --$pending; },
					static function() use (&$populationError) : void{ $populationError = "Chunk population failed"; }
				);
			}
		}
		yield static function() use (&$pending, &$populationError) : bool{
			if($populationError !== null){ throw new \RuntimeException($populationError); }
			return $pending === 0;
		};
		$this->check($pending === 0, "Sixteen real chunks populated and pinned");
		$this->ticker = new ChunkTicker();
		for($x = -1; $x <= 0; ++$x){
			for($z = -1; $z <= 0; ++$z){ $this->world->registerTickingChunk($this->ticker, $x, $z); }
		}
		yield function() : bool{
			foreach($this->pins as [, $x, $z]){
				if($this->world->getChunk($x, $z)?->isLightPopulated() !== true){ return false; }
			}
			return true;
		};
		$this->check($this->world->getChunk(0, 0)?->isLightPopulated() === true, "Real chunk ticking completes initial light population");
		foreach($this->pins as [, $x, $z]){
			$this->loaderCounts["$x:$z"] = count($this->world->getChunkLoaders($x, $z));
		}
		$region = new Region(-17, 100, -17, 16, 101, 16);
		$before = $this->snapshot($region);
		$job = $this->fill($region);
		yield $this->await($job);
		$this->check($job->changed === $region->volume(), "Fill crosses negative chunk boundaries");
		$after = $this->snapshot($region);
		$undo = $this->editor->restore("integration", $this->access);
		yield $this->await($undo);
		$this->check($this->snapshot($region) === $before, "Undo restores every original runtime state");
		$redo = $this->editor->restore("integration", $this->access, true);
		yield $this->await($redo);
		$this->check($this->snapshot($region) === $after, "Redo restores every edited runtime state");
		$noop = $this->fill($region);
		yield $this->await($noop);
		$this->check($noop->changed === 0, "Unchanged blocks create no history writes");

		foreach([$this->world->getMinY(), $this->world->getMaxY() - 1] as $y){
			$edge = new Region(0, $y, 0, 0, $y, 0);
			$original = $this->snapshot($edge);
			$job = $this->fill($edge, "glowstone");
			yield $this->await($job);
			$undo = $this->editor->restore("integration", $this->access);
			yield $this->await($undo);
			$this->check($this->snapshot($edge) === $original, "World height boundary " . $y . " round-trips");
		}

		$cancelRegion = new Region(-16, 110, -16, 15, 113, 15);
		$job = $this->fill($cancelRegion, "dirt", "cancel");
		yield fn() : bool => $job->changed > 0;
		$job->cancel();
		yield static fn() : bool => $job->isFinished();
		$this->check($job->status === JobStatus::Cancelled && $job->changed > 0 && $job->changed < $cancelRegion->volume(), "Cancellation preserves partial undoable work");
		$undo = $this->editor->restore("cancel", $this->access);
		yield $this->await($undo);
		$retry = $this->fill($cancelRegion, "dirt", "cancel");
		yield $this->await($retry);
		$this->check($retry->changed === $cancelRegion->volume(), "Cancelled owner can retry after cleanup");

		$a = $this->fill(new Region(-16, 120, -16, -1, 120, -1), "stone", "client-a");
		$b = $this->fill(new Region(0, 120, 0, 15, 120, 15), "stone", "client-b");
		$rejected = false;
		try{ $this->fill(new Region(-10, 120, -10, -5, 120, -5), "dirt", "client-c"); }
		catch(\RuntimeException){ $rejected = true; }
		$this->check($rejected, "Overlapping owners are rejected");
		yield $this->await($a);
		yield $this->await($b);
		$this->check($a->changed === 256 && $b->changed === 256, "Disjoint owners complete on the shared scheduler");

		yield from $this->tilesAndFluids();
		yield from $this->schematicRoundTrip();
		$light = $this->fill(new Region(5, 150, 5, 5, 150, 5), "glowstone");
		yield $this->await($light);
		$settle = $this->getServer()->getTick() + 10;
		yield fn() : bool => $this->getServer()->getTick() >= $settle;
		$this->check($this->world->getBlockLightAt(6, 150, 5) > 0, "Ordinary light propagation settles after edits");

		$this->stepTimeout = 300;
		$workloads = match(true){
			getenv("AWE_FUNCTIONAL_ONLY") === "1" => [],
			getenv("AWE_ONLY_MILLION") === "1" => [[64, 256, 64]],
			default => [[8, 8, 8], [32, 16, 32], [32, 32, 32]]
		};
		foreach($workloads as [$width, $height, $length]){
			$million = $width === 64;
			$minX = $million ? -32 : -16;
			$minY = $million ? 64 : 160;
			$box = new Region($minX, $minY, $minX, $minX + $width - 1, $minY + $height - 1, $minX + $length - 1);
			for($repeat = 0, $repeats = $million ? 1 : 3; $repeat < $repeats; ++$repeat){
				$this->phase = "Benchmark " . $box->volume() . " repeat " . ($repeat + 1);
				$baselineBlock = VanillaBlocks::STONE();
				$asyncName = "dirt";
				$cpuStart = $this->cpuNanos();
				$start = hrtime(true);
				foreach($box->positions() as [$x, $y, $z]){ $this->world->setBlockAt($x, $y, $z, $baselineBlock, true); }
				$baseline = hrtime(true) - $start;
				$baselineCpu = $this->cpuNanos() - $cpuStart;
				$settle = $this->getServer()->getTick() + 2;
				yield fn() : bool => $this->getServer()->getTick() >= $settle;
				$this->tickSamples = [];
				$this->sampling = true;
				$cpuStart = $this->cpuNanos();
				$start = hrtime(true);
				$job = $this->fill($box, $asyncName, "benchmark");
				yield $this->await($job);
				$elapsed = hrtime(true) - $start;
				$processCpu = $this->cpuNanos() - $cpuStart;
				$this->sampling = false;
				$this->check($job->changed === $box->volume(), "Benchmark " . $box->volume() . " repeat " . ($repeat + 1) . " changes all cells");
				$samples = $this->tickSamples;
				$this->benchmarks[] = [
					"blocks" => $box->volume(), "repeat" => $repeat + 1, "ordinary_set_ns" => $baseline,
					"ordinary_process_cpu_ns" => $baselineCpu, "scheduled_process_cpu_ns" => $processCpu,
					"scheduled_wall_ns" => $elapsed, "editor_total_ns" => array_sum($samples),
					"editor_max_tick_ns" => $samples === [] ? 0 : max($samples), "editor_ticks" => count($samples),
					"tps_average" => $this->getServer()->getTicksPerSecondAverage(), "php_peak_bytes" => memory_get_peak_usage(true)
				];
				$this->tickSamples = [];
				$this->sampling = true;
				$start = hrtime(true);
				$undo = $this->editor->restore("benchmark", $this->access);
				yield $this->await($undo);
				$this->sampling = false;
				$this->check($undo->changed === $box->volume(), "Benchmark " . $box->volume() . " repeat " . ($repeat + 1) . " undoes all cells");
				$this->benchmarks[array_key_last($this->benchmarks)] += [
					"undo_wall_ns" => hrtime(true) - $start, "undo_editor_ns" => array_sum($this->tickSamples),
					"undo_max_tick_ns" => $this->tickSamples === [] ? 0 : max($this->tickSamples), "undo_ticks" => count($this->tickSamples)
				];
				$this->checkpoint();
				$this->editor->forget("benchmark");
			}
		}
		// Let lighting from the benchmark edits drain before the 30 second functional steps resume.
		$settle = $this->getServer()->getTick() + 20;
		yield fn() : bool => $this->getServer()->getTick() >= $settle;
		$this->stepTimeout = 30;

		$persistent = new Region(-17, 100, -17, 16, 101, 16);
		$expected = $this->snapshot($persistent);
		$folder = $this->world->getFolderName();
		$this->access->release();
		$released = true;
		foreach($this->pins as [, $x, $z]){
			$released = $released && count($this->world->getChunkLoaders($x, $z)) <= $this->loaderCounts["$x:$z"];
		}
		$this->check($released, "All edit chunk-loader pins are released after completed work");
		$this->releasePins();
		$this->world->save(true);
		$manager = $this->getServer()->getWorldManager();
		$this->check($manager->unloadWorld($this->world, true), "Edited world unloads cleanly");
		$this->check($manager->loadWorld($folder), "Edited world reloads from disk");
		$this->world = $manager->getWorldByName($folder) ?? throw new \RuntimeException("Reloaded world missing");
		$manager->setDefaultWorld($this->world);
		$this->access = new QuarkWorld($this->world);
		for($cx = -2; $cx <= 1; ++$cx){ for($cz = -2; $cz <= 1; ++$cz){ $this->world->loadChunk($cx, $cz); } }
		$this->check($this->snapshot($persistent) === $expected, "All edited runtime states persist across unload and reload");
		$shutdown = $this->fill(new Region(-16, 250, -16, 15, 253, 15), "stone", "shutdown");
		yield static fn() : bool => $shutdown->changed > 0;
		$this->editor->shutdown();
		$this->check($shutdown->isFinished() && $shutdown->status === JobStatus::Cancelled && $this->editor->active("shutdown") === null, "Shutdown cancels active work and settles ownership");
	}

	private function tilesAndFluids() : \Generator{
		$this->world->setBlockAt(2, 139, 2, VanillaBlocks::STONE());
		$this->world->setBlockAt(2, 140, 2, VanillaBlocks::OAK_SIGN());
		$tile = $this->world->getTileAt(2, 140, 2);
		$this->check($tile instanceof Sign, "Sign block entity is created by the ordinary world path");
		$tile->setText(new SignText(["AsyncWorldEdit", "exact NBT", "negative chunks", "round trip"]));
		$region = new Region(2, 140, 2, 2, 140, 2);
		$before = $this->snapshot($region);
		$job = $this->fill($region);
		yield $this->await($job);
		$undo = $this->editor->restore("integration", $this->access);
		yield $this->await($undo);
		$this->check($this->snapshot($region) === $before, "Sign state and normalized NBT round-trip exactly through undo");
		$this->world->setBlockAt(7, 140, 7, VanillaBlocks::NOTE_BLOCK()->setPitch(17));
		$this->access->acquire(0, 0);
		$note = $this->access->read(7, 140, 7);
		$this->world->setBlockAt(7, 140, 7, VanillaBlocks::AIR());
		$listener = new class($this->world) implements \quark\world\ChunkListener{
			public function __construct(private World $world){}
			public function onBlockChanged(int $x, int $y, int $z) : void{ $this->world->getBlockAt($x, $y, $z); }
			public function onChunkChanged(int $x, int $z, \quark\world\format\Chunk $chunk) : void{}
			public function onChunkLoaded(int $x, int $z, \quark\world\format\Chunk $chunk) : void{}
			public function onChunkUnloaded(int $x, int $z, \quark\world\format\Chunk $chunk) : void{}
			public function onChunkPopulated(int $x, int $z, \quark\world\format\Chunk $chunk) : void{}
		};
		$this->world->registerChunkListener($listener, 0, 0);
		try{
			$this->access->write(7, 140, 7, $this->access->read(7, 140, 7), $note);
			$restoredNote = $this->world->getBlockAt(7, 140, 7);
			$this->check($restoredNote instanceof \quark\block\Note && $restoredNote->getPitch() === 17, 'Restored tile data refreshes blocks cached by chunk listeners');
		}finally{
			$this->world->unregisterChunkListener($listener, 0, 0);
			$this->access->release();
		}
		$this->world->setBlockAt(3, 140, 3, VanillaBlocks::CHEST());
		$this->access->acquire(0, 0);
		$rejected = false;
		try{ $this->access->read(3, 140, 3); }catch(\InvalidArgumentException){ $rejected = true; }
		$this->access->release();
		$this->check($rejected, "Inventory-bearing tiles are rejected before mutation");
		$chunk = $this->world->getChunk(0, 0) ?? throw new \RuntimeException("Pinned chunk became unavailable");
		$sub = $chunk->getSubChunk(140 >> 4);
		$water = new PalettedBlockArray($sub->getEmptyBlockId());
		$water->set(4, 140 & 15, 4, VanillaBlocks::WATER()->getStateId());
		$chunk->setSubChunk(140 >> 4, new SubChunk($sub->getEmptyBlockId(), $sub->getBlockLayer0(), $water, clone $sub->getBiomeArray(), $sub->getBlockSkyLightArray(), $sub->getBlockLightArray()));
		$this->access->acquire(0, 0);
		$rejected = false;
		try{ $this->access->read(4, 140, 4); }catch(\RuntimeException){ $rejected = true; }
		$this->access->release();
		$this->check($rejected, "Secondary fluid layers are rejected before mutation");
		$water->set(4, 140 & 15, 4, $sub->getEmptyBlockId());
	}

	private function commandChecks() : void{
		$map = $this->getServer()->getCommandMap();
		$root = $map->getCommand("/we");
		$this->check($root !== null && $map->getCommand("/worldedit") === $root, "Root //we command and //worldedit alias exist");
		$permissions = [];
		foreach($root->getSyntaxes() as $syntax){
			$permissions[$syntax->getPath()] = $syntax->getPermission();
		}
		$expected = ["set" => "edit", "replace" => "edit", "sphere" => "edit", "move" => "edit", "cancel" => "edit", "copy" => "clipboard", "paste" => "clipboard",
			"undo" => "history", "redo" => "history", "pos1" => "selection", "sel" => "selection", "count" => "selection", "brush sphere" => "brush",
			"brush mask" => "brush", "brush range" => "brush", "schem load" => "schematic", "schem save" => "schematic"];
		$matches = true;
		foreach($expected as $path => $permission){
			$matches = $matches && ($permissions[$path] ?? null) === "asyncworldedit." . $permission;
		}
		$this->check($matches, "Subcommands carry their domain permissions");
		unset($permissions[""]);
		$this->check(!in_array(null, $permissions, true), "Only the help syntax inherits the root permission");
		$shortcuts = true;
		foreach(["/set", "/replace", "/copy", "/paste", "/undo", "/wand", "/pos1", "/hcyl", "/schem", "/brush", "/mask", "/range"] as $name){
			$command = $map->getCommand($name);
			$shortcuts = $shortcuts && $command !== null && $command->getPreparedSyntaxes() !== null;
		}
		$this->check($shortcuts, "Familiar shortcuts are native syntax commands");
		$schem = array_map(static fn($syntax) : string => $syntax->getPath(), $map->getCommand("/schem")->getSyntaxes());
		$this->check($schem === ["load", "save"], "//schem keeps load and save subcommands");
		$binding = null;
		foreach($root->getPreparedSyntaxes() ?? [] as $syntax){
			if($syntax->getPath() === "set"){
				$binding = $syntax->getBinding();
			}
		}
		$this->check($binding?->senderClass === \quark\player\Player::class, "Edit handlers are bound to players only");
	}

	private function schematicRoundTrip() : \Generator{
		$log = VanillaBlocks::OAK_LOG()->setAxis(\quark\math\Axis::X);
		$dirt = VanillaBlocks::DIRT();
		$this->world->setBlockAt(-5, 130, -5, $log);
		$this->world->setBlockAt(-4, 130, -5, $dirt);
		$source = new Region(-5, 130, -5, -4, 130, -5);
		$copy = $this->editor->submit("schematic", $this->access, ClipboardOperations::capture($this->access, $source, [-6, 130, -5], 16, air: $this->target->edits()->states->air()), 2, $source, record: false);
		yield $this->await($copy);
		$clipboard = $copy->result;
		$this->check($clipboard instanceof Clipboard, "Live source blocks copy into a packed clipboard");
		$states = $this->target->edits()->states;
		$runtimeStates = [$log->getStateId(), $dirt->getStateId()];
		$palette = [];
		$bedrock = [];
		foreach($runtimeStates as $state){
			$palette[] = $states->name($state);
			$bedrock[] = GlobalBlockStateHandlers::getSerializer()->serialize($state)->toVanillaNbt();
		}
		$metadata = \pocketmine\nbt\tag\CompoundTag::create()->setIntArray('ClipboardOrigin', $clipboard->origin);
		$data = new SchematicData(2, 1, 1, pack("V*", 0, 1), $palette, $clipboard->min, format: "mcstructure", bedrockPalette: $bedrock, metadata: $metadata, formatVersion: 1);
		$codec = new SchematicCodec();
		$decoded = $codec->decode($codec->encode($data, "mcstructure"), "mcstructure");
		$this->check($decoded->origin === $clipboard->min, "Live mcstructure round-trip preserves absolute source origin");
		$this->check($decoded->placement()['origin'] === $clipboard->origin, 'Live mcstructure round-trip preserves a distinct clipboard pivot');
		$packed = "";
		for($index = 0; $index < $decoded->blockCount(); ++$index){
			$entry = unpack("V", $decoded->states, $index * 4);
			if($entry === false){ throw new \RuntimeException("Decoded live schematic has invalid packed data"); }
			$packed .= pack("V", $states->resolve($decoded->palette[$entry[1]]));
		}
		$placement = $decoded->placement();
		$rotated = (new Clipboard(2, 1, 1, $packed, [], $placement['min'], $placement['origin']))->rotate(90);
		$pivot = [-8, 130, -8];
		$bounds = $rotated->bounds($pivot);
		$before = $this->snapshot($bounds);
		$paste = $this->editor->submit("schematic", $this->access, ClipboardOperations::paste($this->access, $rotated, $pivot, transformState: $states->transform(...)), 2, bounds: $bounds);
		yield $this->await($paste);
		$this->check($this->world->getBlockAt(-8, 130, -7)->getStateId() === VanillaBlocks::OAK_LOG()->setAxis(\quark\math\Axis::Z)->getStateId() && $this->world->getBlockAt(-8, 130, -6)->getStateId() === $dirt->getStateId(), "Rotated schematic paste preserves pivot offsets and Bedrock axis states");
		$undo = $this->editor->restore("schematic", $this->access);
		yield $this->await($undo);
		$this->check($this->snapshot($bounds) === $before, "Rotated schematic paste is exactly undoable in the real world");
		$redo = $this->editor->restore('schematic', $this->access, true);
		yield $this->await($redo);
		$this->check($this->world->getBlockAt(-8, 130, -7)->getStateId() === VanillaBlocks::OAK_LOG()->setAxis(\quark\math\Axis::Z)->getStateId(), 'Rotated schematic redo restores the transformed state');
		$rotatedState = VanillaBlocks::OAK_LOG()->setAxis(\quark\math\Axis::Z)->getStateId();
		$bedrock[0] = GlobalBlockStateHandlers::getSerializer()->serialize($rotatedState)->toVanillaNbt();
		$export = new SchematicData(1, 1, 2, pack('V*', 0, 1), [$states->name($rotatedState), $palette[1]], [-6,130,-4], format: 'mcstructure', bedrockPalette: $bedrock, metadata: $metadata, formatVersion: 1);
		$reimport = $codec->decode($codec->encode($export, 'mcstructure'), 'mcstructure');
		$this->check($reimport->placement() === ['min'=>[-6,130,-4], 'origin'=>[-6,130,-5]] && $states->resolve($reimport->palette[0]) === $rotatedState, 'Rotated export and reimport preserve pivot and directional state');
	}

	private function releasePins() : void{
		if($this->ticker !== null && $this->world->isLoaded()){
			for($x = -1; $x <= 0; ++$x){
				for($z = -1; $z <= 0; ++$z){ $this->world->unregisterTickingChunk($this->ticker, $x, $z); }
			}
			$this->ticker = null;
		}
		foreach($this->pins as [$loader, $x, $z]){
			if($this->world->isLoaded()){ $this->world->unregisterChunkLoader($loader, $x, $z); }
		}
		$this->pins = [];
	}

	private function finish(?\Throwable $error = null) : void{
		if($this->finished){ return; }
		$this->finished = true;
		if(isset($this->access)){ $this->access->release(); }
		if(isset($this->world)){ $this->releasePins(); }
		$this->checkpoint($error, $error === null);
		if($error !== null){ $this->getLogger()->error($error::class . ": " . $error->getMessage()); }
		$this->getServer()->shutdown();
	}

	private function cpuNanos() : int{
		$usage = getrusage();
		return (($usage["ru_utime.tv_sec"] + $usage["ru_stime.tv_sec"]) * 1000000 + $usage["ru_utime.tv_usec"] + $usage["ru_stime.tv_usec"]) * 1000;
	}

	private function checkpoint(?\Throwable $error = null, bool $passed = false) : void{
		$rss = null;
		$status = is_file("/proc/self/status") ? file_get_contents("/proc/self/status") : false;
		if($status !== false && preg_match('/^VmHWM:\s+(\d+) kB$/m', $status, $match)){
			$rss = (int) $match[1] * 1024;
		}
		$report = [
			"passed" => $passed, "in_progress" => !$this->finished, "phase" => $this->phase,
			"error" => $error === null ? null : $error::class . ": " . $error->getMessage(),
			"trace" => $error?->getTraceAsString(), "php" => PHP_VERSION, "quark" => \quark\VersionInfo::BASE_VERSION,
			"quark_commit" => \quark\VersionInfo::GIT_HASH(), "seconds" => microtime(true) - $this->started,
			"composer_original_root_reference" => defined("AWE_COMPOSER_REFERENCE_ORIGINAL") ? AWE_COMPOSER_REFERENCE_ORIGINAL : null,
			"composer_lock_packages_verified" => defined("AWE_COMPOSER_REFERENCE_ORIGINAL"),
			"target_phar_sha256" => hash_file("sha256", dirname($this->getServer()->getDataPath()) . "/plugins/AsyncWorldEdit.phar"),
			"settings" => isset($this->editor) ? get_object_vars($this->editor->settings) : null,
			"checks" => $this->checks, "benchmarks" => $this->benchmarks, "peak_rss_bytes" => $rss,
			"connected_clients" => count($this->getServer()->getOnlinePlayers()), "multiplayer_verified" => false,
			"benchmark_note" => "Both paths use ordinary setBlockAt with update=true. Scheduled work includes expected-state reads, bounded history and scheduler overhead; it is intended to cap tick work, not beat raw writes."
		];
		file_put_contents($this->getServer()->getDataPath() . "integration-results.json", json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
	}
}
