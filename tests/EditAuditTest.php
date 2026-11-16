<?php

declare(strict_types=1);

namespace Trix\WorldEdit\tests;

use Trix\WorldEdit\Settings;
use Trix\WorldEdit\engine\BlockValue;
use Trix\WorldEdit\engine\ClipboardOperations;
use Trix\WorldEdit\engine\Editor;
use Trix\WorldEdit\engine\JobStatus;
use Trix\WorldEdit\engine\TerrainOperations;
use Trix\WorldEdit\geometry\Region;
use Trix\WorldEdit\pattern\Mask;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/MemoryWorld.php';

final class EditAuditTest extends TestCase{
	private const ORIGIN = [0, 0, 0];

	private static function origin() : Region{
		return new Region(0, 0, 0, 0, 0, 0);
	}

	/** @return \Generator<int, array{int, int, int, BlockValue, null}, mixed, void> */
	private static function writes(int ...$xs) : \Generator{
		foreach($xs as $x){
			yield [$x, 0, 0, BlockValue::of(1), null];
		}
	}

	public function testShutdownDuringPreparationCannotApplyThePreparedWrite() : void{
		$activity = [];
		$editor = new Editor(new Settings(), static function(bool $active) use (&$activity) : void{ $activity[] = $active; });
		$world = new MemoryWorld();
		$steps = (static function() use ($editor) : \Generator{
			$editor->shutdown();
			yield [0, 0, 0, BlockValue::of(1), null];
		})();
		$job = $editor->submit('a', $world, $steps, 1, self::origin());
		$editor->tick();
		self::assertSame('cancelled', $job->status->value);
		self::assertSame([], $world->blocks);
		self::assertFalse($editor->hasJobs());
		self::assertSame([true, false], $activity);
	}

	public function testCancellationInsideWorldWriteDoesNotResumeGenerator() : void{
		$editor = new Editor(new Settings());
		$world = new MemoryWorld();
		$resumed = false;
		$steps = (static function() use (&$resumed) : \Generator{
			yield [0, 0, 0, BlockValue::of(1), null];
			$resumed = true;
			yield [1, 0, 0, BlockValue::of(1), null];
		})();
		$job = $editor->submit('a', $world, $steps, 2, new Region(0, 0, 0, 1, 0, 0));
		$world->afterWrite = $job->cancel(...);
		$editor->tick();
		self::assertSame('cancelled', $job->status->value);
		self::assertFalse($resumed);
		self::assertSame(1, $job->changed);
		self::assertSame(0, $world->pins);
	}

	public function testReleaseFailureDoesNotLeaveEditorRunningOrJobsUnsettled() : void{
		$editor = new Editor(new Settings());
		$world = new MemoryWorld();
		$job = $editor->submit('a', $world, (static function() : \Generator{ yield null; yield null; })(), 2);
		$world->failRelease = true;
		$editor->tick(1);
		self::assertSame('failed', $job->status->value);
		self::assertFalse($editor->hasJobs());
		$world->failRelease = false;
		$next = $editor->submit('a', $world, (static function() : \Generator{ yield null; })(), 1);
		EditorTest::settle($editor, $next);
		self::assertSame('completed', $next->status->value);
	}

	public function testWritesCannotEscapeReservedRegion() : void{
		$editor = new Editor(new Settings());
		$world = new MemoryWorld();
		$job = $editor->submit('a', $world, self::writes(16), 1, self::origin());
		EditorTest::settle($editor, $job);
		self::assertSame('failed', $job->status->value);
		self::assertSame([], $world->blocks);
	}

	public function testCutHonorsMaskAndChecksAdmissionBeforeClearing() : void{
		$world = new MemoryWorld();
		$world->set(0, 0, 0, new BlockValue(2));
		$editor = new Editor(new Settings());
		$region = self::origin();
		$mask = Mask::parse('stone', static fn(string $name) : int => 1, static fn(int $state) : bool => $state !== 0);
		$job = $editor->submit('a', $world, ClipboardOperations::capture($world, $region, self::ORIGIN, 1, true, mask: $mask), 2, $region);
		EditorTest::settle($editor, $job);
		self::assertSame(2, $world->read(0, 0, 0)->state);
		$steps = ClipboardOperations::capture($world, $region, self::ORIGIN, 1, true, prepared: static function() : void{ throw new \RuntimeException('Clipboard quota'); });
		$job = $editor->submit('a', $world, $steps, 2, $region);
		EditorTest::settle($editor, $job);
		self::assertSame('failed', $job->status->value);
		self::assertSame(0, $job->changed);
		self::assertSame(2, $world->read(0, 0, 0)->state);
	}

	public function testSmoothingPreservesChangesMadeAfterItsSnapshot() : void{
		$world = new MemoryWorld();
		foreach([[0, 0], [1, 0], [1, 1], [1, 2]] as [$x, $y]){
			$world->set($x, $y, 0, BlockValue::of(1));
		}
		$region = new Region(0, 0, 0, 1, 2, 0);
		$steps = TerrainOperations::smooth($world, $region);
		$steps->rewind();
		for($i = 0; $i < $region->volume(); ++$i){
			$steps->next();
		}
		$world->set(0, 1, 0, new BlockValue(3));
		$editor = new Editor(new Settings());
		$job = $editor->submit('smooth', $world, $steps, 12, $region);
		EditorTest::settle($editor, $job);
		self::assertSame('conflicted', $job->status->value);
		self::assertSame(3, $world->read(0, 1, 0)->state);
	}

