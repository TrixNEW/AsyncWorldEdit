<?php

declare(strict_types=1);

namespace Trix\WorldEdit\tests;

use Trix\WorldEdit\Schematics;
use Trix\WorldEdit\schematic\SchematicCodec;
use Trix\WorldEdit\schematic\SchematicData;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use pocketmine\nbt\tag\CompoundTag;
use quark\player\Player;
use ReflectionClass;
use ReflectionMethod;

final class SchematicImportTest extends TestCase{

	public function testPortableCodecPreservesForeignBlockEntityFields() : void{
		$codec = new SchematicCodec();
		foreach([2, 3] as $version){
			$data = $codec->decode($codec->encode($this->javaBeacon(), version: $version));
			self::assertSame('minecraft:beacon', $data->tiles[0]->getString('id'));
			self::assertSame(1, $data->tiles[0]->getInt('Primary'));
			self::assertSame(2, $data->tiles[0]->getInt('Secondary'));
			self::assertNull($data->tiles[0]->getTag('primary'));
		}
	}

	public function testImportRejectsForeignBlockEntitiesBeforeTouchingPlayerOrClipboard() : void{
		if(!extension_loaded('pmmpthread') || !is_file(dirname(__DIR__, 2) . '/Quark/vendor/autoload.php')){
			self::markTestSkipped('Requires the Quark runtime and sibling source checkout');
		}
		require_once dirname(__DIR__, 2) . '/Quark/vendor/autoload.php';
		$loader = require dirname(__DIR__) . '/vendor/autoload.php';
		$loader->unregister();
		$loader->register(true);
		$schematics = (new ReflectionClass(Schematics::class))->newInstanceWithoutConstructor();
		$player = new class extends Player{
			public function __construct(){}
			public function __destruct(){}
		};
		try{
			(new ReflectionMethod(Schematics::class, "import"))->invoke($schematics, $player, $this->javaBeacon());
			self::fail("Accepted Java block entity data without a converter");
		}catch(InvalidArgumentException $e){
			self::assertStringContainsString("Java block entity conversion is not supported", $e->getMessage());
		}
	}

	private function javaBeacon() : SchematicData{
		$tile = CompoundTag::create()->setString('id', 'minecraft:beacon')->setInt('x', 0)->setInt('y', 0)->setInt('z', 0)->setInt('Primary', 1)->setInt('Secondary', 2);
		return new SchematicData(1, 1, 1, pack('V', 0), ['minecraft:beacon'], tiles: [0 => $tile]);
	}
}
