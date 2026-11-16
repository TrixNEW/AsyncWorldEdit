<?php

declare(strict_types=1);

namespace Trix\WorldEdit\pattern;

use Closure;
use InvalidArgumentException;

final readonly class Mask{

	/** @param Closure(int): bool $test */
	private function __construct(private Closure $test){}

	/**
	 * @param Closure(string): int $resolve
	 * @param Closure(int): bool $solid
	 */
	public static function parse(string $expression, Closure $resolve, Closure $solid, int $air = 0) : self{
		$tokens = Syntax::tokenize($expression, true);
		$index = 0;
		$leaves = 0;
		$test = self::union($tokens, $index, $leaves, $resolve, $solid, $air, 0);
		if($index !== count($tokens)){
			throw new InvalidArgumentException("Unexpected mask token: " . $tokens[$index]);
		}
		return new self($test);
	}

	public function matches(int $state) : bool{
		return ($this->test)($state);
	}

	public static function both(?self $first, ?self $second) : ?self{
		if($first === null || $second === null){
			return $first ?? $second;
		}
		$a = $first->test;
		$b = $second->test;
		return new self(static fn(int $state) : bool => $a($state) && $b($state));
	}

	/** @param list<string> $tokens */
	private static function union(array $tokens, int &$index, int &$leaves, Closure $resolve, Closure $solid, int $air, int $depth) : Closure{
		$tests = [self::intersection($tokens, $index, $leaves, $resolve, $solid, $air, $depth)];
		while(($tokens[$index] ?? null) === ","){
			++$index;
			$tests[] = self::intersection($tokens, $index, $leaves, $resolve, $solid, $air, $depth);
		}
		return count($tests) === 1 ? $tests[0] : static function(int $state) use ($tests) : bool{
			foreach($tests as $test){
				if($test($state)){
					return true;
				}
			}
			return false;
		};
	}

	/** @param list<string> $tokens */
	private static function intersection(array $tokens, int &$index, int &$leaves, Closure $resolve, Closure $solid, int $air, int $depth) : Closure{
		$tests = [self::term($tokens, $index, $leaves, $resolve, $solid, $air, $depth)];
		while(($tokens[$index] ?? null) === "&"){
			++$index;
			$tests[] = self::term($tokens, $index, $leaves, $resolve, $solid, $air, $depth);
		}
		return count($tests) === 1 ? $tests[0] : static function(int $state) use ($tests) : bool{
			foreach($tests as $test){
				if(!$test($state)){
					return false;
				}
			}
			return true;
		};
	}

	/** @param list<string> $tokens */
	private static function term(array $tokens, int &$index, int &$leaves, Closure $resolve, Closure $solid, int $air, int $depth) : Closure{
		if($depth > 32){
			throw new InvalidArgumentException("Mask nesting exceeds 32 levels");
		}
		$token = $tokens[$index++] ?? "";
		if($token === "!"){
			$test = self::term($tokens, $index, $leaves, $resolve, $solid, $air, $depth + 1);
			return static fn(int $state) : bool => !$test($state);
		}
		if($token === "("){
			$test = self::union($tokens, $index, $leaves, $resolve, $solid, $air, $depth + 1);
			if(($tokens[$index++] ?? null) !== ")"){
				throw new InvalidArgumentException("Missing closing mask parenthesis");
			}
			return $test;
		}
		if(++$leaves > 128){
			throw new InvalidArgumentException("Mask exceeds 128 alternatives");
		}
		if($token === "#air"){
			return static fn(int $state) : bool => $state === $air;
		}
		if($token === "#solid"){
			return $solid;
		}
		Syntax::validateBlock($token);
		$block = $resolve($token);
		return static fn(int $state) : bool => $state === $block;
	}
}
