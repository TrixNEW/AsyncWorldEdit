<?php

declare(strict_types=1);

namespace asyncworldedit\tests;

use asyncworldedit\schematic\SchematicCodec;
use asyncworldedit\schematic\SchematicData;
use asyncworldedit\schematic\SchematicFileStore;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use pocketmine\nbt\BigEndianNbtSerializer;
use pocketmine\nbt\LittleEndianNbtSerializer;
use pocketmine\nbt\NBT;
use pocketmine\nbt\TreeRoot;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\IntTag;
use pocketmine\nbt\tag\ListTag;
use RuntimeException;
use UnexpectedValueException;

final class SchematicTest extends TestCase{

	public function testSpongeVersionsPreservePackedOrderOffsetsStatesAndTiles() : void{
		$codec = new SchematicCodec();
		$tiles = [5 => CompoundTag::create()->setString("id", "minecraft:chest")->setInt("x", 2)->setInt("y", 0)->setInt("z", 1)->setString("CustomName", "Fixture")];
		$data = new SchematicData(3, 2, 2, pack("V*", 0, 1, 0, 1, 0, 1, 1, 0, 1, 0, 1, 0), ["minecraft:air", "minecraft:oak_stairs[facing=north,half=bottom]"], [-12, 20, 8], $tiles, dataVersion: 4189, metadata: CompoundTag::create()->setIntArray("Origin", [-4, 8, 16]));
		foreach([2, 3] as $version){
			$bytes = $codec->encode($data, "schem", $version);
			$copy = $codec->decode($bytes, "schem");
			self::assertSame($data->states, $copy->states);
			self::assertSame($data->palette, $copy->palette);
			self::assertSame($data->origin, $copy->origin);
			self::assertSame(4189, $copy->dataVersion);
			$serializer = new BigEndianNbtSerializer();
			$customMetadata = CompoundTag::create()->setTag('Origin', clone $copy->metadata->getTag('Origin'));
			self::assertSame($serializer->write(new TreeRoot($data->metadata)), $serializer->write(new TreeRoot($customMetadata)));
			self::assertSame($data->placement(), $copy->placement());
			self::assertTrue($tiles[5]->equals($copy->tiles[5]));
			$offset = 0;
			$root = (new BigEndianNbtSerializer())->read(gzdecode($bytes), $offset)->mustGetCompoundTag();
			self::assertSame($version === 3, $root->getCompoundTag("Schematic") !== null);
		}
	}

	public function testMcstructurePreservesTypedPaletteAndChangesAxisOrder() : void{
		$codec = new SchematicCodec();
		$palette = [
			CompoundTag::create()->setString("name", "minecraft:air")->setTag("states", CompoundTag::create())->setInt("version", 18168865),
			CompoundTag::create()->setString("name", "minecraft:oak_stairs")->setTag("states", CompoundTag::create()->setInt("weirdo_direction", 2)->setByte("upside_down_bit", 1)->setString("test", "north"))->setInt("version", 18168865)
		];
		$states = pack("V*", 0, 1, 0, 1, 1, 0, 1, 0, 0, 1, 1, 0);
		$tile = CompoundTag::create()->setString("id", "Chest")->setInt("x", 1)->setInt("y", 1)->setInt("z", 2)->setString("CustomName", "Kept");
		$data = new SchematicData(2, 2, 3, $states, ["minecraft:air", 'minecraft:oak_stairs[weirdo_direction=2,upside_down_bit=1,test="north"]'], [-3, 80, 12], [11 => $tile], "mcstructure", $palette);
		$bytes = $codec->encode($data, "mcstructure");
		$copy = $codec->decode($bytes, "mcstructure");
		self::assertSame($states, $copy->states);
		self::assertSame($data->origin, $copy->origin);
		self::assertSame($data->palette, $copy->palette);
		self::assertTrue($palette[1]->equals($copy->bedrockPalette[1]));
		self::assertTrue($tile->equals($copy->tiles[11]));
		$offset = 0;
		$root = (new LittleEndianNbtSerializer())->read($bytes, $offset)->mustGetCompoundTag();
		$primary = $root->getCompoundTag("structure")->getListTag("block_indices")->get(0);
		self::assertSame([0, 0, 1, 1, 0, 1, 1, 1, 0, 0, 1, 0], array_map(static fn(IntTag $value) : int => $value->getValue(), $primary->getValue()));
		self::assertSame($bytes, $codec->encode($copy, "mcstructure"));
	}

	public function testMalformedDimensionsPalettesAndVarintsAreRejected() : void{
		$codec = new SchematicCodec();
		$valid = $this->spongeRoot();
		$invalid = [];
		$invalid[] = (clone $valid)->setShort("Width", 0);
		$invalid[] = (clone $valid)->setShort("Height", -1)->setShort("Length", -1);
		$invalid[] = (clone $valid)->setByteArray("BlockData", "");
		$invalid[] = (clone $valid)->setByteArray("BlockData", "\x80");
		$invalid[] = (clone $valid)->setByteArray("BlockData", "\x80\x00");
		$invalid[] = (clone $valid)->setByteArray("BlockData", "\xff\xff\xff\xff\x7f");
		$invalid[] = (clone $valid)->setByteArray("BlockData", "\0\0");
		$invalid[] = (clone $valid)->setByteArray("BlockData", "\x01");
		$invalid[] = (clone $valid)->setTag("Palette", CompoundTag::create()->setInt("minecraft:air", 1));
		$invalid[] = (clone $valid)->setIntArray("Offset", [1, 2]);
		$invalid[] = (clone $valid)->setTag("Entities", new ListTag([CompoundTag::create()], NBT::TAG_Compound));
		foreach($invalid as $root){
			$this->assertRejected(fn() => $codec->decode((new BigEndianNbtSerializer())->write(new TreeRoot($root))));
		}
	}

