<?php

declare(strict_types=1);

namespace asyncworldedit\tests;

use asyncworldedit\schematic\SchematicData;
use asyncworldedit\worker\SchematicService;
use asyncworldedit\worker\SchematicWorker;
use PHPUnit\Framework\TestCase;
use pocketmine\snooze\SleeperHandler;
use quark\Server;
use quark\thread\ThreadManager;
use quark\thread\ThreadSafeClassLoader;
use ReflectionClass;
use ReflectionProperty;
use RuntimeException;

final class SchematicWorkerTest extends TestCase{

	private string $directory;
	private ?object $previousServer = null;

	protected function setUp() : void{
		if(!extension_loaded("pmmpthread") || !extension_loaded("igbinary") || !is_file(dirname(__DIR__, 2) . "/Quark/vendor/autoload.php")){
			self::markTestSkipped("Requires the Quark threaded runtime and sibling source checkout");
		}
		require_once dirname(__DIR__, 2) . "/Quark/vendor/autoload.php";
		$pluginLoader = require dirname(__DIR__) . "/vendor/autoload.php";
		$pluginLoader->unregister();
		$pluginLoader->register(true);
		$property = new ReflectionProperty(Server::class, "instance");
		$this->previousServer = $property->getValue();
		$server = (new ReflectionClass(Server::class))->newInstanceWithoutConstructor();
		$loader = new ThreadSafeClassLoader();
		$loader->addPath("asyncworldedit", dirname(__DIR__) . "/src");
		(new ReflectionProperty(Server::class, "autoloader"))->setValue($server, $loader);
		$property->setValue(null, $server);
		$this->directory = sys_get_temp_dir() . "/awe-worker-" . bin2hex(random_bytes(8));
		mkdir($this->directory);
	}

	protected function tearDown() : void{
		if(!isset($this->directory)){
			return;
		}
		(new ReflectionProperty(Server::class, "instance"))->setValue(null, $this->previousServer);
		foreach(glob($this->directory . "/*") as $file){
			unlink($file);
		}
		rmdir($this->directory);
	}

	public function testDedicatedWorkerLoadsSavesAndReleasesSlotBeforeCallback() : void{
		$sleeper = new SleeperHandler();
		$service = new SchematicService($this->directory, 1, $sleeper);
		$data = new SchematicData(2, 1, 1, pack("V*", 0, 1), ["minecraft:air", "minecraft:stone"], [-4, 80, 2]);
		$events = [];
		$before = count(ThreadManager::getInstance()->getAll());
		try{
			$service->save("roundtrip.schem", $data, function(?SchematicData $saved, ?string $error) use (&$events, $service) : void{
				$events[] = ["save", $saved, $error];
				$service->load("roundtrip.schem", static function(?SchematicData $loaded, ?string $loadError) use (&$events) : void{
					$events[] = ["load", $loaded, $loadError];
				});
			});
			try{
				$service->load("roundtrip.schem", static function() : void{});
				self::fail("Accepted a second outstanding request");
			}catch(RuntimeException){
				self::assertCount($before + 1, ThreadManager::getInstance()->getAll());
			}
			$this->waitFor($sleeper, $service, static function() use (&$events) : bool{ return count($events) === 2; });
			self::assertSame(["save", null, null], $events[0]);
			self::assertSame("load", $events[1][0]);
			self::assertNull($events[1][2]);
			self::assertSame($data->states, $events[1][1]->states);
			self::assertSame($data->origin, $events[1][1]->origin);
			$cancelled = [];
			$id = $service->load("roundtrip.schem", static function(?SchematicData $result, ?string $error) use (&$cancelled) : void{ $cancelled[] = [$result, $error]; });
			$service->cancel($id);
			self::assertSame([[null, "Schematic request was cancelled"]], $cancelled);
			$sleeper->sleepUntil(microtime(true) + 0.1);
			$service->tick();
			self::assertCount(1, $cancelled);
		}finally{
			$service->shutdown();
		}
		self::assertCount($before, ThreadManager::getInstance()->getAll());
		$this->expectException(RuntimeException::class);
		$service->load("roundtrip.schem", static function() : void{});
	}

	public function testQueuedSaveCancellationAndMissingFilesReturnWithoutCommit() : void{
		$sleeper = new SleeperHandler();
		$entry = $sleeper->addNotifier(static function() : void{});
		$worker = new SchematicWorker($this->directory, $entry);
		$data = new SchematicData(1, 1, 1, pack("V", 0), ["minecraft:air"]);
		$worker->submit(1, igbinary_serialize(["id" => 1, "operation" => "save", "name" => "cancelled.schem", "data" => $data]));
		$worker->cancel(1);
		try{
			self::assertTrue($worker->start());
			$result = null;
			$deadline = microtime(true) + 3;
			do{
				$sleeper->sleepUntil(microtime(true) + 0.01);
				$result = $worker->collect();
			}while($result === null && microtime(true) < $deadline);
			self::assertNotNull($result);
			self::assertStringContainsString("cancelled", igbinary_unserialize($result)["error"]);
			self::assertFileDoesNotExist($this->directory . "/cancelled.schem");
		}finally{
			$worker->quit();
			$sleeper->removeNotifier($entry->getNotifierId());
		}
		$service = new SchematicService($this->directory, 1, $sleeper);
		$error = null;
		try{
			$service->load("missing.schem", static function(?SchematicData $data, ?string $message) use (&$error) : void{ $error = $message; });
			$this->waitFor($sleeper, $service, static function() use (&$error) : bool{ return $error !== null; });
			self::assertStringContainsString("does not exist", $error);
		}finally{
			$service->shutdown();
		}
	}

	private function waitFor(SleeperHandler $sleeper, SchematicService $service, \Closure $done) : void{
		$deadline = microtime(true) + 3;
		while(!$done() && microtime(true) < $deadline){
			$sleeper->sleepUntil(microtime(true) + 0.01);
			$service->tick();
		}
		self::assertTrue($done(), "Worker did not complete within three seconds");
	}
}
