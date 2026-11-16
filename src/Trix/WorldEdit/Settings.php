<?php

declare(strict_types=1);

namespace Trix\WorldEdit;

final readonly class Settings{
	/** Constructor parameter => [section, key] in config.toml. */
	private const KEYS = [
		'budgetMicros' => ['editing', 'budget-micros'],
		'maxBlocks' => ['editing', 'max-blocks'],
		'maxJobs' => ['editing', 'max-jobs'],
		'historyBytes' => ['history', 'memory'],
		'historyEntries' => ['history', 'entries'],
		'clipboardBlocks' => ['clipboard', 'max-blocks'],
		'clipboardBytes' => ['clipboard', 'memory'],
		'workers' => ['schematics', 'workers'],
	];
	/** Flat keys used before config-version 2; the time budget replaced blocksPerTick. */
	private const REMOVED = ['blocksPerTick'];

	public function __construct(
		public int $budgetMicros = 2000,
		public int $maxBlocks = 10000000,
		public int $maxJobs = 16,
		public int $historyBytes = 268435456,
		public int $historyEntries = 15,
		public int $clipboardBlocks = 5000000,
		public int $clipboardBytes = 134217728,
		public int $workers = 1
	){
		foreach([
			'editing.budget-micros' => [$budgetMicros, 100, 10000],
			'editing.max-blocks' => [$maxBlocks, 1, 10000000],
			'editing.max-jobs' => [$maxJobs, 1, 128],
			'history.memory' => [$historyBytes, 1048576, 1073741824],
			'history.entries' => [$historyEntries, 1, 100],
			'clipboard.max-blocks' => [$clipboardBlocks, 1, $maxBlocks],
			'clipboard.memory' => [$clipboardBytes, 1048576, 1073741824],
			'schematics.workers' => [$workers, 1, 4],
		] as $name => [$value, $min, $max]){
			if($value < $min || $value > $max){
				throw new \InvalidArgumentException("config.toml: $name must be between $min and $max");
			}
		}
	}

	/** @param array<array-key, mixed> $config */
	public static function fromConfig(array $config) : self{
		$args = [];
		$known = ['config-version' => true];
		foreach(self::KEYS as $parameter => [$section, $key]){
			$known[$section] = true;
			$values = $config[$section] ?? [];
			if(!is_array($values)){
				throw new \InvalidArgumentException("config.toml: [$section] must be a table");
			}
			if(array_key_exists($key, $values)){
				$value = $values[$key];
				$args[$parameter] = is_int($value) ? $value : throw new \InvalidArgumentException("config.toml: $section.$key must be an integer");
			}
		}
		foreach($config as $name => $values){
			if(!isset($known[$name])){
				throw new \InvalidArgumentException("config.toml: unknown setting $name");
			}
			if(is_array($values)){
				foreach($values as $key => $_){
					if(!in_array([$name, $key], self::KEYS, true)){
						throw new \InvalidArgumentException("config.toml: unknown setting $name.$key");
					}
				}
			}
		}
		return new self(...$args);
	}

	/**
	 * Writes values from a flat, pre-version-2 config into $template.
	 * Returns null when nothing needs migrating; unknown keys are an error rather than being dropped.
	 *
	 * @param array<array-key, mixed> $config
	 */
	public static function migrate(array $config, string $template) : ?string{
		$legacy = array_intersect_key($config, self::KEYS + array_flip(self::REMOVED));
		if($legacy === []){
			return null;
		}
		$unknown = array_diff_key($config, self::KEYS + array_flip(self::REMOVED));
		if($unknown !== []){
			throw new \InvalidArgumentException('config.toml: cannot migrate unknown setting ' . array_key_first($unknown));
		}
		$values = [];
		foreach(array_intersect_key($config, self::KEYS) as $parameter => $value){
			if(!is_int($value)){
				throw new \InvalidArgumentException("config.toml: $parameter must be an integer");
			}
			[$section, $key] = self::KEYS[$parameter];
			$values["$section.$key"] = $value;
		}
		$section = '';
		$lines = explode("\n", $template);
		foreach($lines as $index => $line){
			if(preg_match('/^\[([a-z-]+)\]\s*$/', $line, $match) === 1){
				$section = $match[1];
			}elseif(preg_match('/^([a-z-]+) = /', $line, $match) === 1 && isset($values["$section.{$match[1]}"])){
				$lines[$index] = $match[1] . ' = ' . $values["$section.{$match[1]}"];
				unset($values["$section.{$match[1]}"]);
			}
		}
		if($values !== []){
			throw new \LogicException('Configuration template is missing ' . array_key_first($values));
		}
		return implode("\n", $lines);
	}
}
