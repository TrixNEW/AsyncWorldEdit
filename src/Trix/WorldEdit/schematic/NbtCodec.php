<?php

declare(strict_types=1);

namespace Trix\WorldEdit\schematic;

use pocketmine\nbt\BigEndianNbtSerializer;
use pocketmine\nbt\LittleEndianNbtSerializer;
use pocketmine\nbt\TreeRoot;

/** Uses Quark's native bedwire codec when loaded, otherwise the PHP serializer (tests and tooling). */
final class NbtCodec{
	private static ?bool $native = null;

	/** Callers validate untrusted data with NbtGuard first. */
	public static function read(string $data, bool $littleEndian, int $maxDepth = 48) : TreeRoot{
		if(self::$native ??= class_exists(\Bedwire\Nbt::class, false)){
			return \Bedwire\Nbt::read($data, $littleEndian ? \Bedwire\Nbt::BEDROCK : \Bedwire\Nbt::JAVA, 0, $maxDepth);
		}
		$offset = 0;
		return ($littleEndian ? new LittleEndianNbtSerializer() : new BigEndianNbtSerializer())->read($data, $offset, $maxDepth);
	}

	public static function write(TreeRoot $root, bool $littleEndian) : string{
		if(self::$native ??= class_exists(\Bedwire\Nbt::class, false)){
			return \Bedwire\Nbt::write($root, $littleEndian ? \Bedwire\Nbt::BEDROCK : \Bedwire\Nbt::JAVA);
		}
		return ($littleEndian ? new LittleEndianNbtSerializer() : new BigEndianNbtSerializer())->write($root);
	}
}
