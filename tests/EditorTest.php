<?php

declare(strict_types=1);

namespace asyncworldedit\tests;

use asyncworldedit\Settings;
use asyncworldedit\engine\BlockValue;
use asyncworldedit\engine\ChangeSet;
use asyncworldedit\engine\Editor;
use asyncworldedit\engine\Job;
use asyncworldedit\engine\Operations;
use asyncworldedit\geometry\Region;
use asyncworldedit\pattern\Pattern;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/MemoryWorld.php';

final class EditorTest extends TestCase{
	private function editor(int $batch = 16) : Editor{
		return new Editor(new Settings(blocksPerTick: $batch));
	}

	private function settle(Editor $editor, Job $job) : void{
		for($i = 0; !$job->isFinished() && $i < 10000; ++$i){ $editor->tick(); }
		self::assertTrue($job->isFinished(), 'Job failed to settle');
	}

	private function fill(Editor $editor, MemoryWorld $world, string $owner = 'a', ?Region $region = null) : Job{
		$region ??= new Region(-17, -1, -17, 16, 0, 16);
		return $editor->submit($owner, $world, Operations::fill($world, $region, Pattern::parse('stone', static fn(string $name) : int => 1)), $region->volume(), bounds: $region);
	}

	public function testFillAcrossNegativeChunkBoundariesAndHistoryRoundTrip() : void{
		$world = new MemoryWorld(); $editor = $this->editor();
		$job = $this->fill($editor, $world); $this->settle($editor, $job);
		self::assertSame('completed', $job->status);
		self::assertSame(2312, $job->changed);
		$undo = $editor->restore('a', $world); $this->settle($editor, $undo);
		self::assertSame(0, $world->read(-17, -1, -17)->state);
		$redo = $editor->restore('a', $world, true); $this->settle($editor, $redo);
		self::assertSame(1, $world->read(-17, -1, -17)->state);
		self::assertSame(0, $world->pins);
	}

	public function testCancellationKeepsCommittedChangesUndoable() : void{
		$world = new MemoryWorld(); $editor = $this->editor(4);
		$job = $this->fill($editor, $world); $editor->tick(); $job->cancel(); $editor->tick();
		self::assertSame('cancelled', $job->status); self::assertSame(4, $job->changed);
		$undo = $editor->restore('a', $world); $this->settle($editor, $undo);
		self::assertSame(4, $undo->changed); self::assertSame(0, $world->pins);
	}

	public function testUndoDoesNotOverwriteAnUnrelatedNewerState() : void{
		$world = new MemoryWorld(); $editor = $this->editor();
		$job = $this->fill($editor, $world, region: new Region(0, 0, 0, 0, 0, 0)); $this->settle($editor, $job);
		$world->write(0, 0, 0, new BlockValue(2));
		$undo = $editor->restore('a', $world); $this->settle($editor, $undo);
		self::assertSame('conflicted', $undo->status); self::assertSame(1, $undo->conflicts);
		self::assertSame(2, $world->read(0, 0, 0)->state);
	}

	public function testOverlappingRegionRejectedAndDisjointOwnersProgressFairly() : void{
		$world = new MemoryWorld(); $editor = $this->editor(4);
		$a = $this->fill($editor, $world, region: new Region(0, 0, 0, 15, 0, 0));
		$b = $this->fill($editor, $world, 'b', new Region(16, 0, 0, 31, 0, 0));
		$editor->tick(); self::assertSame(2, $a->changed); self::assertSame(2, $b->changed);
		try{ $this->fill($editor, $world, 'c', new Region(0, 0, 0, 1, 0, 0)); self::fail(); }
		catch(\RuntimeException $e){ self::assertStringContainsString('owns', $e->getMessage()); }
		$editor->shutdown(); self::assertTrue($a->isFinished()); self::assertTrue($b->isFinished());
	}

	public function testFailureWorldUnloadAndShutdownSettleJobs() : void{
		$world = new MemoryWorld(); $editor = $this->editor();
		$job = $this->fill($editor, $world); $world->fail = true; $editor->tick();
		self::assertSame('failed', $job->status); self::assertSame(0, $world->pins);
		$world->fail = false; $job = $this->fill($editor, $world); $world->valid = false; $editor->tick();
		self::assertSame('cancelled', $job->status);
	}

	public function testChangeSetSignedCoordinatesAndExactTileData() : void{
		$set = new ChangeSet('world');
		$set->append(-30000000, -64, 30000000, new BlockValue(0, "\0before"), new BlockValue(0xffffffff, "\0after"));
		$records = iterator_to_array($set->changes());
		self::assertSame([-30000000, -64, 30000000], array_slice($records[0], 0, 3));
		self::assertSame("\0before", $records[0][3]->nbt); self::assertSame(0xffffffff, $records[0][4]->state);
	}

	public function testHistoryIsIndependentInEachWorld() : void{
		$first = new MemoryWorld('first'); $second = new MemoryWorld('second'); $editor = $this->editor();
		$region = new Region(0,0,0,0,0,0);
		$a = $this->fill($editor,$first,region:$region); $this->settle($editor,$a);
		$b = $this->fill($editor,$second,region:$region); $this->settle($editor,$b);
		$undo = $editor->restore('a',$first); $this->settle($editor,$undo);
		self::assertSame(0,$first->read(0,0,0)->state);
		self::assertSame(1,$second->read(0,0,0)->state);
		$undo = $editor->restore('a',$second); $this->settle($editor,$undo);
		self::assertSame(0,$second->read(0,0,0)->state);
	}

	public function testApiCoordinatesCannotWrapHistoryStorage() : void{
		$world = new MemoryWorld(); $editor = $this->editor();
		$steps = (static function() : \Generator{ yield [4294967296,0,0,new BlockValue(1),null]; })();
		$job = $editor->submit('api',$world,$steps,1);
		$editor->tick();
		self::assertSame('failed',$job->status);
		self::assertSame(0,$job->changed);
		self::assertSame([],$world->blocks);
	}
}
