<?php

declare(strict_types=1);

namespace asyncworldedit\command;

use asyncworldedit\Main;
use asyncworldedit\Session;
use asyncworldedit\clipboard\Clipboard;
use asyncworldedit\engine\Editor;
use asyncworldedit\engine\Job;
use asyncworldedit\quark\BlockStates;
use asyncworldedit\schematic\SchematicData;
use asyncworldedit\worker\SchematicService;
use pocketmine\nbt\LittleEndianNbtSerializer;
use pocketmine\nbt\TreeRoot;
use quark\command\argument\EnumArgument;
use quark\command\argument\StringArgument;
use quark\command\CommandArgs;
use quark\player\Player;

final class SchematicCommands{

	public function __construct(
		private Main $plugin,
		private Editor $editor,
		private BlockStates $states,
		private SchematicService $schematics
	){
	}

	public function register() : void{
		$this->plugin->getServer()->getCommandMap()->register('asyncworldedit', new EditCommand($this->plugin, 'schem', 'Load or save a schematic', 'schematic', [new EnumArgument('action', ['load', 'save']), new StringArgument('file')], function(Player $player, CommandArgs $args) : void{
			$session = $this->plugin->session($player);
			if($session->fileRequest !== null || $this->editor->active($player->getUniqueId()->toString()) !== null){
				throw new \RuntimeException('Finish your current operation first');
			}
			$name = $args->string('file');
			if($args->string('action') === 'load'){
				$session->fileRequest = $this->schematics->load($name, function(?SchematicData $data, ?string $error) use ($player, $session) : void{
					$session->fileRequest = null;
					if(!$player->isConnected() || !$this->plugin->isEnabled()){
						return;
					}
					if($error !== null || $data === null){
						$player->sendMessage('Schematic import failed: ' . ($error ?? 'No data'));
						return;
					}
					try{
						$this->importSchematic($player, $session, $data);
					}catch(\Throwable $e){
						$player->sendMessage('Schematic import failed: ' . $e->getMessage());
					}
				});
				$player->sendMessage('Loading schematic asynchronously.');
			}else{
				$this->exportSchematic($player, $session, $name);
			}
		}));
	}

	private function importSchematic(Player $player, Session $session, SchematicData $data) : void{
		if($data->format === 'sponge' && $data->tiles !== []){
			throw new \InvalidArgumentException('Java block entity conversion is not supported; clipboard unchanged');
		}
		if($data->blockCount() > $this->editor->settings->clipboardBlocks){
			throw new \RuntimeException('Schematic exceeds clipboard limit');
		}
		$candidate = null;
		$steps = (function() use ($data, &$candidate) : \Generator{
			$palette = [];
			foreach($data->palette as $text){
				$palette[] = $this->states->resolve($text);
				yield null;
			}
			$packed = '';
			for($i = 0; $i < $data->blockCount(); ++$i){
				$decoded = unpack('V', $data->states, $i * 4);
				if($decoded === false){
					throw new \RuntimeException('Invalid packed schematic state');
				}
				$index = $decoded[1];
				$packed .= pack('V', $palette[$index]);
				yield null;
			}
			$tiles = [];
			$serializer = new LittleEndianNbtSerializer();
			foreach($data->tiles as $index => $tag){
				$tag = clone $tag;
				$tag->removeTag('x', 'y', 'z');
				$tiles[$index] = $serializer->write(new TreeRoot($tag));
				yield null;
			}
			$placement = $data->placement();
			$min = $placement['min'];
			$origin = $placement['origin'];
			$candidate = new Clipboard($data->width, $data->height, $data->length, $packed, $tiles, $min, $origin, $this->editor->settings->clipboardBlocks);
		})();
		$this->plugin->submit($player, $this->plugin->world($player->getWorld()), $steps, $data->blockCount() + count($data->palette) + count($data->tiles), null, function(Job $job) use ($session, &$candidate) : void{
			if($job->status === 'completed' && $job->conflicts === 0){
				$this->plugin->setClipboard($session, $candidate);
			}
		});
	}

	private function exportSchematic(Player $player, Session $session, string $name) : void{
		$clipboard = $session->clipboard ?? throw new \RuntimeException('Clipboard is empty');
		if(strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'mcstructure'){
			throw new \InvalidArgumentException('Export uses .mcstructure to preserve exact Bedrock states');
		}
		$steps = (function() use ($clipboard, $player, $session, $name) : \Generator{
			$bounds = $clipboard->bounds([0, 0, 0]);
			$width = $bounds->maxX - $bounds->minX + 1;
			$height = $bounds->maxY - $bounds->minY + 1;
			$length = $bounds->maxZ - $bounds->minZ + 1;
			$packed = str_repeat("\x00", $clipboard->volume() * 4);
			$indices = [];
			$palette = [];
			$bedrockPalette = [];
			$tiles = [];
			$serializer = new LittleEndianNbtSerializer();
			foreach($clipboard->coordinates($this->states->transform(...)) as [$x, $y, $z, $value]){
				if(!isset($indices[$value->state])){
					$indices[$value->state] = count($palette);
					$palette[] = $this->states->name($value->state);
					$bedrockPalette[] = \quark\world\format\io\GlobalBlockStateHandlers::getSerializer()->serialize($value->state)->toVanillaNbt();
				}
				$index = (($y - $bounds->minY) * $length + ($z - $bounds->minZ)) * $width + ($x - $bounds->minX);
				$bytes = pack('V', $indices[$value->state]);
				for($byte = 0; $byte < 4; ++$byte){
					$packed[$index * 4 + $byte] = $bytes[$byte];
				}
				if($value->nbt !== null){
					$tiles[$index] = $serializer->read($value->nbt)->mustGetCompoundTag()->setInt('x', $x - $bounds->minX)->setInt('y', $y - $bounds->minY)->setInt('z', $z - $bounds->minZ);
				}
				yield null;
			}
			$worldOrigin = [$bounds->minX + $clipboard->origin[0], $bounds->minY + $clipboard->origin[1], $bounds->minZ + $clipboard->origin[2]];
			$metadata = \pocketmine\nbt\tag\CompoundTag::create()->setIntArray('ClipboardOrigin', $clipboard->origin);
			$data = new SchematicData($width, $height, $length, $packed, $palette, $worldOrigin, $tiles, 'mcstructure', $bedrockPalette, metadata: $metadata, validateStates: false, formatVersion: 1);
			$session->fileRequest = $this->schematics->save($name, $data, function(?SchematicData $unused, ?string $error) use ($player, $session) : void{
				$session->fileRequest = null;
				if($player->isConnected()){
					$player->sendMessage($error === null ? 'Schematic saved.' : 'Schematic export failed: ' . $error);
				}
			});
		})();
		$this->plugin->submit($player, $this->plugin->world($player->getWorld()), $steps, $clipboard->volume(), null);
	}
}
