<?php

declare(strict_types=1);

namespace Trix\WorldEdit\tests;

use Trix\WorldEdit\schematic\SchematicCodec;
use Trix\WorldEdit\schematic\SchematicData;
use Trix\WorldEdit\schematic\SchematicFileStore;
use Closure;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use pocketmine\nbt\tag\CompoundTag;
use RuntimeException;
use UnexpectedValueException;

final class SchematicAuditTest extends TestCase{

	public function testCompressedTrailingDataAndConcatenatedMembersAreRejected() : void{
		$codec = new SchematicCodec();
		$bytes = $codec->encode($this->air());
		foreach([$bytes . 'trailing', $bytes . gzencode('second member'), substr($bytes, 0, -1)] as $invalid){
			try{
				$codec->decode($invalid);
				self::fail('Accepted trailing or truncated gzip data');
			}catch(UnexpectedValueException){
				self::assertTrue(true);
			}
		}
		self::assertSame($this->air()->states, $codec->decode($bytes)->states);
	}

	public function testCompressionAcrossChunksHonorsExactDecodedLimit() : void{
		$palette = [];
		for($index = 0; $index < 512; ++$index){
			$palette[] = 'minecraft:test_' . hash('sha256', (string) $index);
		}
		$data = new SchematicData(512, 1, 1, pack('V*', ...range(0, 511)), $palette);
		$bytes = (new SchematicCodec())->encode($data);
		self::assertGreaterThan(1024, strlen($bytes));
		$length = strlen(gzdecode($bytes));
		$codec = new SchematicCodec(maxDecodedBytes: $length);
		self::assertSame($palette, $codec->decode($bytes)->palette);
		$this->expectException(UnexpectedValueException::class);
		(new SchematicCodec(maxDecodedBytes: $length - 1))->decode($bytes);
	}

	public function testEncodingRevalidatesMutablePlacementMetadata() : void{
		$metadata = CompoundTag::create()->setTag('WorldEdit', CompoundTag::create()->setIntArray('Origin', [10, 20, 30]));
		$data = $this->air($metadata);
		$metadata->getCompoundTag('WorldEdit')->setIntArray('Origin', [1, 2]);
		$this->expectException(InvalidArgumentException::class);
		(new SchematicCodec())->encode($data);
	}

	public function testOriginMetadataCannotTruncateOutsideInt32Storage() : void{
		$this->expectException(InvalidArgumentException::class);
		$this->air(CompoundTag::create()->setTag('WorldEdit', CompoundTag::create()->setIntArray('Origin', [0x100000000, 0, 0])));
	}

	public function testVersionConversionRejectsPlacementOverflowInsteadOfWrapping() : void{
		$v2Metadata = CompoundTag::create()->setInt('WEOffsetX', -1)->setInt('WEOffsetY', 0)->setInt('WEOffsetZ', 0);
		$v3Metadata = CompoundTag::create()->setTag('WorldEdit', CompoundTag::create()->setIntArray('Origin', [1, 0, 0]));
		$codec = new SchematicCodec();
		foreach([2 => $v2Metadata, 3 => $v3Metadata] as $version => $metadata){
			$data = new SchematicData(1, 1, 1, pack('V', 0), ['minecraft:air'], [0x7fffffff, 0, 0], metadata: $metadata, formatVersion: $version);
			try{
				$codec->encode($data, version: $version === 2 ? 3 : 2);
				self::fail('Wrapped placement coordinates during format conversion');
			}catch(UnexpectedValueException){
				self::assertTrue(true);
			}
		}
	}

	public function testEncodersRejectMissingPaletteReferencesFromTrustedProducers() : void{
		$palette = [CompoundTag::create()->setString('name', 'minecraft:air')->setTag('states', CompoundTag::create())];
		$codec = new SchematicCodec();
		foreach(['sponge' => 'schem', 'mcstructure' => 'mcstructure'] as $source => $format){
			$data = new SchematicData(1, 1, 1, pack('V', 1), ['minecraft:air'], format: $source, bedrockPalette: $palette, validateStates: false);
			try{
				$codec->encode($data, $format);
				self::fail('Encoded a block without a palette entry');
			}catch(UnexpectedValueException){
				self::assertTrue(true);
			}
		}
	}

	public function testCancellationAtPublicationKeepsOriginalFileAndCleansTemporaryFile() : void{
		$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'awe-commit-' . bin2hex(random_bytes(8));
		$store = new SchematicFileStore($directory);
		try{
			$store->save('saved.schem', 'original');
			$cancelled = false;
			try{
				$store->save('saved.schem', 'replacement', static function() use (&$cancelled) : bool{ return $cancelled; }, static function(Closure $publish) use (&$cancelled) : void{
					$cancelled = true;
					$publish();
				});
				self::fail('Published a cancelled schematic');
			}catch(RuntimeException){
				self::assertSame('original', $store->read('saved.schem'));
			}
			self::assertSame([], glob($directory . DIRECTORY_SEPARATOR . '.awe-*'));
		}finally{
			unlink($directory . DIRECTORY_SEPARATOR . 'saved.schem');
			rmdir($directory);
		}
	}

	public function testReplacingSchematicDirectoryWithSymlinkIsRejected() : void{
		$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'awe-directory-' . bin2hex(random_bytes(8));
		$directory = $root . DIRECTORY_SEPARATOR . 'schematics';
		$backup = $root . DIRECTORY_SEPARATOR . 'original';
		$outside = $root . DIRECTORY_SEPARATOR . 'outside';
		$store = new SchematicFileStore($directory);
		mkdir($outside);
		rename($directory, $backup);
		try{
			if(!@symlink($outside, $directory)){
				self::markTestSkipped('Creating symbolic links is unavailable');
			}
			try{
				$store->save('escape.schem', 'outside');
				self::fail('Wrote through a replaced schematic directory');
			}catch(RuntimeException){
				self::assertFileDoesNotExist($outside . DIRECTORY_SEPARATOR . 'escape.schem');
			}
		}finally{
			if(is_link($directory)){
				unlink($directory);
			}
			rmdir($backup);
			rmdir($outside);
			rmdir($root);
		}
	}

	private function air(?CompoundTag $metadata = null) : SchematicData{
		return new SchematicData(1, 1, 1, pack('V', 0), ['minecraft:air'], metadata: $metadata);
	}
}
