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

require_once __DIR__.'/MemoryWorld.php';

final class StressTest extends TestCase{
	public function testCompressedHistoryAcrossSegmentsAndSignedCoordinates() : void{
		$set = new ChangeSet('world');
		for($i=0;$i<10000;++$i){ $set->append(-$i,-64,$i,new BlockValue(0),new BlockValue(1)); }
		self::assertLessThan(200000,$set->bytes());
		$forward=iterator_to_array($set->changes()); $reverse=iterator_to_array($set->changes(true));
		self::assertCount(10000,$forward);
		foreach($forward as $i=>$record){
			self::assertSame(-$i,$record[0]); self::assertSame($record[0],$reverse[9999-$i][0]);
		}
	}

	public function testRepeatedOwnersCancelUnloadAndDisconnectWithoutRetainedWork() : void{
		$world=new MemoryWorld(); $editor=new Editor(new Settings(blocksPerTick:8));
		$pattern=Pattern::parse('stone',static fn(string $name):int=>1);
		for($cycle=0;$cycle<100;++$cycle){
			$jobs=[];
			for($i=0;$i<4;++$i){
				$r=new Region($i*16,0,0,$i*16+15,0,0);
				$jobs[]=$editor->submit((string)$i,$world,Operations::fill($world,$r,$pattern),$r->volume(),bounds:$r);
			}
			$editor->tick();
			foreach($jobs as $i=>$job){ $editor->forget((string)$i); self::assertTrue($job->isFinished()); }
			self::assertSame(0,$editor->historyBytes()); self::assertSame(0,$world->pins);
			$world->blocks=[];
		}
	}

	public function testInterruptedUndoCanResumeWithoutLosingUnprocessedHistory() : void{
		$world=new MemoryWorld(); $editor=new Editor(new Settings(blocksPerTick:4));
		$r=new Region(0,0,0,15,0,0); $p=Pattern::parse('stone',static fn(string $name):int=>1);
		$job=$editor->submit('a',$world,Operations::fill($world,$r,$p),16,bounds:$r);
		while(!$job->isFinished()){ $editor->tick(); }
		$undo=$editor->restore('a',$world); $editor->tick(); $undo->cancel(); $editor->tick();
		$retry=$editor->restore('a',$world);
		while(!$retry->isFinished()){ $editor->tick(); }
		foreach($world->blocks as $block){ self::assertSame(0,$block->state); }
	}
}
