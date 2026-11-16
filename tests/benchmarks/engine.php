<?php

declare(strict_types=1);

/*
 * Memory-backed engine benchmark: scheduling, history, clipboards and schematic codecs without Quark.
 * World cost (setBlockAt, lighting, networking) is measured by tools/integration instead.
 * It also runs against the pre-refactor API, so `php tests/benchmarks/engine.php` gives comparable numbers on both.
 */

use Trix\WorldEdit\Settings;
use Trix\WorldEdit\clipboard\Clipboard;
use Trix\WorldEdit\engine\BlockValue;
use Trix\WorldEdit\engine\ClipboardOperations;
use Trix\WorldEdit\engine\Editor;
use Trix\WorldEdit\engine\Job;
use Trix\WorldEdit\engine\Operations;
use Trix\WorldEdit\engine\WorldAccess;
use Trix\WorldEdit\geometry\Region;
use Trix\WorldEdit\pattern\Pattern;
use Trix\WorldEdit\schematic\SchematicCodec;
use Trix\WorldEdit\schematic\SchematicData;
use pocketmine\nbt\tag\CompoundTag;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

const SIZE = 128;
$legacy = (new ReflectionMethod(WorldAccess::class, 'write'))->getNumberOfParameters() === 4;

abstract class PackedWorld{
	protected string $states;
	public function __construct(){ $this->states = str_repeat("\0", SIZE * SIZE * 256 * 4); }
	public function id() : string{ return 'packed'; }
	public function isValid() : bool{ return true; }
	public function minY() : int{ return 0; }
	public function maxY() : int{ return 256; }
	public function acquire(int $chunkX, int $chunkZ) : void{}
	public function release() : void{}
	protected function index(int $x, int $y, int $z) : int{ return (($y * SIZE + $z) * SIZE + $x) * 4; }
	public function stateAt(int $x, int $y, int $z) : int{ return unpack('V', $this->states, $this->index($x, $y, $z))[1]; }
	public function read(int $x, int $y, int $z) : BlockValue{
		$state = unpack('V', $this->states, $this->index($x, $y, $z))[1];
		return method_exists(BlockValue::class, 'of') ? BlockValue::of($state) : new BlockValue($state);
	}
	protected function put(int $x, int $y, int $z, BlockValue $value) : void{
		$packed = pack('V', $value->state);
		$offset = $this->index($x, $y, $z);
		for($i = 0; $i < 4; ++$i){ $this->states[$offset + $i] = $packed[$i]; }
	}
}
if($legacy){
	eval('final class Bench extends PackedWorld implements Trix\WorldEdit\engine\WorldAccess{ public function write(int $x, int $y, int $z, Trix\WorldEdit\engine\BlockValue $value) : void{ $this->put($x, $y, $z, $value); } }');
}else{
	eval('final class Bench extends PackedWorld implements Trix\WorldEdit\engine\WorldAccess{ public function write(int $x, int $y, int $z, Trix\WorldEdit\engine\BlockValue $expected, Trix\WorldEdit\engine\BlockValue $value) : ?Trix\WorldEdit\engine\BlockValue{ if(!$this->read($x, $y, $z)->equals($expected)){ return null; } $this->put($x, $y, $z, $value); return $value; } }');
}

$world = new Bench();
$editor = new Editor(new Settings(budgetMicros: 2000));

function submit(Editor $editor, WorldAccess $world, Generator $steps, int $limit, ?Region $bounds, bool $legacy) : Job{
	return $legacy ? $editor->submit('bench', $world, $steps, $limit, bounds: $bounds) : $editor->submit('bench', $world, $steps, $limit, $bounds);
}

/** @return array<string, int|float|string> */
function measure(Editor $editor, Job $job) : array{
	memory_reset_peak_usage();
	$base = memory_get_usage();
	$ticks = [];
	$cpu = getrusage();
	$start = hrtime(true);
	while(!$job->isFinished()){
		$tickStart = hrtime(true);
		$editor->tick();
		$ticks[] = hrtime(true) - $tickStart;
	}
	$elapsed = hrtime(true) - $start;
	$after = getrusage();
	sort($ticks);
	$work = max(1, $job->visited);
	return [
		'status' => is_string($job->status) ? $job->status : $job->status->value,
		'visited' => $job->visited,
		'changed' => $job->changed,
		'ns_per_unit' => round($elapsed / $work, 1),
		'units_per_second' => (int) round($work / ($elapsed / 1e9)),
		'cpu_ms' => round((($after['ru_utime.tv_sec'] - $cpu['ru_utime.tv_sec']) * 1e6 + $after['ru_utime.tv_usec'] - $cpu['ru_utime.tv_usec']) / 1000, 1),
		'ticks' => count($ticks),
		'tick_p95_ms' => round($ticks[(int) floor((count($ticks) - 1) * 0.95)] / 1e6, 3),
		'tick_max_ms' => round(max($ticks) / 1e6, 3),
		'peak_delta_kib' => (int) round((memory_get_peak_usage() - $base) / 1024),
	];
}

