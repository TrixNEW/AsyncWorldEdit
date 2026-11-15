<?php

declare(strict_types=1);

namespace asyncworldedit\schematic;

use InvalidArgumentException;
use Closure;
use RuntimeException;

final class SchematicFileStore{

	private string $directory;

	public function __construct(string $directory, private int $maxFileBytes = 16777216){
		if($maxFileBytes < 1){
			throw new InvalidArgumentException("Schematic file limit must be positive");
		}
		if(!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)){
			throw new RuntimeException("Cannot create schematic directory");
		}
		$this->directory = realpath($directory) ?: throw new RuntimeException("Cannot resolve schematic directory");
	}

	public function read(string $name) : string{
		$path = $this->path($name);
		if(!is_file($path)){
			throw new RuntimeException("Schematic does not exist");
		}
		$handle = @fopen($path, "rb");
		if($handle === false){
			throw new RuntimeException("Cannot open schematic");
		}
		try{
			$stat = fstat($handle);
			if($stat === false || $stat["size"] > $this->maxFileBytes){
				throw new RuntimeException("Schematic exceeds file size limit");
			}
			$bytes = stream_get_contents($handle, $this->maxFileBytes + 1);
			if($bytes === false || strlen($bytes) > $this->maxFileBytes){
				throw new RuntimeException("Cannot read schematic within file size limit");
			}
			return $bytes;
		}finally{
			fclose($handle);
		}
	}

	public function save(string $name, string $bytes, ?Closure $isCancelled = null) : void{
		$path = $this->path($name);
		if(strlen($bytes) > $this->maxFileBytes){
			throw new RuntimeException("Schematic exceeds file size limit");
		}
		$temp = tempnam($this->directory, ".awe-");
		if($temp === false || dirname($temp) !== $this->directory){
			if($temp !== false){
				@unlink($temp);
			}
			throw new RuntimeException("Cannot create temporary schematic");
		}
		try{
			$handle = @fopen($temp, "wb");
			if($handle === false){
				throw new RuntimeException("Cannot open temporary schematic");
			}
			try{
				$offset = 0;
				while($offset < strlen($bytes)){
					$written = fwrite($handle, substr($bytes, $offset));
					if($written === false || $written === 0){
						throw new RuntimeException("Cannot write schematic");
					}
					$offset += $written;
				}
				if(!fflush($handle) || !fsync($handle)){
					throw new RuntimeException("Cannot flush schematic");
				}
			}finally{
				fclose($handle);
			}
			$this->path($name);
			if($isCancelled !== null && $isCancelled()){
				throw new RuntimeException("Schematic save was cancelled");
			}
			if(!@rename($temp, $path)){
				throw new RuntimeException("Cannot atomically save schematic");
			}
		}finally{
			if(is_file($temp)){
				@unlink($temp);
			}
		}
	}

	private function path(string $name) : string{
		if(!preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._-]{0,127}\.(?:schem|mcstructure|schematic)\z/D', $name) || str_contains($name, "..") || preg_match('/\A(?:CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])(?:\.|$)/i', $name)){
			throw new InvalidArgumentException("Use a simple schematic filename with a supported extension");
		}
		$path = $this->directory . DIRECTORY_SEPARATOR . $name;
		clearstatcache(true, $path);
		if(is_link($path) || (file_exists($path) && (!is_file($path) || realpath($path) !== $path))){
			throw new RuntimeException("Schematic path must be a regular file inside its directory");
		}
		return $path;
	}
}
