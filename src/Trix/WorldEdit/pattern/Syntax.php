<?php

declare(strict_types=1);

namespace Trix\WorldEdit\pattern;

use InvalidArgumentException;

final class Syntax{

	/** @return list<string> */
	public static function tokenize(string $input, bool $mask = false) : array{
		if(strlen($input) > 4096 || trim($input) === ""){
			throw new InvalidArgumentException("Expression must contain between 1 and 4096 bytes");
		}
		$tokens = [];
		$start = 0;
		$bracket = false;
		$quote = null;
		$escaped = false;
		for($i = 0, $length = strlen($input); $i < $length; ++$i){
			$char = $input[$i];
			if($quote !== null){
				if($escaped){
					$escaped = false;
				}elseif($char === "\\"){
					$escaped = true;
				}elseif($char === $quote){
					$quote = null;
				}
				continue;
			}
			if($bracket && ($char === '"' || $char === "'")){
				$quote = $char;
			}elseif($char === "["){
				if($bracket){
					throw new InvalidArgumentException("Nested block properties are not supported");
				}
				$bracket = true;
			}elseif($char === "]"){
				if(!$bracket){
					throw new InvalidArgumentException("Unexpected closing bracket");
				}
				$bracket = false;
			}elseif(!$bracket && str_contains($mask ? ",&!()" : ",", $char)){
				if($i > $start && trim(substr($input, $start, $i - $start)) !== ""){
					$tokens[] = trim(substr($input, $start, $i - $start));
				}
				$tokens[] = $char;
				$start = $i + 1;
			}
		}
		if($bracket || $quote !== null){
			throw new InvalidArgumentException("Unclosed block properties");
		}
		if(trim(substr($input, $start)) !== ""){
			$tokens[] = trim(substr($input, $start));
		}
		return $tokens;
	}

	public static function validateBlock(string $block) : void{
		if(!preg_match('/^[a-zA-Z0-9_.:-]+(?:\[.*\])?$/sD', $block)){
			throw new InvalidArgumentException("Invalid block expression: " . $block);
		}
	}
}
