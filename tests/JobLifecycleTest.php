<?php

declare(strict_types=1);

namespace asyncworldedit\tests;

use asyncworldedit\Settings;
use asyncworldedit\engine\Editor;
use asyncworldedit\engine\Job;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/MemoryWorld.php';

final class JobLifecycleTest extends TestCase{
	public function testNotificationFailureDoesNotBreakSchedulerOrRetainGenerator() : void{
		$editor=new Editor(new Settings()); $world=new MemoryWorld();
		$steps=(static function() : \Generator{ yield null; })();
		$job=$editor->submit('a',$world,$steps,1,static function(Job $job) : void{ throw new \RuntimeException('callback failed'); });
		$editor->tick();
		self::assertSame('completed',$job->status);
		self::assertSame('callback failed',$job->notificationError);
		self::assertNull($job->steps);
		$next=$editor->submit('a',$world,(static function() : \Generator{ yield null; })(),1);
		$editor->tick(); self::assertTrue($next->isFinished());
	}

	public function testReentrantDisconnectReleasesHistoryAfterCompletion() : void{
		$editor=new Editor(new Settings()); $world=new MemoryWorld();
		$steps=(static function() use($editor) : \Generator{
			$editor->forget('a'); yield null;
		})();
		$job=$editor->submit('a',$world,$steps,1);
		$editor->tick();
		self::assertSame('cancelled',$job->status); self::assertSame(0,$editor->historyBytes());
	}
}
