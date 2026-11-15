<?php

declare(strict_types=1);

namespace asyncworldedit\tests;

use asyncworldedit\Settings;
use asyncworldedit\clipboard\Clipboard;
use asyncworldedit\engine\BlockValue;
use asyncworldedit\engine\ClipboardOperations;
use asyncworldedit\engine\Editor;
use asyncworldedit\engine\Job;
use asyncworldedit\engine\TerrainOperations;
use asyncworldedit\geometry\Region;
use asyncworldedit\pattern\Mask;
use asyncworldedit\pattern\Pattern;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . "/MemoryWorld.php";

final class CopiedOperationsTest extends TestCase{

	private static function settle(Editor $editor, Job $job) : void{
		for($tick = 0; !$job->isFinished() && $tick < 10000; ++$tick){
			$editor->tick();
		}
		self::assertTrue($job->isFinished());
	}

	public function testCopyAndRotatedPastePreserveOffsetsAndUndo() : void{
		$editor = new Editor(new Settings());
		$world = new MemoryWorld();
		$world->write(-10, 0, -20, new BlockValue(1));
		$world->write(-9, 0, -20, new BlockValue(2));
		$region = new Region(-10, 0, -20, -9, 0, -20);
		$clipboard = null;
		$copy = $editor->submit("a", $world, ClipboardOperations::capture($world, $region, [-12, 0, -18], 10, static function(Clipboard $result) use (&$clipboard) : void{
			$clipboard = $result;
		}), $region->volume(), record: false, bounds: $region);
		self::settle($editor, $copy);
		self::assertSame("completed", $copy->status);
		self::assertInstanceOf(Clipboard::class, $clipboard);
		self::assertSame(0, $copy->changed);
		$clipboard = $clipboard->rotate(90);
		$paste = $editor->submit("a", $world, ClipboardOperations::paste($world, $clipboard, [10, 0, 10]), $clipboard->volume(), bounds: $clipboard->bounds([10, 0, 10]));
		self::settle($editor, $paste);
		self::assertSame("completed", $paste->status);
		self::assertSame(1, $world->read(12, 0, 12)->state);
		self::assertSame(2, $world->read(12, 0, 13)->state);
		$undo = $editor->restore("a", $world);
		self::settle($editor, $undo);
		self::assertSame(0, $world->read(12, 0, 12)->state);
		self::assertSame(1, $world->read(-10, 0, -20)->state);
	}

	public function testCutPreservesTileNbtAndCanBeUndone() : void{
		$editor = new Editor(new Settings());
		$world = new MemoryWorld();
		$world->write(0, 0, 0, new BlockValue(1, "tile-data"));
		$clipboard = null;
		$region = new Region(0, 0, 0, 0, 0, 0);
		$cut = $editor->submit("a", $world, ClipboardOperations::capture($world, $region, [0, 0, 0], 10, static function(Clipboard $result) use (&$clipboard) : void{
			$clipboard = $result;
		}, true), 2, bounds: $region);
		self::settle($editor, $cut);
		self::assertSame("completed", $cut->status);
		self::assertInstanceOf(Clipboard::class, $clipboard);
		self::assertSame("tile-data", $clipboard->at(0, 0, 0)->nbt);
		self::assertSame(0, $world->read(0, 0, 0)->state);
		$undo = $editor->restore("a", $world);
		self::settle($editor, $undo);
		self::assertSame("tile-data", $world->read(0, 0, 0)->nbt);
	}

	public function testFailedAndCancelledCopiesPreservePreviousClipboard() : void{
		$editor = new Editor(new Settings(blocksPerTick: 1));
		$world = new MemoryWorld();
		$previous = new Clipboard(1, 1, 1, pack("V", 9));
		$clipboard = $previous;
		$save = static function(Clipboard $result) use (&$clipboard) : void{ $clipboard = $result; };
		$region = new Region(0, 0, 0, 3, 0, 0);
		$copy = $editor->submit("a", $world, ClipboardOperations::capture($world, $region, [0, 0, 0], 10, $save), 4, bounds: $region);
		$editor->tick();
		$copy->cancel();
		self::settle($editor, $copy);
		self::assertSame($previous, $clipboard);
		$world->write(0, 0, 0, new BlockValue(1));
		$world->fail = true;
		$cut = $editor->submit("a", $world, ClipboardOperations::capture($world, $region, [0, 0, 0], 10, $save, true), 8, bounds: $region);
		self::settle($editor, $cut);
		self::assertSame("failed", $cut->status);
		self::assertSame($previous, $clipboard);
		self::assertSame(1, $world->read(0, 0, 0)->state);
	}

