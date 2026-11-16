<?php

declare(strict_types=1);

namespace Trix\WorldEdit\tests;

use Trix\WorldEdit\Settings;
use Trix\WorldEdit\clipboard\Clipboard;
use Trix\WorldEdit\engine\BlockValue;
use Trix\WorldEdit\engine\ClipboardOperations;
use Trix\WorldEdit\engine\Editor;
use Trix\WorldEdit\engine\Job;
use Trix\WorldEdit\engine\Operations;
use Trix\WorldEdit\engine\TerrainOperations;
use Trix\WorldEdit\geometry\Region;
use Trix\WorldEdit\pattern\Mask;
use Trix\WorldEdit\pattern\Pattern;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/MemoryWorld.php';

final class CopiedOperationsTest extends TestCase{
	private static function finished(Editor $editor, Job $job) : Job{
		EditorTest::settle($editor, $job);
		return $job;
	}

	public function testOperationsRepinChunksWhenResumedInLaterTicks() : void{
		$editor = new Editor(new Settings());
		$region = new Region(-17, 0, -17, 16, 3, 16);
		$pattern = Pattern::parse('stone', static fn(string $name) : int => 1);
		$operations = [
			'fill' => static fn(MemoryWorld $world) => Operations::fill($world, $region, $pattern),
			'walls' => static fn(MemoryWorld $world) => Operations::fill($world, new Region(-17, 0, -17, 16, 3, 16, 'cylinder'), $pattern, 'walls'),
			'overlay' => static fn(MemoryWorld $world) => TerrainOperations::overlay($world, $region, $pattern),
			'naturalize' => static fn(MemoryWorld $world) => TerrainOperations::naturalize($world, $region, 3, 2, 1),
			'smooth' => static fn(MemoryWorld $world) => TerrainOperations::smooth($world, $region),
			'copy' => static fn(MemoryWorld $world) => ClipboardOperations::capture($world, $region, [0, 0, 0], 100000, true),
			'pyramid' => static fn(MemoryWorld $world) => Operations::pyramid($world, 0, 0, 0, 3, $pattern, true),
			'count' => static fn(MemoryWorld $world) => Operations::distribution($world, $region),
		];
		foreach($operations as $name => $operation){
			// Sparse columns make operations read, yield and resume within one column.
			$world = new MemoryWorld();
			$world->set(-17, 0, -17, BlockValue::of(1));
			$world->set(5, 1, 5, BlockValue::of(1));
			$world->strict = true;
			$job = $editor->submit('a', $world, $operation($world), 100000, $region->expand(1, 'up'));
			for($tick = 0; !$job->isFinished() && $tick < 100000; ++$tick){
				$editor->tick(1);
			}
			self::assertNotSame('failed', $job->status->value, "$name: {$job->error}");
		}
	}

	public function testCopyAndRotatedPastePreserveOffsetsAndUndo() : void{
		$editor = new Editor(new Settings());
		$world = new MemoryWorld();
		$world->set(-10, 0, -20, BlockValue::of(1));
		$world->set(-9, 0, -20, BlockValue::of(2));
		$region = new Region(-10, 0, -20, -9, 0, -20);
		$copy = self::finished($editor, $editor->submit('a', $world, ClipboardOperations::capture($world, $region, [-12, 0, -18], 10), $region->volume(), $region, record: false));
		self::assertSame('completed', $copy->status->value);
		self::assertInstanceOf(Clipboard::class, $copy->result);
		self::assertSame(0, $copy->changed);
		$clipboard = $copy->result->rotate(90);
		$paste = self::finished($editor, $editor->submit('a', $world, ClipboardOperations::paste($world, $clipboard, [10, 0, 10]), $clipboard->volume(), $clipboard->bounds([10, 0, 10])));
		self::assertSame('completed', $paste->status->value);
		self::assertSame(1, $world->read(12, 0, 12)->state);
		self::assertSame(2, $world->read(12, 0, 13)->state);
		self::finished($editor, $editor->restore('a', $world));
		self::assertSame(0, $world->read(12, 0, 12)->state);
		self::assertSame(1, $world->read(-10, 0, -20)->state);
	}