	public function testTruncationCompressionAndAllocationBombsAreRejected() : void{
		$codec = new SchematicCodec(maxDecodedBytes: 1024);
		$raw = (new BigEndianNbtSerializer())->write(new TreeRoot($this->spongeRoot()));
		foreach([substr($raw, 0, -1), $raw . "\0", "\x0a\0\0\x09\0\0\x0a\x7f\xff\xff\xff\0", "\x0a\0\0\x0b\0\0\x7f\xff\xff\xff\0", gzencode(str_repeat("\0", 2048)), "\x1f\x8bBAD"] as $bytes){
			$this->assertRejected(fn() => $codec->decode($bytes));
		}
		$this->assertRejected(fn() => (new SchematicCodec(maxFileBytes: 5))->decode($raw));
		$this->assertRejected(fn() => (new SchematicCodec(maxNbtNodes: 3))->decode($raw));
		$this->assertRejected(fn() => $codec->decode($raw, "schematic"));
	}

	public function testSecondaryLayersAndCrossEditionWritesAreRejected() : void{
		$codec = new SchematicCodec();
		$palette = [CompoundTag::create()->setString("name", "minecraft:air")->setTag("states", CompoundTag::create())];
		$data = new SchematicData(1, 1, 1, pack("V", 0), ["minecraft:air"], format: "mcstructure", bedrockPalette: $palette);
		$bytes = $codec->encode($data, "mcstructure");
		$offset = 0;
		$serializer = new LittleEndianNbtSerializer();
		$root = $serializer->read($bytes, $offset)->mustGetCompoundTag();
		$layer = $root->getCompoundTag("structure")->getListTag("block_indices")->get(1);
		self::assertInstanceOf(ListTag::class, $layer);
		$layer->set(0, new IntTag(0));
		$this->assertRejected(fn() => $codec->decode($serializer->write(new TreeRoot($root)), "mcstructure"));
		$this->assertRejected(fn() => $codec->encode($data, "schem"));
		$this->assertRejected(fn() => $codec->encode(new SchematicData(1, 1, 1, pack("V", 0), ["minecraft:air"]), "mcstructure"));
	}

	public function testConfinedFileStoreWritesAtomicallyAndRejectsTraversalAndOversize() : void{
		$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . "awe-" . bin2hex(random_bytes(8));
		$store = new SchematicFileStore($directory, 16);
		try{
			$store->save("test.schem", "first");
			$store->save("test.schem", "second");
			self::assertSame("second", $store->read("test.schem"));
			foreach(["../test.schem", "..\\test.schem", "/test.schem", "C:test.schem", "test.schem:stream", "CON.schem", "test.schem\0", "test.php"] as $name){
				try{
					$store->save($name, "bad");
					self::fail("Accepted unsafe filename");
				}catch(InvalidArgumentException){
					self::assertTrue(true);
				}
			}
			try{
				$store->save("test.schem", str_repeat("x", 17));
				self::fail("Accepted oversized file");
			}catch(RuntimeException){
				self::assertSame("second", $store->read("test.schem"));
			}
			try{
				$store->save("test.schem", "cancelled", static fn() : bool => true);
				self::fail("Committed cancelled save");
			}catch(RuntimeException){
				self::assertSame("second", $store->read("test.schem"));
			}
			file_put_contents($directory . DIRECTORY_SEPARATOR . "large.schem", str_repeat("x", 17));
			try{
				$store->read("large.schem");
				self::fail("Read oversized file");
			}catch(RuntimeException){
				self::assertTrue(true);
			}
			self::assertSame([], glob($directory . DIRECTORY_SEPARATOR . ".awe-*"));
		}finally{
			foreach(glob($directory . DIRECTORY_SEPARATOR . "*") as $path){
				unlink($path);
			}
			rmdir($directory);
		}
	}

	public function testFileStoreRejectsSymlinkReadsAndWrites() : void{
		$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . "awe-link-" . bin2hex(random_bytes(8));
		$store = new SchematicFileStore($directory);
		$target = $directory . DIRECTORY_SEPARATOR . "target.schem";
		$link = $directory . DIRECTORY_SEPARATOR . "link.schem";
		file_put_contents($target, "original");
		try{
			if(!@symlink($target, $link)){
				self::markTestSkipped("Creating symbolic links is unavailable");
			}
			foreach([fn() => $store->read("link.schem"), fn() => $store->save("link.schem", "changed")] as $operation){
				try{
					$operation();
					self::fail("Accepted schematic symlink");
				}catch(RuntimeException){
					self::assertSame("original", file_get_contents($target));
				}
			}
		}finally{
			if(is_link($link)){
				unlink($link);
			}
			unlink($target);
			rmdir($directory);
		}
	}

	private function spongeRoot() : CompoundTag{
		return CompoundTag::create()->setInt("Version", 2)->setShort("Width", 1)->setShort("Height", 1)->setShort("Length", 1)->setTag("Palette", CompoundTag::create()->setInt("minecraft:air", 0))->setByteArray("BlockData", "\0");
	}

	private function assertRejected(callable $decode) : void{
		try{
			$decode();
			self::fail("Accepted malformed or unsupported schematic");
		}catch(UnexpectedValueException){
			self::assertTrue(true);
		}
	}
}
