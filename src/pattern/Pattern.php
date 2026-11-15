<?php

declare(strict_types=1);

namespace asyncworldedit\pattern;

use Closure;
use InvalidArgumentException;

final readonly class Pattern{

	/** @param list<array{float, ?int}> $choices */
	private function __construct(private array $choices, private float $total){}

	/** @param Closure(string): int $resolve */
	public static function parse(string $expression, Closure $resolve) : self{
		$tokens = Syntax::tokenize($expression);
		$choices = [];
		$total = 0.0;
		foreach($tokens as $index => $token){
			if(($index & 1) === 1){
				if($token !== ","){
					throw new InvalidArgumentException("Expected a pattern separator");
				}
				continue;
			}
			$weight = 1.0;
			if(preg_match('/^([0-9]+(?:\.[0-9]+)?|\.[0-9]+)%\s*(.*)$/sD', $token, $match)){
				$weight = (float) $match[1];
				$token = trim($match[2]);
			}
			if($weight <= 0 || !is_finite($weight) || !is_finite($total + $weight) || count($choices) >= 128){
				throw new InvalidArgumentException("Pattern weights must be finite and positive; at most 128 alternatives are allowed");
			}
			if($token !== "#existing"){
				Syntax::validateBlock($token);
			}
			$total += $weight;
			$choices[] = [$total, $token === "#existing" ? null : $resolve($token)];
		}
		if((count($tokens) & 1) === 0){
			throw new InvalidArgumentException("Missing pattern after separator");
		}
		return new self($choices, $total);
	}

	public function choose(int $x, int $y, int $z, int $existing, int $seed = 0) : int{
		if(count($this->choices) === 1){
			return $this->choices[0][1] ?? $existing;
		}
		$value = (float) hexdec(hash("crc32b", "$seed:$x:$y:$z")) / 4294967296.0 * $this->total;
		foreach($this->choices as [$limit, $state]){
			if($value < $limit){
				return $state ?? $existing;
			}
		}
		return $this->choices[array_key_last($this->choices)][1] ?? $existing;
	}
}
