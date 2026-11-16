<?php

declare(strict_types=1);

namespace Trix\WorldEdit;

use Trix\WorldEdit\clipboard\Clipboard;
use Trix\WorldEdit\engine\Job;
use Trix\WorldEdit\engine\JobStatus;
use Trix\WorldEdit\schematic\NbtCodec;
use Trix\WorldEdit\schematic\SchematicData;
use Trix\WorldEdit\worker\SchematicService;
use pocketmine\nbt\TreeRoot;
use pocketmine\nbt\tag\CompoundTag;
use quark\player\Player;
use Vow\CancelledException;
use Vow\Promise;

/**
 * Converts between clipboards and schematic files. Files are read and written by workers;
 * block states are resolved on the main thread in budgeted editor jobs.
 */
final class Schematics{
	public function __construct(private readonly SchematicService $files, private readonly Edits $edits){}

	public function load(Player $player, string $name) : void{
		$session = $this->idleSession($player);
		$request = $this->files->load($name);
		$this->track($session, $request, function(SchematicData $data) use ($player) : void{
			try{
				$this->import($player, $data);
			}catch(\Throwable $e){
				$player->sendMessage('Schematic import failed: ' . $e->getMessage());
			}
		}, 'Schematic import failed: ', $player);
		$player->sendMessage('Loading schematic asynchronously.');
	}

	public function save(Player $player, string $name) : void{
		$session = $this->idleSession($player);
		$clipboard = $session->clipboard ?? throw new \RuntimeException('Your clipboard is empty');
		if(strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'mcstructure'){
			throw new \InvalidArgumentException('Export uses .mcstructure to preserve exact Bedrock states');
		}
		$states = $this->edits->states;
		$steps = (static function() use ($clipboard, $states) : \Generator{
			[[$minX, $minY, $minZ], [$maxX, $maxY, $maxZ]] = $clipboard->relativeBounds();
			$width = $maxX - $minX + 1;
			$length = $maxZ - $minZ + 1;
			$packed = str_repeat("\0", $clipboard->volume() * 4);
			$indices = [];
			$palette = [];
			$bedrock = [];
			$tiles = [];
			foreach($clipboard->blocks($states->transform(...)) as [$x, $y, $z, $value]){
				if(!isset($indices[$value->state])){
					$indices[$value->state] = pack('V', count($palette));
					$palette[] = $states->name($value->state);
					$bedrock[] = $states->vanillaTag($value->state);
				}
				$index = (($y - $minY) * $length + $z - $minZ) * $width + $x - $minX;
				$entry = $indices[$value->state];
				$offset = $index * 4;
				$packed[$offset] = $entry[0];
				$packed[$offset + 1] = $entry[1];
				$packed[$offset + 2] = $entry[2];
				$packed[$offset + 3] = $entry[3];
				if($value->nbt !== null){
					$tiles[$index] = NbtCodec::read($value->nbt, true)->mustGetCompoundTag()->setInt('x', $x - $minX)->setInt('y', $y - $minY)->setInt('z', $z - $minZ);
				}
				yield null;
			}
			$origin = [$minX + $clipboard->origin[0], $minY + $clipboard->origin[1], $minZ + $clipboard->origin[2]];
			$metadata = CompoundTag::create()->setIntArray('ClipboardOrigin', $clipboard->origin);
			return new SchematicData($width, $maxY - $minY + 1, $length, $packed, $palette, $origin, $tiles, 'mcstructure', $bedrock, metadata: $metadata, validateStates: false, formatVersion: 1);
		})();
		$this->edits->submit($player, $this->edits->world($player), $steps, $clipboard->volume(), null, then: function(Job $job) use ($player, $session, $name) : void{
			// A departed player's request was cancelled on disconnect, so do not start the file write.
			if($job->status === JobStatus::Completed && $job->result instanceof SchematicData && $player->isConnected()){
				$this->track($session, $this->files->save($name, $job->result), static fn() => $player->sendMessage('Schematic saved.'), 'Schematic export failed: ', $player);
			}
		});
	}

	public function cancel(Session $session) : void{
		if($session->file !== null){
			$this->files->cancel($session->file);
			$session->file = null;
		}
	}

	private function import(Player $player, SchematicData $data) : void{
		if($data->format === 'sponge' && $data->tiles !== []){
			throw new \InvalidArgumentException('Java block entity conversion is not supported; clipboard unchanged');
		}
		$max = $this->edits->editor->settings->clipboardBlocks;
		if($data->blockCount() > $max){
			throw new \RuntimeException("Schematic exceeds the $max block clipboard limit");
		}
		$states = $this->edits->states;
		$steps = (static function() use ($data, $states, $max) : \Generator{
			$palette = [];
			foreach($data->palette as $name){
				$palette[] = pack('V', $states->resolve($name));
				yield null;
			}
			$packed = '';
			for($i = 0, $count = $data->blockCount(); $i < $count; ++$i){
				$packed .= $palette[unpack('V', $data->states, $i * 4)[1] ?? -1] ?? throw new \RuntimeException('Invalid packed schematic state');
				yield null;
			}
			$tiles = [];
			foreach($data->tiles as $index => $tag){
				$tag = clone $tag;
				$tag->removeTag('x', 'y', 'z');
				$tiles[$index] = NbtCodec::write(new TreeRoot($tag), true);
				yield null;
			}
			['min' => $min, 'origin' => $origin] = $data->placement();
			return new Clipboard($data->width, $data->height, $data->length, $packed, $tiles, $min, $origin, $max);
		})();
		$session = $this->edits->sessions->get($player);
		$this->edits->submit($player, $this->edits->world($player), $steps, count($data->palette) + $data->blockCount() + count($data->tiles), null, then: function(Job $job) use ($session) : void{
			if($job->status === JobStatus::Completed && $job->result instanceof Clipboard){
				$this->edits->sessions->setClipboard($session, $job->result);
			}
		});
	}

	private function idleSession(Player $player) : Session{
		$session = $this->edits->sessions->get($player);
		if($session->file !== null || $this->edits->editor->active(Sessions::owner($player)) !== null){
			throw new \RuntimeException('Finish your current operation first');
		}
		return $session;
	}

	/**
	 * @template T
	 * @param Promise<T> $request
	 * @param \Closure(T) : void $done
	 */
	private function track(Session $session, Promise $request, \Closure $done, string $failure, Player $player) : void{
		$session->file = $request;
		$request->observe(static function(mixed $value) use ($session, $request, $done, $player) : void{
			if($session->file === $request){
				$session->file = null;
			}
			if($player->isConnected()){
				$done($value);
			}
		}, static function(\Throwable $error) use ($session, $request, $failure, $player) : void{
			if($session->file === $request){
				$session->file = null;
			}
			if($player->isConnected() && !$error instanceof CancelledException){
				$player->sendMessage($failure . $error->getMessage());
			}
		});
	}
}
