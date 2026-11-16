<?php

// Analysis stub for Quark's bedwire extension, for environments that do not load it.

namespace Bedwire;

use pocketmine\nbt\TreeRoot;

final class Nbt{
	public const JAVA = 0;
	public const BEDROCK = 1;
	public const NETWORK = 2;

	public static function read(string $data, int $format, int $offset = 0, int $maxDepth = 0) : TreeRoot{}

	public static function write(TreeRoot $root, int $format) : string{}
}
