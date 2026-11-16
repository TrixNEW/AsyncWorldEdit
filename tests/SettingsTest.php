<?php

declare(strict_types=1);

namespace Trix\WorldEdit\tests;

use Trix\WorldEdit\Settings;
use PHPUnit\Framework\TestCase;

final class SettingsTest extends TestCase{
	private static function template() : string{
		return (string) file_get_contents(dirname(__DIR__) . '/resources/config.toml');
	}

	/** @return array<string, mixed> */
	private static function parse(string $toml) : array{
		$values = [];
		$section = null;
		foreach(explode("\n", $toml) as $line){
			if(preg_match('/^\[([a-z-]+)\]$/', $line, $match) === 1){
				$section = $match[1];
			}elseif(preg_match('/^([a-z-]+) = (-?\d+)$/', $line, $match) === 1){
				if($section === null){
					$values[$match[1]] = (int) $match[2];
				}else{
					$values[$section][$match[1]] = (int) $match[2];
				}
			}
		}
		return $values;
	}

	public function testBundledConfigMatchesDefaults() : void{
		self::assertEquals(new Settings(), Settings::fromConfig(self::parse(self::template())));
	}

	public function testLegacyFlatConfigKeepsEveryValue() : void{
		$legacy = ['maxBlocks' => 1234567, 'maxJobs' => 3, 'budgetMicros' => 900, 'blocksPerTick' => 64, 'historyBytes' => 2097152,
			'historyEntries' => 7, 'clipboardBlocks' => 4096, 'workers' => 2, 'clipboardBytes' => 3145728];
		$migrated = Settings::migrate($legacy, self::template());
		self::assertNotNull($migrated);
		self::assertStringContainsString('# Undo steps kept per player', $migrated);
		self::assertEquals(new Settings(900, 1234567, 3, 2097152, 7, 4096, 3145728, 2), Settings::fromConfig(self::parse($migrated)));
		self::assertNull(Settings::migrate(self::parse($migrated), self::template()));
	}

	public function testPartialLegacyConfigKeepsOtherDefaults() : void{
		$migrated = Settings::migrate(['budgetMicros' => 5000], self::template());
		self::assertSame(5000, Settings::fromConfig(self::parse((string) $migrated))->budgetMicros);
		self::assertSame(16, Settings::fromConfig(self::parse((string) $migrated))->maxJobs);
	}

	public function testUnknownOrInvalidSettingsAreRejected() : void{
		foreach([
			['maxBlocks' => 10, 'typo' => 1],
			['editing' => ['budget-micros' => 50]],
			['editing' => ['budgetmicros' => 2000]],
			['history' => ['memory' => '64MiB']],
			['clipboard' => ['max-blocks' => 3000000]],
		] as $config){
			try{
				Settings::migrate($config, self::template()) ?? Settings::fromConfig($config);
				self::fail('Accepted ' . json_encode($config));
			}catch(\InvalidArgumentException){
				$this->addToAssertionCount(1);
			}
		}
	}
}