	public function testPasteHonorsAirAndTargetMask() : void{
		$editor = new Editor(new Settings());
		$world = new MemoryWorld();
		$world->write(0, 0, 0, new BlockValue(9));
		$world->write(1, 0, 0, new BlockValue(9));
		$clipboard = new Clipboard(3, 1, 1, pack("V*", 0, 2, 3));
		$mask = Mask::parse("#air", static fn(string $name) : int => 0, static fn(int $state) : bool => $state !== 0);
		$paste = $editor->submit("a", $world, ClipboardOperations::paste($world, $clipboard, [0, 0, 0], true, mask: $mask), 3, bounds: $clipboard->bounds([0, 0, 0]));
		self::settle($editor, $paste);
		self::assertSame(1, $paste->changed);
		self::assertSame(9, $world->read(0, 0, 0)->state);
		self::assertSame(9, $world->read(1, 0, 0)->state);
		self::assertSame(3, $world->read(2, 0, 0)->state);
	}

	public function testCurvedCopyUsesAirOutsideShape() : void{
		$editor = new Editor(new Settings());
		$world = new MemoryWorld();
		$region = new Region(-1, -1, -1, 1, 1, 1, "ellipsoid");
		$world->write(-1, -1, -1, new BlockValue(9));
		$world->write(0, 0, 0, new BlockValue(2));
		$clipboard = null;
		$copy = $editor->submit("a", $world, ClipboardOperations::capture($world, $region, [0, 0, 0], 100, static function(Clipboard $result) use (&$clipboard) : void{
			$clipboard = $result;
		}), 27, record: false, bounds: $region);
		self::settle($editor, $copy);
		self::assertInstanceOf(Clipboard::class, $clipboard);
		self::assertSame(0, $clipboard->at(0, 0, 0)->state);
		self::assertSame(2, $clipboard->at(1, 1, 1)->state);
	}

	public function testOverlayUsesTopSurfaceAndRespectsWorldHeight() : void{
		$editor = new Editor(new Settings());
		$world = new MemoryWorld();
		$world->write(0, 0, 0, new BlockValue(1));
		$world->write(1, 2, 0, new BlockValue(1));
		$region = new Region(0, 0, 0, 1, 2, 0);
		$pattern = Pattern::parse("grass", static fn(string $name) : int => 3);
		$job = $editor->submit("a", $world, TerrainOperations::overlay($world, $region, $pattern), 8, bounds: $region->expand(1, "up"));
		self::settle($editor, $job);
		self::assertSame("completed", $job->status);
		self::assertSame(2, $job->changed);
		self::assertSame(3, $world->read(0, 1, 0)->state);
		self::assertSame(3, $world->read(1, 3, 0)->state);
		$world->write(0, 319, 0, new BlockValue(1));
		$top = new Region(0, 319, 0, 0, 319, 0);
		$job = $editor->submit("a", $world, TerrainOperations::overlay($world, $top, $pattern), 2, bounds: $top);
		self::settle($editor, $job);
		self::assertSame(0, $job->changed);
	}

	public function testNaturalizePreservesCaveAirAndLayersByDepth() : void{
		$editor = new Editor(new Settings());
		$world = new MemoryWorld();
		for($y = -5; $y <= 1; ++$y){
			if($y !== -1){ $world->write(0, $y, 0, new BlockValue(9)); }
		}
		$region = new Region(0, -5, 0, 0, 2, 0);
		$job = $editor->submit("a", $world, TerrainOperations::naturalize($world, $region, 3, 2, 1), $region->volume(), bounds: $region);
		self::settle($editor, $job);
		self::assertSame("completed", $job->status);
		self::assertSame(3, $world->read(0, 1, 0)->state);
		self::assertSame(2, $world->read(0, 0, 0)->state);
		self::assertSame(0, $world->read(0, -1, 0)->state);
		self::assertSame(2, $world->read(0, -2, 0)->state);
		self::assertSame(1, $world->read(0, -3, 0)->state);
	}

	public function testSmoothSnapshotsAllColumnsAndLeavesCavesBelowSurface() : void{
		$editor = new Editor(new Settings(blocksPerTick: 2));
		$world = new MemoryWorld();
		for($x = -1; $x <= 1; ++$x){
			$height = $x === 0 ? 3 : 1;
			for($y = -1; $y <= $height; ++$y){
				if($y !== 0){ $world->write($x, $y, 0, new BlockValue(4)); }
			}
		}
		$region = new Region(-1, -1, 0, 1, 3, 0);
		$job = $editor->submit("a", $world, TerrainOperations::smooth($world, $region), $region->volume() * 2, bounds: $region);
		self::settle($editor, $job);
		self::assertSame("completed", $job->status);
		self::assertSame(4, $world->read(-1, 2, 0)->state);
		self::assertSame(4, $world->read(1, 2, 0)->state);
		self::assertSame(0, $world->read(0, 2, 0)->state);
		self::assertSame(0, $world->read(0, 3, 0)->state);
		self::assertSame(0, $world->read(0, 0, 0)->state);
		$undo = $editor->restore("a", $world);
		self::settle($editor, $undo);
		self::assertSame(4, $world->read(0, 3, 0)->state);
		self::assertSame(0, $world->read(-1, 2, 0)->state);
	}
}
