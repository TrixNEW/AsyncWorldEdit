<?php

declare(strict_types=1);

namespace asyncworldedit;

final readonly class Settings{
	public function __construct(
		public int $maxBlocks = 2000000,
		public int $maxJobs = 16,
		public int $budgetMicros = 2000,
		public int $blocksPerTick = 2048,
		public int $historyBytes = 67108864,
		public int $historyEntries = 15,
		public int $clipboardBlocks = 500000,
		public int $workers = 1,
		public int $clipboardBytes = 67108864
	){
		if($maxBlocks < 1 || $maxBlocks > 10000000 || $maxJobs < 1 || $maxJobs > 128 ||
			$budgetMicros < 100 || $budgetMicros > 10000 || $blocksPerTick < 1 || $blocksPerTick > 16384 ||
			$historyBytes < 1048576 || $historyBytes > 1073741824 || $historyEntries < 1 || $historyEntries > 100 ||
			$clipboardBlocks < 1 || $clipboardBlocks > $maxBlocks || $workers < 1 || $workers > 4 ||
			$clipboardBytes < 1048576 || $clipboardBytes > 1073741824){
			throw new \InvalidArgumentException("Invalid editing limits in config.toml");
		}
	}

	/** @param array<string, mixed> $values */
	public static function fromArray(array $values) : self{
		$args = [];
		foreach((new \ReflectionMethod(self::class, '__construct'))->getParameters() as $parameter){
			$key = $parameter->getName();
			$value = $values[$key] ?? $parameter->getDefaultValue();
			if(!is_int($value)){
				throw new \InvalidArgumentException("Configuration $key must be an integer");
			}
			$args[$key] = $value;
		}
		foreach($values as $key => $_){
			if(!array_key_exists($key, $args)){
				throw new \InvalidArgumentException("Unknown configuration setting $key");
			}
		}
		return new self(...$args);
	}
}
