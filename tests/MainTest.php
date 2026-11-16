<?php

declare(strict_types=1);

namespace Trix\WorldEdit\tests;

use Trix\WorldEdit\Main;
use Trix\WorldEdit\Sessions;
use Trix\WorldEdit\Settings;
use Trix\WorldEdit\engine\BlockValue;
use Trix\WorldEdit\engine\Editor;
use Trix\WorldEdit\geometry\Region;
use Trix\WorldEdit\quark\Outlines;
use Trix\WorldEdit\quark\QuarkWorld;
use Trix\WorldEdit\quark\Tools;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use quark\player\Player;
use quark\plugin\PluginBase;
use quark\scheduler\TaskScheduler;
use quark\world\World;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;

final class MainTest extends TestCase{
	protected function setUp() : void{
		if(!extension_loaded('pmmpthread') || !is_file(dirname(__DIR__, 2) . '/Quark/vendor/autoload.php')){
			self::markTestSkipped('Requires the Quark runtime and sibling source checkout');
		}
		require_once dirname(__DIR__, 2) . '/Quark/vendor/autoload.php';
		$loader = require dirname(__DIR__) . '/vendor/autoload.php';
		$loader->unregister();
		$loader->register(true);
		require_once __DIR__ . '/MemoryWorld.php';
	}

	public function testMalformedWandMarkerIsIgnored() : void{
		$item = \quark\item\VanillaItems::WOODEN_AXE();
		$item->getNamedTag()->setString('asyncworldedit:wand', 'unexpected');
		self::assertFalse(Tools::isWand($item));
		self::assertTrue(Tools::isWand(Tools::wand()));
	}

	public function testEditorTaskRunsOnlyWhileJobsExist() : void{
		$plugin = (new ReflectionClass(Main::class))->newInstanceWithoutConstructor();
		$scheduler = new TaskScheduler('AsyncWorldEdit test');
		$scheduler->setEnabled(true);
		(new ReflectionProperty(PluginBase::class, 'scheduler'))->setValue($plugin, $scheduler);
		$editor = new Editor(new Settings(), (new ReflectionMethod(Main::class, 'editActivity'))->getClosure($plugin));
		(new ReflectionProperty(Main::class, 'editor'))->setValue($plugin, $editor);
		$task = new ReflectionProperty(Main::class, 'editTask');
		self::assertNull($task->getValue($plugin));

		$world = new MemoryWorld();
		$steps = (static function() : \Generator{
			yield [0, 0, 0, BlockValue::of(1), null];
			yield [1, 0, 0, BlockValue::of(1), null];
		})();
		$job = $editor->submit('api', $world, $steps, 2, new Region(0, 0, 0, 1, 0, 0));
		$handler = $task->getValue($plugin);
		self::assertNotNull($handler);
		for($tick = 1; $tick < 4; ++$tick){
			$scheduler->mainThreadHeartbeat($tick);
		}
		self::assertSame('completed', $job->status->value);
		self::assertNull($task->getValue($plugin));
		self::assertFalse($scheduler->isQueued($handler));

		$undo = $editor->restore('api', $world);
		self::assertNotNull($task->getValue($plugin));
		for($tick = 4; $tick < 8; ++$tick){
			$scheduler->mainThreadHeartbeat($tick);
		}
		self::assertSame('completed', $undo->status->value);
		self::assertSame(0, $world->read(0, 0, 0)->state);
		self::assertNull($task->getValue($plugin));
		$editor->shutdown();
		$scheduler->shutdown();
	}

	public function testOutlinesOnlyScheduleForEnabledSelectionViewers() : void{
		$scheduler = new TaskScheduler('Outline test');
		$scheduler->setEnabled(true);
		$sessions = new Sessions(1048576);
		$outlines = new Outlines($scheduler, $sessions);
		$world = new class extends World{
			public function __construct(){}
			public function getFolderName() : string{ return 'outline-test'; }
			public function isLoaded() : bool{ return true; }
		};
		(new ReflectionProperty(World::class, 'worldId'))->setValue($world, 1200);
		$player = new class($world) extends Player{
			public bool $connected = true;
			private UuidInterface $testUuid;
			public function __construct(private World $testWorld){ $this->testUuid = Uuid::uuid4(); }
			public function __destruct(){}
			public function getUniqueId() : UuidInterface{ return $this->testUuid; }
			public function getWorld() : World{ return $this->testWorld; }
			public function isConnected() : bool{ return $this->connected; }
			public function hasPermission(\quark\permission\Permission|string $name) : bool{ return true; }
		};
		$task = new ReflectionProperty(Outlines::class, 'task');
		$outlines->refresh($player);
		self::assertNull($task->getValue($outlines));

		$session = $sessions->get($player);
		$session->worldId = QuarkWorld::worldId($world);
		$session->first = [0, 0, 0];
		$session->second = [2, 2, 2];
		$outlines->refresh($player);
		$handler = $task->getValue($outlines);
		self::assertNotNull($handler);
		self::assertSame(20, $handler->getPeriod());
		$outlines->refresh($player);
		self::assertSame($handler, $task->getValue($outlines));

		$session->outline = false;
		$outlines->refresh($player);
		self::assertNull($task->getValue($outlines));
		self::assertFalse($scheduler->isQueued($handler));
		$session->outline = true;
		$outlines->refresh($player);
		self::assertNotNull($task->getValue($outlines));
		$player->connected = false;
		$outlines->refresh($player);
		self::assertNull($task->getValue($outlines));
		$scheduler->shutdown();
	}
}
