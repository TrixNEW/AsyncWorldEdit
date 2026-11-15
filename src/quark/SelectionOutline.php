<?php

declare(strict_types=1);

namespace asyncworldedit\quark;

use asyncworldedit\geometry\Region;
use quark\color\Color;
use quark\math\Vector3;
use quark\player\Player;
use quark\world\particle\DustParticle;

final class SelectionOutline{
	public static function send(Player $player, Region $region) : void{
		$particle = new DustParticle(new Color(40, 220, 230));
		$position = $player->getPosition();
		foreach(self::points($region) as [$x, $y, $z]){
			if(($position->x - $x) ** 2 + ($position->y - $y) ** 2 + ($position->z - $z) ** 2 <= 96 ** 2){
				$position->getWorld()->addParticle(new Vector3($x, $y, $z), $particle, [$player]);
			}
		}
	}

	/** @return \Generator<int,array{float,float,float}> */
	public static function points(Region $r) : \Generator{
		$lo = [(float) $r->minX, (float) $r->minY, (float) $r->minZ];
		$hi = [$r->maxX + 1.0, $r->maxY + 1.0, $r->maxZ + 1.0];
		if($r->shape === 'cuboid'){
			for($axis = 0; $axis < 3; ++$axis){
				$a = ($axis + 1) % 3; $b = ($axis + 2) % 3;
				for($edge = 0; $edge < 4; ++$edge){
					for($i = 0; $i < 6; ++$i){
						$p = $lo;
						$p[$a] = ($edge & 1) === 0 ? $lo[$a] : $hi[$a];
						$p[$b] = ($edge & 2) === 0 ? $lo[$b] : $hi[$b];
						$p[$axis] += ($hi[$axis] - $lo[$axis]) * $i / 5;
						yield $p;
					}
				}
			}
			return;
		}
		for($ring = 0; $ring < 3; ++$ring){
			for($i = 0; $i < 24; ++$i){
				$angle = $i * M_PI / 12;
				$p = [($lo[0] + $hi[0]) / 2, ($lo[1] + $hi[1]) / 2, ($lo[2] + $hi[2]) / 2];
				if($r->shape === 'cylinder'){
					$p[0] += cos($angle) * ($hi[0] - $lo[0]) / 2;
					$p[2] += sin($angle) * ($hi[2] - $lo[2]) / 2;
					$p[1] = $lo[1] + ($hi[1] - $lo[1]) * $ring / 2;
				}else{
					$a = ($ring + 1) % 3; $b = ($ring + 2) % 3;
					$p[$a] += cos($angle) * ($hi[$a] - $lo[$a]) / 2;
					$p[$b] += sin($angle) * ($hi[$b] - $lo[$b]) / 2;
				}
				yield $p;
			}
		}
	}
}
