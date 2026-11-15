<?php

declare(strict_types=1);

namespace Trix\WorldEdit\worker;

use Trix\WorldEdit\schematic\SchematicData;
use Trix\WorldEdit\schematic\SchematicFileStore;
use Closure;
use InvalidArgumentException;
use pocketmine\snooze\SleeperHandler;
use quark\thread\ThreadManager;
use RuntimeException;
use Throwable;

final class SchematicService{

	public const MAX_PAYLOAD_BYTES = 67108864;

	/** @var array<int, array{worker: SchematicWorker, notifier: int, request: ?int, file: ?string}> */
	private array $workers = [];
	/** @var array<int, Closure(?SchematicData, ?string): void> */
	private array $callbacks = [];
	private int $nextId = 1;
	private bool $closed = false;

	/** @param Closure(bool):void|null $activity */
	public function __construct(private string $directory, private int $workerCount, private SleeperHandler $sleeper, private ?Closure $activity = null){
		if($workerCount < 1 || $workerCount > 4){
			throw new InvalidArgumentException("Use between one and four schematic workers");
		}
	}

	/** @param Closure(?SchematicData, ?string): void $callback */
	public function load(string $name, Closure $callback) : int{
		return $this->submit("load", $name, null, $callback);
	}

	/** @param Closure(?SchematicData, ?string): void $callback */
	public function save(string $name, SchematicData $data, Closure $callback) : int{
		return $this->submit("save", $name, $data, $callback);
	}

	public function cancel(int $requestId) : void{
		$callback = $this->callbacks[$requestId] ?? null;
		unset($this->callbacks[$requestId]);
		foreach($this->workers as $slot){
			if($slot["request"] === $requestId){
				$slot["worker"]->cancel($requestId);
				break;
			}
		}
		if($callback !== null){
			$this->invoke($callback, null, "Schematic request was cancelled");
		}
	}

	public function tick() : void{
		if($this->closed){
			return;
		}
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
		$activity = $this->activity;
		$this->activity = null;
		$callbacks = $this->callbacks;
		$this->callbacks = [];
		foreach($this->workers as $slot){
			if($slot["request"] !== null){
				$slot["worker"]->cancel($slot["request"]);
			}
		}
		foreach($callbacks as $callback){
			$this->invoke($callback, null, "Schematic request was cancelled");
		}
		foreach($this->workers as $slot){
			$this->sleeper->removeNotifier($slot["notifier"]);
			$slot["worker"]->quit();
		}
		$this->workers = [];
		$activity?->__invoke(false);
	}

	/** @param Closure(?SchematicData, ?string): void $callback */
	private function submit(string $operation, string $name, ?SchematicData $data, Closure $callback) : int{
		$this->tick();
		if($this->closed){
			throw new RuntimeException("Schematic service is shut down");
		}
		SchematicFileStore::validateName($name);
		$file = $operation === 'save' ? strtolower($name) : null;
		if($file !== null){
			foreach($this->workers as $slot){
				if($slot['file'] === $file && $slot['request'] !== null){
					throw new RuntimeException('This schematic is already being saved');
				}
			}
		}
		$index = null;
		foreach($this->workers as $workerId => $slot){
			if($slot["request"] === null && !$slot["worker"]->isTerminated()){
				$index = $workerId;
				break;
			}
		}
		if($index === null){
			if(count($this->workers) >= $this->workerCount){
				throw new RuntimeException("All schematic workers are busy or unavailable");
			}
			$index = 0;
			while(isset($this->workers[$index])){ ++$index; }
			$entry = $this->sleeper->addNotifier(fn() => $this->collect($index));
			$worker = new SchematicWorker($this->directory, $entry);
			try{
				if(!$worker->start()){
					throw new RuntimeException("Cannot start schematic worker");
				}
			}catch(Throwable $e){
				$this->sleeper->removeNotifier($entry->getNotifierId());
				ThreadManager::getInstance()->remove($worker);
				throw new RuntimeException("Cannot start schematic worker", 0, $e);
			}
			$this->workers[$index] = ["worker" => $worker, "notifier" => $entry->getNotifierId(), "request" => null, 'file' => null];
		}
		$id = $this->nextId++;
		$payload = igbinary_serialize(["id" => $id, "operation" => $operation, "name" => $name, "data" => $data]);
		if($payload === null || strlen($payload) > self::MAX_PAYLOAD_BYTES){
			throw new RuntimeException("Schematic request exceeds transport size limit");
		}
		$wasIdle = !$this->hasPending();
		$slot = $this->workers[$index];
		$slot['worker']->submit($id, $payload);
		$slot['request'] = $id;
		$slot['file'] = $file;
		$this->workers[$index] = $slot;
		$this->callbacks[$id] = $callback;
		if($wasIdle){ ($this->activity)?->__invoke(true); }
		return $id;
	}

	public function hasPending() : bool{
		foreach($this->workers as $slot){
			if($slot['request'] !== null){ return true; }
		}
		return false;
	}

	private function collect(int $index) : void{
		if($this->closed || !isset($this->workers[$index])){
			return;
		}
		$slot = $this->workers[$index];
		$payload = $slot["worker"]->collect();
		$crashed = $slot["worker"]->isTerminated() || $slot['worker']->isJoined();
		$id = $slot["request"];
		if($crashed){
			$this->sleeper->removeNotifier($slot['notifier']);
			$slot['worker']->quit();
			unset($this->workers[$index]);
		}
		if($id === null || ($payload === null && !$crashed)){
			return;
		}
		if(!$crashed){
			$this->workers[$index]["request"] = null;
			$this->workers[$index]['file'] = null;
		}
		$callback = $this->callbacks[$id] ?? null;
		unset($this->callbacks[$id]);
		if($callback === null){
			if(!$this->hasPending()){ ($this->activity)?->__invoke(false); }
			return;
		}
		$result = $payload === null ? ["data" => null, "error" => "Schematic worker terminated"] : igbinary_unserialize($payload);
		$this->invoke($callback, $result["data"], $result["error"]);
		if(!$this->hasPending()){ ($this->activity)?->__invoke(false); }
	}

	/** @param Closure(?SchematicData, ?string): void $callback */
	private function invoke(Closure $callback, ?SchematicData $data, ?string $error) : void{
		try{
			$callback($data, $error);
		}catch(Throwable $e){
			\GlobalLogger::get()->logException($e);
		}
	}
}
