<?php

declare(strict_types=1);

namespace Trix\WorldEdit\worker;

use Trix\WorldEdit\schematic\SchematicData;
use Trix\WorldEdit\schematic\SchematicFileStore;
use pocketmine\snooze\SleeperHandler;
use quark\thread\ThreadManager;
use RuntimeException;
use Throwable;
use Vow\Deferred;
use Vow\Promise;

/**
 * Runs schematic file work on up to four worker threads, started on demand.
 * Promises settle on the main thread when the sleeper wakes it; cancelled ones ignore late results.
 */
final class SchematicService{
	public const MAX_PAYLOAD_BYTES = 67108864;

	/** @var array<int, array{worker: SchematicWorker, notifier: int, request: ?int, file: ?string, deferred: ?Deferred<mixed>}> */
	private array $workers = [];
	private int $nextId = 1;
	private bool $closed = false;

	/** @param (\Closure(bool) : void)|null $activity told when work starts and when the last request settles */
	public function __construct(
		private readonly string $directory,
		private readonly int $workerCount,
		private readonly int $maxBlocks,
		private readonly SleeperHandler $sleeper,
		private ?\Closure $activity = null
	){
		if($workerCount < 1 || $workerCount > 4){
			throw new \InvalidArgumentException('Use between one and four schematic workers');
		}
	}

	/** @return Promise<SchematicData> */
	public function load(string $name) : Promise{
		/** @var Promise<SchematicData> */
		return $this->submit('load', $name, null);
	}

	/** @return Promise<null> */
	public function save(string $name, SchematicData $data) : Promise{
		/** @var Promise<null> */
		return $this->submit('save', $name, $data);
	}

	/** @param Promise<mixed> $request */
	public function cancel(Promise $request) : void{
		foreach($this->workers as $slot){
			if($slot['deferred']?->promise === $request && $slot['request'] !== null){
				// The worker stays busy until it reports back; its result is then ignored.
				$slot['worker']->cancel($slot['request']);
				$slot['deferred']->cancel();
				return;
			}
		}
	}

	public function hasPending() : bool{
		foreach($this->workers as $slot){
			if($slot['request'] !== null){
				return true;
			}
		}
		return false;
	}

	/** Watchdog for workers that die without waking the main thread. */
	public function tick() : void{
		foreach($this->workers as $index => $slot){
			if($slot['worker']->isTerminated() || $slot['worker']->isJoined()){
				$this->collect($index);
			}
		}
	}

	public function shutdown() : void{
		if($this->closed){
			return;
		}
		$this->closed = true;
		$workers = $this->workers;
		$this->workers = [];
		foreach($workers as $slot){
			if($slot['request'] !== null){
				$slot['worker']->cancel($slot['request']);
			}
			$slot['deferred']?->cancel();
		}
		foreach($workers as $slot){
			$this->sleeper->removeNotifier($slot['notifier']);
			$slot['worker']->quit();
		}
		$activity = $this->activity;
		$this->activity = null;
		$activity?->__invoke(false);
	}

	/** @return Promise<mixed> */
	private function submit(string $operation, string $name, ?SchematicData $data) : Promise{
		if($this->closed){
			throw new RuntimeException('Schematic service is shut down');
		}
		$this->tick();
		SchematicFileStore::validateName($name);
		$file = $operation === 'save' ? strtolower($name) : null;
		$index = null;
		foreach($this->workers as $workerIndex => $slot){
			if($file !== null && $slot['file'] === $file){
				throw new RuntimeException('This schematic is already being saved');
			}
			if($index === null && $slot['request'] === null && !$slot['worker']->isTerminated()){
				$index = $workerIndex;
			}
		}
		$payload = igbinary_serialize(['id' => $this->nextId, 'operation' => $operation, 'name' => $name, 'data' => $data]);
		if($payload === null || strlen($payload) > self::MAX_PAYLOAD_BYTES){
			throw new RuntimeException('Schematic request exceeds the transport size limit');
		}
		$index ??= $this->start();
		$wasIdle = !$this->hasPending();
		$id = $this->nextId++;
		$this->workers[$index]['worker']->submit($id, $payload);
		/** @var Deferred<mixed> $deferred */
		$deferred = new Deferred();
		$this->workers[$index] = ['request' => $id, 'file' => $file, 'deferred' => $deferred] + $this->workers[$index];
		if($wasIdle){
			($this->activity)?->__invoke(true);
		}
		return $deferred->promise;
	}

	private function start() : int{
		if(count($this->workers) >= $this->workerCount){
			throw new RuntimeException('All schematic workers are busy; retry shortly');
		}
		$index = 0;
		while(isset($this->workers[$index])){
			++$index;
		}
		$entry = $this->sleeper->addNotifier(fn() => $this->collect($index));
		$worker = new SchematicWorker($this->directory, $this->maxBlocks, $entry);
		try{
			if(!$worker->start()){
				throw new RuntimeException('Cannot start schematic worker');
			}
		}catch(Throwable $e){
			$this->sleeper->removeNotifier($entry->getNotifierId());
			ThreadManager::getInstance()->remove($worker);
			throw new RuntimeException('Cannot start schematic worker', 0, $e);
		}
		$this->workers[$index] = ['worker' => $worker, 'notifier' => $entry->getNotifierId(), 'request' => null, 'file' => null, 'deferred' => null];
		return $index;
	}

	private function collect(int $index) : void{
		$slot = $this->workers[$index] ?? null;
		if($this->closed || $slot === null){
			return;
		}
		$payload = $slot['worker']->collect();
		$crashed = $slot['worker']->isTerminated() || $slot['worker']->isJoined();
		if($crashed){
			$this->sleeper->removeNotifier($slot['notifier']);
			$slot['worker']->quit();
			unset($this->workers[$index]);
		}elseif($payload === null || $slot['request'] === null){
			return;
		}else{
			$this->workers[$index] = ['request' => null, 'file' => null, 'deferred' => null] + $slot;
		}
		$deferred = $slot['deferred'];
		try{
			if($deferred !== null && $slot['request'] !== null){
				$result = $payload === null ? null : igbinary_unserialize($payload);
				if(!is_array($result)){
					$deferred->reject(new RuntimeException('Schematic worker terminated'));
				}elseif(is_string($result['error'] ?? null)){
					$deferred->reject(new RuntimeException($result['error']));
				}else{
					$deferred->resolve($result['data'] ?? null);
				}
			}
		}catch(Throwable $e){
			// Callbacks run while settling; one failing must not leave the slot or the watchdog stuck.
			\GlobalLogger::get()->logException($e);
		}finally{
			if(!$this->hasPending()){
				($this->activity)?->__invoke(false);
			}
		}
	}
}
