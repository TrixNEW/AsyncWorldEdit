<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$output = $root . '/build/AsyncWorldEdit.phar';
if(!is_dir(dirname($output)) && !mkdir(dirname($output), 0755, true)){
	throw new RuntimeException('Cannot create build directory');
}
if(is_file($output)){
	unlink($output);
}
$phar = new Phar($output);
$phar->startBuffering();
$phar->setStub("<?php __HALT_COMPILER();");
$phar->setSignatureAlgorithm(Phar::SHA256);
foreach(['src', 'resources'] as $directory){
	$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $directory, FilesystemIterator::SKIP_DOTS));
	foreach($files as $file){
		if($file->isFile()){
			$local = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
			$phar->addFile($file->getPathname(), $local);
		}
	}
}
foreach(['plugin.toml', 'LICENSE'] as $name){ $phar->addFile($root . '/' . $name, $name); }
$phar->stopBuffering();
echo $output . PHP_EOL;
