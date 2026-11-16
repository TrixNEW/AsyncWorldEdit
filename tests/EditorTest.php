<?php

declare(strict_types=1);

namespace Trix\WorldEdit\tests;

use Trix\WorldEdit\Settings;
use Trix\WorldEdit\engine\BlockValue;
use Trix\WorldEdit\engine\ChangeSet;
use Trix\WorldEdit\engine\Editor;
use Trix\WorldEdit\engine\Job;
use Trix\WorldEdit\engine\Operations;
use Trix\WorldEdit\geometry\Region;
use Trix\WorldEdit\pattern\Mask;
use Trix\WorldEdit\pattern\Pattern;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/MemoryWorld.php';

final class EditorTest extends TestCase{
	public static function settle(Editor $editor, Job $job) : void{
		for($i = 0; !$job->isFinished() && $i < 10000; ++$i){
			$editor->tick();
		}
		self::assertTrue($job->isFinished(), 'Job failed to settle');
	}

	private function fill(Editor $editor, MemoryWorld $world, string $owner = 'a', ?Region $region = null, ?Mask $mask = null) : Job{
		$region ??= new Region(-17, -1, -17, 16, 0, 16);
		return $editor->submit($owner, $world, Operations::fill($world, $region, Pattern::parse('stone', static fn(string $name) : int => 1)), $region->volume(), $region, $mask);
	}

	public function testFillAcrossNegativeChunkBoundariesAndHistoryRoundTrip() : void{
		$world = new MemoryWorld();
		$editor = new Editor(new Settings());
		$job = $this->fill($editor, $world);
		self::settle($editor, $job);
		self::assertSame('completed', $job->status->value);
		self::assertSame(2312, $job->changed);
		$undo = $editor->restore('a', $world);
		self::settle($editor, $undo);
		self::assertSame(0, $world->read(-17, -1, -17)->state);
		$redo = $editor->restore('a', $world, true);
		self::settle($editor, $redo);
		self::assertSame(1, $world->read(-17, -1, -17)->state);
		self::assertSame(2312, $redo->changed);
		self::assertSame(0, $world->pins);
	}

	public function testUndoRedoCyclesKeepOneEntryPerDirection() : void{
		$world = new MemoryWorld();
		$editor = new Editor(new Settings());
		self::settle($editor, $this->fill($editor, $world, region: new Region(0, 0, 0, 3, 0, 0)));
		for($cycle = 0; $cycle < 5; ++$cycle){
			self::settle($editor, $editor->restore('a', $world));
			self::assertSame(0, $world->read(3, 0, 0)->state);
			self::settle($editor, $editor->restore('a', $world, true));
			self::assertSame(1, $world->read(3, 0, 0)->state);
		}
		self::assertNull($editor->history->top("a\0test", true));
		self::assertNotNull($editor->history->top("a\0test", false));
	}

	public function testCancellationKeepsCommittedChangesUndoable() : void{
		$world = new MemoryWorld();
		$editor = new Editor(new Settings());
		$job = $this->fill($editor, $world);
		$editor->tick(4);
		$job->cancel();
		$editor->tick(4);
		self::assertSame('cancelled', $job->status->value);
		self::assertSame(4, $job->changed);
		$undo = $editor->restore('a', $world);
		self::settle($editor, $undo);
		self::assertSame(4, $undo->changed);
		self::assertSame(0, $world->pins);
	}

	public function testUndoDoesNotOverwriteAnUnrelatedNewerState() : void{
		$world = new MemoryWorld();
		$editor = new Editor(new Settings());
		self::settle($editor, $this->fill($editor, $world, region: new Region(0, 0, 0, 0, 0, 0)));
		$world->set(0, 0, 0, new BlockValue(2));
		$undo = $editor->restore('a', $world);
		self::settle($editor, $undo);
		self::assertSame('conflicted', $undo->status->value);
		self::assertSame(1, $undo->conflicts);
		self::assertSame(2, $world->read(0, 0, 0)->state);
	}

	public function testOverlappingRegionRejectedAndDisjointOwnersProgressFairly() : void{
		$world = new MemoryWorld();
		$editor = new Editor(new Settings());
		$a = $this->fill($editor, $world, region: new Region(0, 0, 0, 15, 0, 0));
		$b = $this->fill($editor, $world, 'b', new Region(16, 0, 0, 31, 0, 0));
		$editor->tick(4);
		self::assertSame(2, $a->changed);
		self::assertSame(2, $b->changed);
		try{
			$this->fill($editor, $world, 'c', new Region(0, 0, 0, 1, 0, 0));
			self::fail('Overlapping job was accepted');
		}catch(\RuntimeException $e){
			self::assertStringContainsString('owns', $e->getMessage());
		}
		$editor->shutdown();
		self::assertTrue($a->isFinished());
		self::assertTrue($b->isFinished());
	}

