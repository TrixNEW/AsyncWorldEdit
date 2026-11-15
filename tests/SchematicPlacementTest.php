<?php

declare(strict_types=1);

namespace asyncworldedit\tests;

use asyncworldedit\schematic\SchematicCodec;
use asyncworldedit\schematic\SchematicData;
use pocketmine\nbt\tag\CompoundTag;
use PHPUnit\Framework\TestCase;

final class SchematicPlacementTest extends TestCase{
	public function testWorldEditV2V3OriginsSurviveVersionConversion() : void{
		$metadata = CompoundTag::create()->setInt('WEOffsetX', -2)->setInt('WEOffsetY', 0)->setInt('WEOffsetZ', -3);
		$data = new SchematicData(1,1,1,pack('V',0),['minecraft:air'],[98,64,97],metadata:$metadata,formatVersion:2);
		$expected = ['min'=>[98,64,97], 'origin'=>[100,64,100]];
		self::assertSame($expected,$data->placement());
		$codec = new SchematicCodec();
		$v3 = $codec->decode($codec->encode($data,'schem',3));
		self::assertSame([-2,0,-3],$v3->origin);
		self::assertSame($expected,$v3->placement());
		$v2 = $codec->decode($codec->encode($v3,'schem',2));
		self::assertSame($expected,$v2->placement());
	}

	public function testBedrockExtensionPreservesNonzeroCopyPivot() : void{
		$palette = [CompoundTag::create()->setString('name','minecraft:air')->setTag('states',CompoundTag::create())];
		$metadata = CompoundTag::create()->setIntArray('ClipboardOrigin',[-100,64,100]);
		$data = new SchematicData(1,1,1,pack('V',0),['minecraft:air'],[-102,64,97],format:'mcstructure',bedrockPalette:$palette,metadata:$metadata,formatVersion:1);
		$codec = new SchematicCodec();
		$copy = $codec->decode($codec->encode($data,'mcstructure'),'mcstructure');
		self::assertSame(['min'=>[-102,64,97],'origin'=>[-100,64,100]],$copy->placement());
	}

	public function testIncompleteMetadataIsRejected() : void{
		$this->expectException(\InvalidArgumentException::class);
		new SchematicData(1,1,1,pack('V',0),['minecraft:air'],metadata:CompoundTag::create()->setInt('WEOffsetX',1),formatVersion:2);
	}
}