	public function testSmoothingRejectsTilePayloadsBeforeChangingTerrain() : void{
		$world = new MemoryWorld();
		$world->set(0, 0, 0, new BlockValue(1, 'exact tile data'));
		$editor = new Editor(new Settings());
		$region = new Region(0, 0, 0, 0, 1, 0);
		$job = $editor->submit('smooth', $world, TerrainOperations::smooth($world, $region), 4, $region);
		EditorTest::settle($editor, $job);
		self::assertSame('failed', $job->status->value);
		self::assertSame(0, $job->changed);
		self::assertSame('exact tile data', $world->read(0, 0, 0)->nbt);
	}

	public function testDisconnectCannotRestartTheOwnerFromACompletionCallback() : void{
		$editor = new Editor(new Settings());
		$world = new MemoryWorld();
		$blocked = false;
		$job = $editor->submit('a', $world, (static function() : \Generator{ yield null; yield null; })(), 2, onDone: static function() use ($editor, $world, &$blocked) : void{
			try{
				$editor->submit('a', $world, (static function() : \Generator{ yield null; })(), 1);
			}catch(\RuntimeException){
				$blocked = true;
			}
		});
		$editor->forget('a');
		self::assertTrue($blocked);
		self::assertTrue($job->isFinished());
		self::assertFalse($editor->hasJobs());
	}

	public function testUnloadedWorldHistoryCanBeReleasedWithoutRemovingOtherWorlds() : void{
		$editor = new Editor(new Settings());
		$first = new MemoryWorld('first');
		$second = new MemoryWorld('second');
		foreach([$first, $second] as $world){
			EditorTest::settle($editor, $editor->submit('a', $world, self::writes(0), 1, self::origin()));
		}
		$editor->forgetWorld('first');
		EditorTest::settle($editor, $editor->restore('a', $second));
		self::assertSame(0, $second->read(0, 0, 0)->state);
		$this->expectException(\RuntimeException::class);
		$editor->restore('a', $first);
	}

	public function testExternallySettledJobCannotKeepItsOwnerAndSchedulerActive() : void{
		$editor = new Editor(new Settings());
		$world = new MemoryWorld();
		$job = $editor->submit('a', $world, self::writes(0), 1, self::origin());
		$job->cancel();
		$editor->tick();
		self::assertSame(JobStatus::Cancelled, $job->status);
		self::assertFalse($editor->hasJobs());
		self::assertNull($editor->active('a'));
		self::assertSame([], $world->blocks);
	}

	public function testShutdownReleasesHistoryEvenDuringAnUndoWrite() : void{
		$editor = new Editor(new Settings());
		$world = new MemoryWorld();
		EditorTest::settle($editor, $editor->submit('a', $world, self::writes(0), 1, self::origin()));
		self::assertGreaterThan(0, $editor->historyBytes());
		$undo = $editor->restore('a', $world);
		$world->afterWrite = $editor->shutdown(...);
		$editor->tick();
		self::assertTrue($undo->isFinished());
		self::assertSame(0, $editor->historyBytes());
		self::assertFalse($editor->hasJobs());
	}

	public function testRepeatedConcurrentCancellationReleasesJobsAndHistory() : void{
		$editor = new Editor(new Settings());
		$world = new MemoryWorld();
		for($cycle = 0; $cycle < 200; ++$cycle){
			$jobs = [];
			$references = [];
			for($owner = 0; $owner < 4; ++$owner){
				$x = $owner * 16;
				$steps = (static function() use ($x, $cycle) : \Generator{
					for($offset = 0; $offset < 8; ++$offset){
						yield [$x + $offset, 0, 0, BlockValue::of($cycle % 3 + 1), null];
					}
				})();
				$jobs[] = $job = $editor->submit('editor-' . $owner, $world, $steps, 8, new Region($x, 0, 0, $x + 7, 0, 0));
				$references[] = \WeakReference::create($job);
			}
			$editor->tick(4);
			$jobs[0]->cancel();
			$jobs[2]->cancel();
			foreach($jobs as $job){
				EditorTest::settle($editor, $job);
				self::assertFalse(str_starts_with((string) $job->error, 'Completion failed'));
			}
			self::assertSame('cancelled', $jobs[0]->status->value);
			self::assertSame('completed', $jobs[1]->status->value);
			for($owner = 0; $owner < 4; ++$owner){
				$editor->forget('editor-' . $owner);
			}
			unset($jobs, $job, $steps);
			foreach($references as $reference){
				self::assertNull($reference->get());
			}
			self::assertFalse($editor->hasJobs());
			self::assertSame(0, $editor->historyBytes());
			self::assertSame(0, $world->pins);
		}
	}
}
