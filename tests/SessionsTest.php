<?php

declare(strict_types=1);

namespace Trix\WorldEdit\tests;

use Trix\WorldEdit\Session;
use Trix\WorldEdit\Sessions;
use Trix\WorldEdit\clipboard\Clipboard;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SessionsTest extends TestCase{
	/** @return array{Sessions, Session, Session} */
	private static function sessions(int $limit = 1048576) : array{
		$sessions = new Sessions($limit);
		$first = new Session();
		$second = new Session();
		(new \ReflectionProperty(Sessions::class, 'sessions'))->setValue($sessions, ['first' => $first, 'second' => $second]);
		return [$sessions, $first, $second];
	}

	public function testHeldClipboardBlocksOtherUseUntilReleased() : void{
		[$sessions, $first, $second] = self::sessions();
		$full = new Clipboard(262144, 1, 1, str_repeat("\0", 1048576), maxBlocks: 262144);
		$small = new Clipboard(1, 1, 1, pack('V', 1));
		$sessions->hold($first, $full);
		try{
			$sessions->setClipboard($second, $small);
			self::fail('The held clipboard was not counted');
		}catch(RuntimeException){
			self::assertNull($second->clipboard);
		}
		try{
			$sessions->setClipboard($first, $small);
			self::fail('A busy clipboard was replaced');
		}catch(RuntimeException){
			self::assertNull($first->clipboard);
		}
		$sessions->setClipboard($first, $full);
		self::assertSame($full, $first->clipboard);
		self::assertNull($first->pending);
		$sessions->setClipboard($first, null);
		$sessions->setClipboard($second, $small);
		self::assertSame($small, $second->clipboard);
	}

	public function testCaptureCountsTheOldAndNewClipboardTogether() : void{
		[$sessions, $first] = self::sessions();
		$half = new Clipboard(131072, 1, 1, str_repeat("\0", 524288));
		$larger = new Clipboard(131073, 1, 1, str_repeat("\0", 524292));
		$sessions->setClipboard($first, $half);
		$this->expectException(RuntimeException::class);
		$sessions->hold($first, $larger);
	}

	public function testFailedAdmissionKeepsThePreviousClipboard() : void{
		[$sessions, $first, $second] = self::sessions();
		$half = new Clipboard(131072, 1, 1, str_repeat("\0", 524288));
		$larger = new Clipboard(131073, 1, 1, str_repeat("\0", 524292));
		$sessions->setClipboard($first, $half);
		$sessions->setClipboard($second, $half);
		try{
			$sessions->setClipboard($second, $larger);
			self::fail('Admitted clipboards above the shared memory limit');
		}catch(RuntimeException){
			self::assertSame($half, $second->clipboard);
		}
		$sessions->setClipboard($first, null);
		$sessions->setClipboard($second, $larger);
		self::assertSame($larger, $second->clipboard);
		// Rotating shares storage and keeps the same size, so it always fits.
		$sessions->setClipboard($second, $larger->rotate(90));
		self::assertNotSame($larger, $second->clipboard);
	}
}