	public function testCutPreservesTileNbtAndCanBeUndone() : void{
		$editor = new Editor(new Settings());
		$world = new MemoryWorld();
		$world->set(0, 0, 0, new BlockValue(1, 'tile-data'));
		$region = new Region(0, 0, 0, 0, 0, 0);
		$cut = self::finished($editor, $editor->submit('a', $world, ClipboardOperations::capture($world, $region, [0, 0, 0], 10, true), 2, $region));
		self::assertSame('completed', $cut->status->value);
		self::assertInstanceOf(Clipboard::class, $cut->result);
		self::assertSame('tile-data', $cut->result->at(0, 0, 0)->nbt);
		self::assertSame(0, $world->read(0, 0, 0)->state);
		self::finished($editor, $editor->restore('a', $world));
		self::assertSame('tile-data', $world->read(0, 0, 0)->nbt);
	}

	public function testFailedAndCancelledCopiesProduceNoClipboard() : void{
		$editor = new Editor(new Settings());
		$world = new MemoryWorld();
		$region = new Region(0, 0, 0, 3, 0, 0);
		$copy = $editor->submit('a', $world, ClipboardOperations::capture($world, $region, [0, 0, 0], 10), 4, $region);
		$editor->tick(1);
		$copy->cancel();
		self::finished($editor, $copy);
		self::assertNull($copy->result);
		$world->set(0, 0, 0, BlockValue::of(1));
		$world->fail = true;
		$cut = self::finished($editor, $editor->submit('a', $world, ClipboardOperations::capture($world, $region, [0, 0, 0], 10, true), 8, $region));
		self::assertSame('failed', $cut->status->value);
		self::assertNull($cut->result);
		self::assertSame(1, $world->read(0, 0, 0)->state);
	}

	public function testPasteHonorsAirAndTargetMask() : void{
		$editor = new Editor(new Settings());
		$world = new MemoryWorld();
		$world->set(0, 0, 0, new BlockValue(9));
		$world->set(1, 0, 0, new BlockValue(9));
		$clipboard = new Clipboard(3, 1, 1, pack('V*', 0, 2, 3));
		$mask = Mask::parse('#air', static fn(string $name) : int => 0, static fn(int $state) : bool => $state !== 0);
		$paste = self::finished($editor, $editor->submit('a', $world, ClipboardOperations::paste($world, $clipboard, [0, 0, 0], true), 3, $clipboard->bounds([0, 0, 0]), $mask));
		self::assertSame(1, $paste->changed);
		self::assertSame(9, $world->read(0, 0, 0)->state);
		self::assertSame(9, $world->read(1, 0, 0)->state);
		self::assertSame(3, $world->read(2, 0, 0)->state);
	}

	public function testSourceMaskedPasteOnlyPlacesMatchingBlocks() : void{
		$editor = new Editor(new Settings());
		$world = new MemoryWorld();
		$clipboard = new Clipboard(2, 1, 1, pack('V*', 5, 6));
		$mask = Mask::parse('five', static fn(string $name) : int => 5, static fn(int $state) : bool => true);
		self::finished($editor, $editor->submit('a', $world, ClipboardOperations::paste($world, $clipboard, [0, 0, 0], source: $mask), 2, $clipboard->bounds([0, 0, 0])));
		self::assertSame(5, $world->read(0, 0, 0)->state);
		self::assertSame(0, $world->read(1, 0, 0)->state);
	}

	public function testMaskedMoveDoesNotDuplicateUnmaskedBlocks() : void{
		$editor = new Editor(new Settings());
		$world = new MemoryWorld();
		$world->set(0, 0, 0, BlockValue::of(5));
		$world->set(1, 0, 0, BlockValue::of(6));
		$mask = Mask::parse('five', static fn(string $name) : int => 5, static fn(int $state) : bool => true);
		$region = new Region(0, 0, 0, 1, 0, 0);
		$steps = (static function() use ($world, $region, $mask) : \Generator{
			$clipboard = yield from ClipboardOperations::capture($world, $region, [0, 0, 0], 10, true, 0, $mask);
			yield from ClipboardOperations::paste($world, $clipboard, [0, 1, 0], source: $mask);
		})();
		self::finished($editor, $editor->submit('a', $world, $steps, 6, new Region(0, 0, 0, 1, 1, 0)));
		self::assertSame(0, $world->read(0, 0, 0)->state);
		self::assertSame(6, $world->read(1, 0, 0)->state);
		self::assertSame(5, $world->read(0, 1, 0)->state);
		self::assertSame(0, $world->read(1, 1, 0)->state);
	}