$results = [];
$stone = Pattern::parse('stone', static fn(string $name) : int => 1);
foreach([[8, 8, 8], [32, 32, 32], [128, 64, 128]] as [$w, $h, $l]){
	$region = new Region(0, 0, 0, $w - 1, $h - 1, $l - 1);
	$name = $region->volume() . ' blocks';
	$fill = measure($editor, submit($editor, $world, Operations::fill($world, $region, $stone), $region->volume(), $region, $legacy));
	$fill['history_kib'] = (int) round($editor->historyBytes() / 1024);
	$results["fill $name"] = $fill;
	$results["undo $name"] = measure($editor, $editor->restore('bench', $world));
	$results["redo $name"] = measure($editor, $editor->restore('bench', $world, true));
	$editor->forget('bench');
}

// Four players editing disjoint boxes at once: per-tick cost stays bounded and work is shared fairly.
$world = new Bench();
$jobs = [];
for($owner = 0; $owner < 4; ++$owner){
	$box = new Region($owner * 32, 0, 0, $owner * 32 + 31, 63, 31);
	$steps = Operations::fill($world, $box, $stone);
	$jobs[] = $legacy ? $editor->submit("player$owner", $world, $steps, $box->volume(), bounds: $box) : $editor->submit("player$owner", $world, $steps, $box->volume(), $box);
}
$ticks = [];
$finished = [];
$start = hrtime(true);
for($tick = 0; count($finished) < 4; ++$tick){
	$tickStart = hrtime(true);
	$editor->tick();
	$ticks[] = hrtime(true) - $tickStart;
	foreach($jobs as $index => $job){
		if($job->isFinished() && !isset($finished[$index])){
			$finished[$index] = $tick;
		}
	}
}
sort($ticks);
$results['4 concurrent fills of 65536 blocks'] = [
	'ns_per_unit' => round((hrtime(true) - $start) / (4 * 65536), 1),
	'tick_p95_ms' => round($ticks[(int) floor((count($ticks) - 1) * 0.95)] / 1e6, 3),
	'tick_max_ms' => round(max($ticks) / 1e6, 3),
	'finish_spread_ticks' => max($finished) - min($finished),
];
for($owner = 0; $owner < 4; ++$owner){
	$editor->forget("player$owner");
}

$world = new Bench();
$region = new Region(0, 0, 0, 63, 63, 63);
measure($editor, submit($editor, $world, Operations::fill($world, $region, Pattern::parse('50%stone,50%dirt', static fn(string $name) : int => $name === 'stone' ? 1 : 2)), $region->volume(), $region, $legacy));
$editor->forget('bench');
$clipboard = null;
$capture = $legacy
	? ClipboardOperations::capture($world, $region, [0, 0, 0], 500000, static function(Clipboard $result) use (&$clipboard) : void{ $clipboard = $result; })
	: ClipboardOperations::capture($world, $region, [0, 0, 0], 500000);
$job = submit($editor, $world, $capture, $region->volume(), $region, $legacy);
$results['copy 262144 blocks'] = measure($editor, $job);
$clipboard ??= $job->result;
$rotated = $clipboard->rotate(90);
$target = $rotated->bounds([64, 0, 64]);
$results['rotated paste 262144 blocks'] = measure($editor, submit($editor, $world, ClipboardOperations::paste($world, $rotated, [64, 0, 64]), $rotated->volume(), $target, $legacy));
$editor->forget('bench');

$codec = new SchematicCodec(maxBlocks: 500000);
$palette = [CompoundTag::create()->setString('name', 'minecraft:air')->setTag('states', CompoundTag::create()), CompoundTag::create()->setString('name', 'minecraft:stone')->setTag('states', CompoundTag::create())];
foreach([48, 64] as $side){
	$count = $side ** 3;
	$data = new SchematicData($side, $side, $side, str_repeat(pack('V*', 0, 1, 1, 0), intdiv($count, 4)), ['minecraft:air', 'minecraft:stone'], format: 'mcstructure', bedrockPalette: $palette, formatVersion: 1);
	memory_reset_peak_usage();
	$base = memory_get_usage();
	try{
		$start = hrtime(true);
		$bytes = $codec->encode($data, 'mcstructure');
		$encoded = hrtime(true) - $start;
		$start = hrtime(true);
		$codec->decode($bytes, 'mcstructure');
		$results["mcstructure $count blocks"] = ['encode_ms' => round($encoded / 1e6, 1), 'decode_ms' => round((hrtime(true) - $start) / 1e6, 1), 'bytes' => strlen($bytes), 'peak_delta_kib' => (int) round((memory_get_peak_usage() - $base) / 1024)];
	}catch(Throwable $e){
		$results["mcstructure $count blocks"] = ['error' => $e->getMessage()];
	}
}

echo json_encode([
	'api' => $legacy ? 'pre-refactor' : 'refactor',
	'backend' => 'packed memory world; excludes Quark world writes, lighting, physics and networking',
	'php' => PHP_VERSION,
	'native_nbt' => class_exists(\Bedwire\Nbt::class, false),
	'results' => $results,
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
