<?php

declare(strict_types=1);

namespace Trix\WorldEdit\tests;

use Trix\WorldEdit\Settings;
use Trix\WorldEdit\engine\Editor;
use Trix\WorldEdit\engine\Job;
use Trix\WorldEdit\geometry\Region;
use Trix\WorldEdit\engine\BlockValue;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/MemoryWorld.php';

final class JobLifecycleTest extends TestCase{
	public function testCompletionFailureDoesNotBreakSchedulerOrRetainGenerator() : void{
		$editor = new Editor(new Settings());
		$world = new MemoryWorld();
		$steps = (static function() : \Generator{ yield null; })();
		$job = $editor->submit('a', $world, $steps, 1, onDone: static function(Job $job) : void{ throw new \RuntimeException('callback failed'); });
		$editor->tick();
		self::assertSame('completed', $job->status->value);
		self::assertSame('Completion failed: callback failed', $job->error);
		self::assertNull($job->steps);
		$next = $editor->submit('a', $world, (static function() : \Generator{ yield null; })(), 1);
		$editor->tick();
		self::assertTrue($next->isFinished());
	}

	public function testReentrantDisconnectReleasesHistoryAfterCompletion() : void{
		$editor = new Editor(new Settings());
		$world = new MemoryWorld();
		$steps = (static function() use ($editor) : \Generator{
			$editor->forget('a');
			yield null;
		})();
		$job = $editor->submit('a', $world, $steps, 1);
		$editor->tick();
		self::assertSame('completed', $job->status->value);
		self::assertSame(0, $editor->historyBytes());
	}

	public function testGeneratorReturnValueBecomesTheResult() : void{
		$editor = new Editor(new Settings());
		$steps = (static function() : \Generator{
			yield null;
			return 'done';
		})();
		$job = $editor->submit('a', new MemoryWorld(), $steps, 1);
		EditorTest::settle($editor, $job);
		self::assertSame('done', $job->result);
	}

	public function testActivityStartsWithTheFirstJobAndStopsWithTheLast() : void{
		$events = [];
		$editor = new Editor(new Settings(), static function(bool $active) use (&$events) : void{ $events[] = $active; });
		$world = new MemoryWorld();
		$a = $editor->submit('a', $world, (static function() : \Generator{ yield null; })(), 1);
		$b = $editor->submit('b', $world, (static function() : \Generator{ yield null; })(), 1);
		EditorTest::settle($editor, $a);
		EditorTest::settle($editor, $b);
		self::assertSame([true, false], $events);
	}

	public function testHistoryLimitEvictsOldEntriesBeforeFailingNewEdits() : void{
		$editor = new Editor(new Settings(historyBytes: 1048576));
		$world = new MemoryWorld();
		mt_srand(1);
		// Random states compress poorly, so a few entries are enough to reach the limit.
		$fill = static function(int $from) : \Generator{
			for($i = 0; $i < 20000; ++$i){
				yield [$from + $i, 0, 0, new BlockValue(mt_rand(1, 0x7fffffff)), null];
			}
		};
		for($round = 0; $round < 6; ++$round){
			$region = new Region($round * 20000, 0, 0, $round * 20000 + 19999, 0, 0);
			$job = $editor->submit('a', $world, $fill($round * 20000), 20000, $region);
			EditorTest::settle($editor, $job);
			self::assertSame('completed', $job->status->value, (string) $job->error);
			self::assertLessThanOrEqual(1048576, $editor->historyBytes());
		}
	}
}