	public function testCurvedCopyUsesAirOutsideShape() : void{
		$editor = new Editor(new Settings());
		$world = new MemoryWorld();
		$region = new Region(-1, -1, -1, 1, 1, 1, 'ellipsoid');
		$world->set(-1, -1, -1, new BlockValue(9));
		$world->set(0, 0, 0, new BlockValue(2));
		$copy = self::finished($editor, $editor->submit('a', $world, ClipboardOperations::capture($world, $region, [0, 0, 0], 100), 27, $region, record: false));
		self::assertInstanceOf(Clipboard::class, $copy->result);
		self::assertSame(0, $copy->result->at(0, 0, 0)->state);
		self::assertSame(2, $copy->result->at(1, 1, 1)->state);
	}

	public function testOverlayUsesTopSurfaceAndRespectsWorldHeight() : void{
		$editor = new Editor(new Settings());
		$world = new MemoryWorld();
		$world->set(0, 0, 0, BlockValue::of(1));
		$world->set(1, 2, 0, BlockValue::of(1));
		$region = new Region(0, 0, 0, 1, 2, 0);
		$pattern = Pattern::parse('grass', static fn(string $name) : int => 3);
		$job = self::finished($editor, $editor->submit('a', $world, TerrainOperations::overlay($world, $region, $pattern), 8, $region->expand(1, 'up')));
		self::assertSame('completed', $job->status->value);
		self::assertSame(2, $job->changed);
		self::assertSame(3, $world->read(0, 1, 0)->state);
		self::assertSame(3, $world->read(1, 3, 0)->state);
		$world->set(0, 319, 0, BlockValue::of(1));
		$top = new Region(0, 319, 0, 0, 319, 0);
		$job = self::finished($editor, $editor->submit('a', $world, TerrainOperations::overlay($world, $top, $pattern), 2, $top));
		self::assertSame(0, $job->changed);
	}

	public function testNaturalizePreservesCaveAirAndLayersByDepth() : void{
		$editor = new Editor(new Settings());
		$world = new MemoryWorld();
		for($y = -5; $y <= 1; ++$y){
			if($y !== -1){
				$world->set(0, $y, 0, new BlockValue(9));
			}
		}
		$region = new Region(0, -5, 0, 0, 2, 0);
		$job = self::finished($editor, $editor->submit('a', $world, TerrainOperations::naturalize($world, $region, 3, 2, 1), $region->volume(), $region));
		self::assertSame('completed', $job->status->value);
		self::assertSame(3, $world->read(0, 1, 0)->state);
		self::assertSame(2, $world->read(0, 0, 0)->state);
		self::assertSame(0, $world->read(0, -1, 0)->state);
		self::assertSame(2, $world->read(0, -2, 0)->state);
		self::assertSame(1, $world->read(0, -3, 0)->state);
	}

	public function testSmoothSnapshotsAllColumnsAndLeavesCavesBelowSurface() : void{
		$editor = new Editor(new Settings());
		$world = new MemoryWorld();
		for($x = -1; $x <= 1; ++$x){
			for($y = -1, $height = $x === 0 ? 3 : 1; $y <= $height; ++$y){
				if($y !== 0){
					$world->set($x, $y, 0, new BlockValue(4));
				}
			}
		}
		$region = new Region(-1, -1, 0, 1, 3, 0);
		$job = self::finished($editor, $editor->submit('a', $world, TerrainOperations::smooth($world, $region), $region->volume() * 2, $region));
		self::assertSame('completed', $job->status->value);
		self::assertSame(4, $world->read(-1, 2, 0)->state);
		self::assertSame(4, $world->read(1, 2, 0)->state);
		self::assertSame(0, $world->read(0, 2, 0)->state);
		self::assertSame(0, $world->read(0, 3, 0)->state);
		self::assertSame(0, $world->read(0, 0, 0)->state);
		self::finished($editor, $editor->restore('a', $world));
		self::assertSame(4, $world->read(0, 3, 0)->state);
		self::assertSame(0, $world->read(-1, 2, 0)->state);
	}
}
