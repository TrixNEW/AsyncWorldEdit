<?php

declare(strict_types=1);

namespace asyncworldedit\tests;

use asyncworldedit\Main;
use asyncworldedit\Session;
use asyncworldedit\Settings;
use asyncworldedit\clipboard\Clipboard;
use asyncworldedit\engine\Editor;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use RuntimeException;

final class MainTest extends TestCase{

	public function testGlobalClipboardAdmissionPreservesPreviousClipboardOnFailure() : void{
		if(!extension_loaded('pmmpthread') || !is_file(dirname(__DIR__, 2) . '/Quark/vendor/autoload.php')){
			self::markTestSkipped('Requires the Quark runtime and sibling source checkout');
		}
		require_once dirname(__DIR__, 2) . '/Quark/vendor/autoload.php';
		$loader = require dirname(__DIR__) . '/vendor/autoload.php';
		$loader->unregister();
		$loader->register(true);
		$plugin = (new ReflectionClass(Main::class))->newInstanceWithoutConstructor();
		$editor = new Editor(new Settings(clipboardBytes: 1048576));
		(new ReflectionProperty(Main::class, 'editor'))->setValue($plugin, $editor);
		$first = new Session();
		$second = new Session();
		(new ReflectionProperty(Main::class, 'sessions'))->setValue($plugin, ['first' => $first, 'second' => $second]);
		$half = new Clipboard(131072, 1, 1, str_repeat("\0", 524288));
		$larger = new Clipboard(131073, 1, 1, str_repeat("\0", 524292));
		$plugin->setClipboard($first, $half);
		$plugin->setClipboard($second, $half);
		try{
			$plugin->setClipboard($second, $larger);
			self::fail('Admitted clipboards above the global memory limit');
		}catch(RuntimeException){
			self::assertSame($half, $second->clipboard);
		}
		$plugin->setClipboard($first, null);
		$plugin->setClipboard($second, $larger);
		self::assertNull($first->clipboard);
		self::assertSame($larger, $second->clipboard);
	}
}
