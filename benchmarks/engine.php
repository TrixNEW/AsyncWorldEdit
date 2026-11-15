<?php

declare(strict_types=1);

use asyncworldedit\Settings;
use asyncworldedit\engine\BlockValue;
use asyncworldedit\engine\Editor;
use asyncworldedit\engine\Operations;
use asyncworldedit\engine\WorldAccess;
use asyncworldedit\geometry\Region;
use asyncworldedit\pattern\Pattern;

require dirname(__DIR__) . '/vendor/autoload.php';

$world = new class implements WorldAccess{
	private string $states;
	public function __construct(){ $this->states = str_repeat("\0", 1048576 * 4); }
	public function id() : string{ return 'packed-memory'; }
	public function isValid() : bool{ return true; }
	public function minY() : int{ return 0; }
	public function maxY() : int{ return 256; }
	public function acquire(int $chunkX, int $chunkZ) : void{}
	public function release() : void{}
	private function index(int $x, int $y, int $z) : int{ return (($y * 64 + $z) * 64 + $x) * 4; }
	public function read(int $x, int $y, int $z) : BlockValue{ return new BlockValue(unpack('V', $this->states, $this->index($x,$y,$z))[1]); }
	public function write(int $x, int $y, int $z, BlockValue $value) : void{
		$packed = pack('V',$value->state); $offset = $this->index($x,$y,$z);
		for($i=0;$i<4;++$i){ $this->states[$offset+$i]=$packed[$i]; }
	}
};
$editor = new Editor(new Settings(budgetMicros:2000,blocksPerTick:2048));
$results = [];
foreach([8,32,64] as $width){
	$region = new Region(0,0,0,$width-1,$width===64?255:$width-1,$width-1);
	$pattern = Pattern::parse('stone',static fn(string $name):int=>1);
	$start = hrtime(true); $ticks=[];
	$job = $editor->submit('benchmark',$world,Operations::fill($world,$region,$pattern),$region->volume(),bounds:$region);
	while(!$job->isFinished()){ $editor->tick(); $ticks[]=$editor->lastTickNanos; }
	$elapsed = (hrtime(true)-$start)/1e9; sort($ticks);
	$results[] = ['blocks'=>$job->changed,'visited'=>$job->visited,'seconds'=>$elapsed,'blocks_per_second'=>round($job->changed/$elapsed),'tick_p95_ms'=>round($ticks[(int)floor((count($ticks)-1)*.95)]/1e6,3),'tick_max_ms'=>round(max($ticks)/1e6,3),'history_bytes'=>$job->history->bytes(),'peak_bytes'=>memory_get_peak_usage(true),'status'=>$job->status];
	$undo=$editor->restore('benchmark',$world);
	while(!$undo->isFinished()){ $editor->tick(); }
}
echo json_encode(['backend'=>'packed memory; excludes Quark, lighting, physics, networking and tick sleeps','php'=>PHP_VERSION,'results'=>$results],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL;