	public function testUndoOnlyLocksTheBoxItTouches() : void{
		$world = new MemoryWorld();
		$editor = new Editor(new Settings());
		self::settle($editor, $this->fill($editor, $world, region: new Region(0, 0, 0, 3, 0, 0)));
		$undo = $editor->restore('a', $world);
		$other = $this->fill($editor, $world, 'b', new Region(10, 0, 0, 12, 0, 0));
		try{
			$this->fill($editor, $world, 'c', new Region(2, 0, 0, 2, 0, 0));
			self::fail('An edit overlapping a running undo was accepted');
		}catch(\RuntimeException $e){
			self::assertStringContainsString('owns', $e->getMessage());
		}
		self::settle($editor, $undo);
		self::settle($editor, $other);
		self::assertSame('completed', $other->status->value);
		self::assertSame(0, $world->read(0, 0, 0)->state);
	}

	public function testMaskAppliesToTheBlockBeingReplaced() : void{
		$world = new MemoryWorld();
		$world->set(0, 0, 0, new BlockValue(5));
		$editor = new Editor(new Settings());
		$mask = Mask::parse('five', static fn(string $name) : int => 5, static fn(int $state) : bool => true);
		$job = $this->fill($editor, $world, region: new Region(0, 0, 0, 1, 0, 0), mask: $mask);
		self::settle($editor, $job);
		self::assertSame(1, $world->read(0, 0, 0)->state);
		self::assertSame(0, $world->read(1, 0, 0)->state);
		self::assertSame(1, $job->changed);
	}

	public function testFailureWorldUnloadAndShutdownSettleJobs() : void{
		$world = new MemoryWorld();
		$editor = new Editor(new Settings());
		$job = $this->fill($editor, $world);
		$world->fail = true;
		$editor->tick();
		self::assertSame('failed', $job->status->value);
		self::assertSame(0, $world->pins);
		$world->fail = false;
		$job = $this->fill($editor, $world);
		$world->valid = false;
		$editor->tick();
		self::assertSame('cancelled', $job->status->value);
	}

	public function testChangeSetSignedCoordinatesExactTileDataAndBounds() : void{
		$set = new ChangeSet('world');
		$set->append(-30000000, -64, 30000000, new BlockValue(0, "\0before"), new BlockValue(0xffffffff, "\0after"));
		$set->append(5, 10, -5, BlockValue::of(1), BlockValue::of(2));
		$records = iterator_to_array($set->changes(), false);
		self::assertSame([-30000000, -64, 30000000], array_slice($records[0], 0, 3));
		self::assertSame("\0before", $records[0][3]->nbt);
		self::assertSame(0xffffffff, $records[0][4]->state);
		$bounds = $set->bounds();
		self::assertSame([-30000000, -64, -5, 5, 10, 30000000], [$bounds?->minX, $bounds?->minY, $bounds?->minZ, $bounds?->maxX, $bounds?->maxY, $bounds?->maxZ]);
		$this->expectException(\OutOfBoundsException::class);
		$set->append(4294967296, 0, 0, BlockValue::of(0), BlockValue::of(1));
	}

	public function testHistoryIsIndependentInEachWorld() : void{
		$first = new MemoryWorld('first');
		$second = new MemoryWorld('second');
		$editor = new Editor(new Settings());
		$region = new Region(0, 0, 0, 0, 0, 0);
		self::settle($editor, $this->fill($editor, $first, region: $region));
		self::settle($editor, $this->fill($editor, $second, region: $region));
		self::settle($editor, $editor->restore('a', $first));
		self::assertSame(0, $first->read(0, 0, 0)->state);
		self::assertSame(1, $second->read(0, 0, 0)->state);
		self::settle($editor, $editor->restore('a', $second));
		self::assertSame(0, $second->read(0, 0, 0)->state);
	}

	public function testJobsWithoutBoundsCannotWrite() : void{
		$world = new MemoryWorld();
		$editor = new Editor(new Settings());
		$steps = (static function() : \Generator{ yield [0, 0, 0, new BlockValue(1), null]; })();
		$job = $editor->submit('api', $world, $steps, 1);
		$editor->tick();
		self::assertSame('failed', $job->status->value);
		self::assertSame(0, $job->changed);
		self::assertSame([], $world->blocks);
	}

	public function testUnchangedBlocksAreNotWrittenOrRecorded() : void{
		$world = new MemoryWorld();
		$world->set(0, 0, 0, BlockValue::of(1));
		$region = new Region(0, 0, 0, 0, 0, 0);
		$steps = (static function() : \Generator{ yield [0, 0, 0, BlockValue::of(1), null]; })();
		$editor = new Editor(new Settings());
		$job = $editor->submit('a', $world, $steps, 1, $region);
		self::settle($editor, $job);
		self::assertSame(0, $world->writes);
		self::assertSame(0, $job->history->count());
	}
}
