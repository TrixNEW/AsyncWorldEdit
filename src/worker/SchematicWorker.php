<?php

declare(strict_types=1);

namespace asyncworldedit\worker;

use asyncworldedit\schematic\SchematicCodec;
use asyncworldedit\schematic\SchematicData;
use asyncworldedit\schematic\SchematicFileStore;
use pmmp\thread\ThreadSafeArray;
use pocketmine\snooze\SleeperHandlerEntry;
use quark\thread\Thread;
use RuntimeException;
use Throwable;

final class SchematicWorker extends Thread{

	/** @var ThreadSafeArray<string, string> */
	private ThreadSafeArray $requests;
	/** @var ThreadSafeArray<string, string> */
	private ThreadSafeArray $results;
	/** @var ThreadSafeArray<string, int> */
	private ThreadSafeArray $control;

	public function __construct(private string $directory, private SleeperHandlerEntry $sleeperEntry){
		$this->requests = new ThreadSafeArray();
		$this->results = new ThreadSafeArray();
		$this->control = new ThreadSafeArray();
	}

	public function submit(int $id, string $payload) : void{
		$this->synchronized(function() use ($id, $payload) : void{
			if($this->isKilled || isset($this->control["active"])){
				throw new RuntimeException("Schematic worker is unavailable");
			}
			$this->control["active"] = $id;
			$this->control["cancelled"] = 0;
			$this->requests["job"] = $payload;
			$this->notify();
		});
	}

	public function cancel(int $id) : void{
		$this->synchronized(function() use ($id) : void{
			if(($this->control["active"] ?? 0) === $id){
				$this->control["cancelled"] = $id;
			}
		});
	}

	public function collect() : ?string{
		return $this->synchronized(function() : ?string{
			$payload = $this->results["result"] ?? null;
			if($payload !== null){
				unset($this->results["result"], $this->control["active"]);
			}
			return $payload;
		});
	}

	protected function onRun() : void{
		$notifier = $this->sleeperEntry->createNotifier();
		$codec = new SchematicCodec(maxBlocks: 500000);
		$store = null;
		while(true){
			$payload = $this->synchronized(function() : ?string{
				while(!$this->isKilled && !isset($this->requests["job"])){
					$this->wait();
				}
				if($this->isKilled){
					return null;
				}
				$payload = $this->requests["job"];
				unset($this->requests["job"]);
				return $payload;
			});
			if($payload === null){
				break;
			}
			try{
				$request = igbinary_unserialize($payload);
				$id = $request["id"];
				if($this->cancelled($id)){
					throw new RuntimeException("Schematic request was cancelled");
				}
				$store ??= new SchematicFileStore($this->directory);
				$name = $request["name"];
				$format = pathinfo($name, PATHINFO_EXTENSION);
				if($request["operation"] === "load"){
					$data = $codec->decode($store->read($name), $format);
				}else{
					$data = $request["data"];
					if(!$data instanceof SchematicData){
						throw new RuntimeException("Missing schematic save data");
					}
					$bytes = $codec->encode($data, $format);
					$store->save($name, $bytes, fn() : bool => $this->cancelled($id));
					$data = null;
				}
				$response = igbinary_serialize(["data" => $data, "error" => null]);
				if($response === null || strlen($response) > SchematicService::MAX_PAYLOAD_BYTES){
					throw new RuntimeException("Schematic result exceeds transport size limit");
				}
			}catch(Throwable $e){
				$response = igbinary_serialize(["data" => null, "error" => substr($e->getMessage(), 0, 1024)]);
				if($response === null){
					throw new RuntimeException("Cannot serialize schematic failure", 0, $e);
				}
			}
			$this->synchronized(function() use ($response) : void{
				$this->results["result"] = $response;
			});
			unset($request, $data, $bytes, $payload, $response);
			$notifier->wakeupSleeper();
		}
	}

	private function cancelled(int $id) : bool{
		return $this->isKilled || ($this->control["cancelled"] ?? 0) === $id;
	}

	public function quit() : void{
		$this->synchronized(function() : void{
			$this->isKilled = true;
			$this->notify();
		});
		parent::quit();
	}

	public function getThreadName() : string{
		return "AsyncWorldEdit schematic";
	}
}
