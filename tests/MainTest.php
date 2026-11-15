<?php

declare(strict_types=1);

namespace Trix\WorldEdit\tests;

use Trix\WorldEdit\Main;
use Trix\WorldEdit\Session;
use Trix\WorldEdit\Settings;
use Trix\WorldEdit\clipboard\Clipboard;
use Trix\WorldEdit\engine\Editor;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use ReflectionMethod;
use quark\plugin\PluginBase;
use quark\scheduler\TaskScheduler;
use Trix\WorldEdit\engine\BlockValue;
use quark\player\Player;
use quark\world\World;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use RuntimeException;

final class MainTest extends TestCase{
	private function requireQuark() : void{
		if(!extension_loaded('pmmpthread') || !is_file(dirname(__DIR__, 2) . '/Quark/vendor/autoload.php')){
			self::markTestSkipped('Requires the Quark runtime and sibling source checkout');
		}
		require_once dirname(__DIR__, 2) . '/Quark/vendor/autoload.php';
		$loader = require dirname(__DIR__) . '/vendor/autoload.php';
		$loader->unregister(); $loader->register(true);
	}

	public function testMalformedWandMarkerIsIgnored() : void{
		$this->requireQuark();
		$item = \quark\item\VanillaItems::WOODEN_AXE();
		$item->getNamedTag()->setString('asyncworldedit:wand','unexpected');
		self::assertFalse(\Trix\WorldEdit\quark\Tools::isWand($item));
		$item->getNamedTag()->setByte('asyncworldedit:wand',1);
		self::assertTrue(\Trix\WorldEdit\quark\Tools::isWand($item));
	}

	public function testEditorTasksWakeForApiAndHistoryAndStopWhenIdle() : void{
		$this->requireQuark();
		require_once __DIR__ . '/MemoryWorld.php';
		$plugin = (new ReflectionClass(Main::class))->newInstanceWithoutConstructor();
		$scheduler = new TaskScheduler('AsyncWorldEdit test'); $scheduler->setEnabled(true);
		(new ReflectionProperty(PluginBase::class, 'scheduler'))->setValue($plugin, $scheduler);
		$activity = (new ReflectionMethod(Main::class, 'editActivity'))->getClosure($plugin);
		$editor = new Editor(new Settings(blocksPerTick:1), $activity);
		(new ReflectionProperty(Main::class, 'editor'))->setValue($plugin, $editor);
		$taskProperty = new ReflectionProperty(Main::class, 'editTask');
		self::assertNull($taskProperty->getValue($plugin));
		$world = new MemoryWorld();
		$steps = (static function() : \Generator{ yield [0,0,0,new BlockValue(1),null]; yield [1,0,0,new BlockValue(1),null]; })();
		$job = $editor->submit('api',$world,$steps,2);
		$handler = $taskProperty->getValue($plugin);
		self::assertNotNull($handler);
		$scheduler->mainThreadHeartbeat(1);
		self::assertSame($handler,$taskProperty->getValue($plugin));
		for($tick = 2; $tick < 8; ++$tick){ $scheduler->mainThreadHeartbeat($tick); }
		self::assertSame('completed',$job->status);
		self::assertNull($taskProperty->getValue($plugin));
		self::assertFalse($scheduler->isQueued($handler));
		$undo = $editor->restore('api',$world);
		self::assertNotNull($taskProperty->getValue($plugin));
		for($tick = 8; $tick < 16; ++$tick){ $scheduler->mainThreadHeartbeat($tick); }
		self::assertSame('completed',$undo->status);
		self::assertSame(0,$world->read(0,0,0)->state);
		self::assertNull($taskProperty->getValue($plugin));
		$editor->shutdown(); $scheduler->shutdown();
	}

