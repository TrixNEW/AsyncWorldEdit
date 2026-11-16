<?php

declare(strict_types=1);

namespace Trix\WorldEdit\tests;

use Trix\WorldEdit\schematic\SchematicData;
use Trix\WorldEdit\worker\SchematicService;
use Trix\WorldEdit\worker\SchematicWorker;
use PHPUnit\Framework\TestCase;
use pocketmine\snooze\SleeperHandler;
use quark\Server;
use quark\thread\ThreadManager;
use quark\thread\ThreadSafeClassLoader;
use ReflectionClass;
use ReflectionProperty;
use RuntimeException;
use Vow\CancelledException;

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
		$loader->addPath("", dirname(__DIR__) . "/src");
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

	/**
	 * @param \Vow\Promise<mixed> $promise
	 * @param list<array{string, mixed}> $events
	 */
	private static function record(\Vow\Promise $promise, array &$events, string $name) : void{
		$promise->observe(static function(mixed $value) use (&$events, $name) : void{ $events[] = [$name, $value]; }, static function(\Throwable $error) use (&$events, $name) : void{ $events[] = [$name, $error]; });
	}

	public function testDedicatedWorkerLoadsSavesAndReleasesSlotBeforeCallback() : void{
		$sleeper = new SleeperHandler();
		$service = new SchematicService($this->directory, 1, 1000, $sleeper);
		$data = new SchematicData(2, 1, 1, pack("V*", 0, 1), ["minecraft:air", "minecraft:stone"], [-4, 80, 2]);
		$events = [];
		$before = count(ThreadManager::getInstance()->getAll());
		try{
			$service->save("roundtrip.schem", $data)->observe(function() use (&$events, $service) : void{
				$events[] = ["save", null];
				// The slot is free again by the time the save settles.
				self::record($service->load("roundtrip.schem"), $events, "load");
			});
			try{
				$service->load("roundtrip.schem");
				self::fail("Accepted a second outstanding request");
			}catch(RuntimeException){
				self::assertCount($before + 1, ThreadManager::getInstance()->getAll());
			}
			$this->waitFor($sleeper, $service, static function() use (&$events) : bool{ return count($events) === 2; });
			self::assertSame(["save", null], $events[0]);
			self::assertSame("load", $events[1][0]);
			self::assertInstanceOf(SchematicData::class, $events[1][1]);
			self::assertSame($data->states, $events[1][1]->states);
			self::assertSame($data->origin, $events[1][1]->origin);

			$cancelled = [];
			$request = $service->load("roundtrip.schem");
			self::record($request, $cancelled, "load");
			$service->cancel($request);
			self::assertCount(1, $cancelled);
			self::assertInstanceOf(CancelledException::class, $cancelled[0][1]);
			$this->waitFor($sleeper, $service, static fn() : bool => !$service->hasPending());
			self::assertCount(1, $cancelled);
		}finally{
			$service->shutdown();
		}
		self::assertCount($before, ThreadManager::getInstance()->getAll());
		$this->expectException(RuntimeException::class);
		$service->load("roundtrip.schem");
	}

	public function testQueuedSaveCancellationAndMissingFilesReturnWithoutCommit() : void{
		$sleeper = new SleeperHandler();
		$entry = $sleeper->addNotifier(static function() : void{});
		$worker = new SchematicWorker($this->directory, 1000, $entry);
		$data = new SchematicData(1, 1, 1, pack("V", 0), ["minecraft:air"]);
		$worker->submit(1, igbinary_serialize(["id" => 1, "operation" => "save", "name" => "cancelled.schem", "data" => $data]));
		$worker->cancel(1);
		try{
			self::assertTrue($worker->start());
			$result = $this->collect($sleeper, $worker);
			self::assertStringContainsString("cancelled", igbinary_unserialize($result)["error"]);
			self::assertFileDoesNotExist($this->directory . "/cancelled.schem");
		}finally{
			$worker->quit();
			$sleeper->removeNotifier($entry->getNotifierId());
		}
		$service = new SchematicService($this->directory, 1, 1000, $sleeper);
		$events = [];
		try{
			self::record($service->load("missing.schem"), $events, "load");
			$this->waitFor($sleeper, $service, static function() use (&$events) : bool{ return $events !== []; });
			self::assertInstanceOf(RuntimeException::class, $events[0][1]);
			self::assertStringContainsString("does not exist", $events[0][1]->getMessage());
		}finally{
			$service->shutdown();
		}
	}

	public function testMalformedWorkerRequestsCannotBecomeSaves() : void{
		$sleeper = new SleeperHandler();
		$entry = $sleeper->addNotifier(static function() : void{});
		$worker = new SchematicWorker($this->directory, 1000, $entry);
		$data = new SchematicData(1, 1, 1, pack('V', 0), ['minecraft:air']);
		try{
			try{
				$worker->submit(0, 'invalid');
				self::fail('Accepted an invalid request ID');
			}catch(RuntimeException){
				self::assertFileDoesNotExist($this->directory . '/unexpected.schem');
			}
			self::assertTrue($worker->start());
			foreach([1 => ['id' => 2, 'operation' => 'save'], 2 => ['id' => 2, 'operation' => 'unexpected']] as $id => $request){
				$worker->submit($id, igbinary_serialize($request + ['name' => 'unexpected.schem', 'data' => $data]));
				self::assertSame('Invalid schematic worker request', igbinary_unserialize($this->collect($sleeper, $worker))['error']);
				self::assertFileDoesNotExist($this->directory . '/unexpected.schem');
			}
		}finally{
			$worker->quit();
			$sleeper->removeNotifier($entry->getNotifierId());
		}
	}

	public function testOversizedSchematicsAreRejectedInsideTheWorker() : void{
		$sleeper = new SleeperHandler();
		$service = new SchematicService($this->directory, 1, 4, $sleeper);
		$events = [];
		try{
			$service->save('big.mcstructure', new SchematicData(5, 1, 1, str_repeat("\0", 20), ['minecraft:air'], format: 'mcstructure', bedrockPalette: [\pocketmine\nbt\tag\CompoundTag::create()], formatVersion: 1))->observe(null, static function(\Throwable $error) use (&$events) : void{ $events[] = $error->getMessage(); });
			$this->waitFor($sleeper, $service, static function() use (&$events) : bool{ return $events !== []; });
			self::assertStringContainsString('dimensions', $events[0]);
		}finally{
			$service->shutdown();
		}
	}

	private function collect(SleeperHandler $sleeper, SchematicWorker $worker) : string{
		$deadline = microtime(true) + 3;
		do{
			$sleeper->sleepUntil(microtime(true) + 0.01);
			$result = $worker->collect();
		}while($result === null && microtime(true) < $deadline);
		self::assertNotNull($result);
		return $result;
	}

	private function waitFor(SleeperHandler $sleeper, SchematicService $service, \Closure $done) : void{
		$deadline = microtime(true) + 3;
		while(!$done() && microtime(true) < $deadline){
			$sleeper->sleepUntil(microtime(true) + 0.01);
			$service->tick();
		}
		self::assertTrue($done(), "Worker did not complete within three seconds");
	}

	public function testActivityAndFilenameReservationsLastUntilWorkerResultsDrain() : void{
		$sleeper = new SleeperHandler();
		$activity = [];
		$service = new SchematicService($this->directory, 2, 1000, $sleeper, static function(bool $active) use (&$activity) : void{ $activity[] = $active; });
		$data = new SchematicData(1, 1, 1, pack('V', 0), ['minecraft:air']);
		try{
			self::assertFalse($service->hasPending());
			$request = $service->save('Same.schem', $data);
			$request->observe(null, static function() : void{});
			self::assertSame([true], $activity);
			try{
				$service->save('same.schem', $data);
				self::fail('Concurrent file save admitted');
			}catch(RuntimeException $error){
				self::assertStringContainsString('already being saved', $error->getMessage());
			}
			$service->cancel($request);
			self::assertTrue($service->hasPending());
			try{
				$service->save('Same.schem', $data);
				self::fail('Cancelled save released its file before draining');
			}catch(RuntimeException $error){
				self::assertStringContainsString('already being saved', $error->getMessage());
			}
			$this->waitFor($sleeper, $service, static fn() : bool => !$service->hasPending());
			self::assertSame([true, false], $activity);
			$saved = false;
			$service->save('Same.schem', $data)->observe(static function() use (&$saved) : void{ $saved = true; });
			$this->waitFor($sleeper, $service, static function() use (&$saved) : bool{ return $saved; });
			self::assertSame([true, false, true, false], $activity);
		}finally{
			$service->shutdown();
		}
	}

	public function testLostWorkersSettleTheirRequestAndCanBeReplaced() : void{
		$sleeper = new SleeperHandler();
		$service = new SchematicService($this->directory, 1, 1000, $sleeper);
		$before = count(ThreadManager::getInstance()->getAll());
		$events = [];
		try{
			self::record($service->load('missing.schem'), $events, 'first');
			$slots = (new ReflectionProperty(SchematicService::class, 'workers'))->getValue($service);
			$slots[0]['worker']->quit();
			$service->tick();
			self::assertCount(1, $events);
			self::assertInstanceOf(\Throwable::class, $events[0][1]);
			self::assertFalse($service->hasPending());
			self::record($service->load('missing.schem'), $events, 'second');
			$this->waitFor($sleeper, $service, static function() use (&$events) : bool{ return count($events) === 2; });
			self::assertInstanceOf(\Throwable::class, $events[1][1]);
		}finally{
			$service->shutdown();
		}
		self::assertCount($before, ThreadManager::getInstance()->getAll());
	}
}
