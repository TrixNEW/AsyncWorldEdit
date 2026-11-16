<?php

declare(strict_types=1);

namespace Trix\WorldEdit\tests;

use Trix\WorldEdit\Settings;
use Trix\WorldEdit\engine\BlockValue;
use Trix\WorldEdit\engine\ChangeSet;
use Trix\WorldEdit\engine\Editor;
use Trix\WorldEdit\engine\Operations;
use Trix\WorldEdit\geometry\Region;
use Trix\WorldEdit\pattern\Pattern;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/MemoryWorld.php';

final class StressTest extends TestCase{
	public function testCompressedHistoryAcrossSegmentsAndSignedCoordinates() : void{
		$set = new ChangeSet('world');
		for($i = 0; $i < 10000; ++$i){
			$set->append(-$i, -64, $i, BlockValue::of(0), BlockValue::of(1));
		}
		self::assertLessThan(200000, $set->bytes());
		$forward = iterator_to_array($set->changes(), false);
		$reverse = iterator_to_array($set->changes(true), false);
		self::assertCount(10000, $forward);
		foreach($forward as $i => $record){
			self::assertSame(-$i, $record[0]);
			self::assertSame($record[0], $reverse[9999 - $i][0]);
		}
	}

	public function testRepeatedOwnersCancelUnloadAndDisconnectWithoutRetainedWork() : void{
		$world = new MemoryWorld();
		$editor = new Editor(new Settings());
		$pattern = Pattern::parse('stone', static fn(string $name) : int => 1);
		for($cycle = 0; $cycle < 100; ++$cycle){
			$jobs = [];
			for($i = 0; $i < 4; ++$i){
				$region = new Region($i * 16, 0, 0, $i * 16 + 15, 0, 0);
				$jobs[] = $editor->submit((string) $i, $world, Operations::fill($world, $region, $pattern), $region->volume(), $region);
			}
			$editor->tick(8);
			foreach($jobs as $i => $job){
				$editor->forget((string) $i);
			}
			// Departed owners' edits run to completion so no half-applied change is left without history.
			foreach($jobs as $job){
				EditorTest::settle($editor, $job);
				self::assertSame(16, $job->changed);
			}
			self::assertSame(0, $editor->historyBytes());
			self::assertSame(0, $world->pins);
			self::assertFalse($editor->hasJobs());
			$world->blocks = [];
		}
	}

	public function testInterruptedUndoCanResumeWithoutLosingUnprocessedHistory() : void{
		$world = new MemoryWorld();
		$editor = new Editor(new Settings());
		$region = new Region(0, 0, 0, 15, 0, 0);
		$job = $editor->submit('a', $world, Operations::fill($world, $region, Pattern::parse('stone', static fn(string $name) : int => 1)), 16, $region);
		EditorTest::settle($editor, $job);
		$undo = $editor->restore('a', $world);
		$editor->tick(4);
		$undo->cancel();
		$editor->tick();
		self::assertSame(4, $undo->changed);
		$retry = $editor->restore('a', $world);
		EditorTest::settle($editor, $retry);
		foreach($world->blocks as $block){
			self::assertSame(0, $block->state);
		}
		// The partial undo went to redo, and the finished retry consumed the original entry.
		self::assertNull($editor->history->top("a\0test", false));
	}
}