	public function testClipboardReservationPreventsConcurrentQuotaUseAndChanges() : void{
		$this->requireQuark();
		$plugin = (new ReflectionClass(Main::class))->newInstanceWithoutConstructor();
		(new ReflectionProperty(Main::class, 'editor'))->setValue($plugin, new Editor(new Settings(clipboardBytes:1048576)));
		$first = new Session(); $second = new Session();
		(new ReflectionProperty(Main::class, 'sessions'))->setValue($plugin,['first'=>$first,'second'=>$second]);
		$full = new Clipboard(262144,1,1,str_repeat("\0",1048576));
		$small = new Clipboard(1,1,1,pack('V',1));
		$plugin->reserveClipboard($first,$full);
		try{ $plugin->setClipboard($second,$small); self::fail('Reservation was ignored'); }
		catch(RuntimeException){ self::assertNull($second->clipboard); }
		try{ $plugin->setClipboard($first,$small); self::fail('Reserved clipboard was changed'); }
		catch(RuntimeException){ self::assertNull($first->clipboard); }
		$plugin->setClipboard($first,$full); $plugin->releaseClipboard($first);
		self::assertSame($full,$first->clipboard);
		$plugin->setClipboard($first,null); $plugin->setClipboard($second,$small);
		self::assertSame($small,$second->clipboard);
	}

	public function testOutlinesOnlyScheduleForEnabledSelectionViewers() : void{
		$this->requireQuark();
		$plugin = (new ReflectionClass(Main::class))->newInstanceWithoutConstructor();
		$scheduler = new TaskScheduler('Outline test'); $scheduler->setEnabled(true);
		(new ReflectionProperty(PluginBase::class,'scheduler'))->setValue($plugin,$scheduler);
		$world = new class extends World{
			public function __construct(){}
			public function getFolderName() : string{ return 'outline-test'; }
			public function isLoaded() : bool{ return true; }
		};
		(new ReflectionProperty(World::class,'worldId'))->setValue($world,1200);
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
		$property = new ReflectionProperty(Main::class,'outlineTask');
		$plugin->refreshOutline($player);
		self::assertNull($property->getValue($plugin));
		$session = $plugin->session($player);
		$session->worldId = $plugin->world($world)->id();
		$session->first = [0,0,0]; $session->second = [2,2,2];
		$plugin->refreshOutline($player);
		$handler = $property->getValue($plugin);
		self::assertNotNull($handler);
		self::assertSame(20,$handler->getPeriod());
		$plugin->refreshOutline($player);
		self::assertSame($handler,$property->getValue($plugin));
		$session->outline = false; $plugin->refreshOutline($player);
		self::assertNull($property->getValue($plugin));
		self::assertFalse($scheduler->isQueued($handler));
		$session->outline = true; $plugin->refreshOutline($player);
		self::assertNotNull($property->getValue($plugin));
		$player->connected = false; $plugin->refreshOutline($player);
		self::assertNull($property->getValue($plugin));
		$scheduler->shutdown();
	}

	public function testGlobalClipboardAdmissionPreservesPreviousClipboardOnFailure() : void{
		if(!extension_loaded('pmmpthread') || !is_file(dirname(__DIR__, 2) . '/Quark/vendor/autoload.php')){
			self::markTestSkipped('Requires the Quark runtime and sibling source checkout');
		}
		require_once dirname(__DIR__, 2) . '/Quark/vendor/autoload.php';
		$loader = require dirname(__DIR__) . '/vendor/autoload.php';
		$loader->unregister();
		$loader->register(true);
		$plugin = (new ReflectionClass(Main::class))->newInstanceWithoutConstructor();
		$editor = new Editor(new Settings(clipboardBytes: 1048576));
		(new ReflectionProperty(Main::class, 'editor'))->setValue($plugin, $editor);
		$first = new Session();
		$second = new Session();
		(new ReflectionProperty(Main::class, 'sessions'))->setValue($plugin, ['first' => $first, 'second' => $second]);
		$half = new Clipboard(131072, 1, 1, str_repeat("\0", 524288));
		$larger = new Clipboard(131073, 1, 1, str_repeat("\0", 524292));
		$plugin->setClipboard($first, $half);
		$plugin->setClipboard($second, $half);
		try{
			$plugin->setClipboard($second, $larger);
			self::fail('Admitted clipboards above the global memory limit');
		}catch(RuntimeException){
			self::assertSame($half, $second->clipboard);
		}
		$plugin->setClipboard($first, null);
		$plugin->setClipboard($second, $larger);
		self::assertNull($first->clipboard);
		self::assertSame($larger, $second->clipboard);
	}
}
