<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$runtime = $root . "/.runtime/integration";
foreach(["/plugins", "/data/config"] as $directory){
	if(!is_dir($runtime . $directory) && !mkdir($runtime . $directory, 0777, true)){
		throw new RuntimeException("Cannot create isolated integration directory");
	}
}

function package(string $source, string $output, array $paths) : void{
	if(is_file($output)){
		unlink($output);
	}
	$phar = new Phar($output);
	$phar->startBuffering();
	$phar->setStub("<?php __HALT_COMPILER();");
	foreach($paths as $path){
		if(is_dir($source . "/" . $path)){
			$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source . "/" . $path, FilesystemIterator::SKIP_DOTS));
			foreach($files as $file){
				if($file->isFile()){
					$phar->addFile($file->getPathname(), str_replace("\\", "/", substr($file->getPathname(), strlen($source) + 1)));
				}
			}
		}else{
			$phar->addFile($source . "/" . $path, $path);
		}
	}
	$phar->stopBuffering();
}

package($root, $runtime . "/plugins/AsyncWorldEdit.phar", ["src", "resources", "plugin.toml", "LICENSE"]);
package(__DIR__, $runtime . "/plugins/Integration.phar", ["src", "plugin.toml"]);
$quark = getenv("QUARK_SOURCE") ?: dirname($root) . "/Quark";
$configuration = file_get_contents($quark . "/resources/server.toml");
$replacements = [
	"bind-address = ''" => "bind-address = '127.0.0.1'", "port = 19132" => "port = 19481", "enable-ipv6 = true" => "enable-ipv6 = false",
	"enable-query = true" => "enable-query = false", "generator = 'DEFAULT'" => "generator = 'FLAT'", "view-distance = 16" => "view-distance = 4",
	"enable-dev-builds = false" => "enable-dev-builds = true", "async-workers = 'auto'" => "async-workers = 2", "name = 'nethernet'" => "name = 'raknet'",
	"name = 'world'" => "name = 'awe-" . bin2hex(random_bytes(4)) . "'"
];
foreach($replacements as $search => $replace){
	// Fail loudly when Quark renames a key, otherwise the run silently uses a default world.
	if(substr_count($configuration, $search) !== 1){
		throw new RuntimeException("Quark server.toml no longer contains exactly one \"$search\"");
	}
	$configuration = str_replace($search, $replace, $configuration);
}
file_put_contents($runtime . "/data/config/server.toml", $configuration);
$pluginData = $runtime . "/data/data/plugins/AsyncWorldEdit";
if(!is_dir($pluginData)){
	mkdir($pluginData, 0777, true);
}
$budget = getenv("AWE_BUDGET_MICROS") ?: "2000";
if(!ctype_digit($budget) || (int) $budget < 100 || (int) $budget > 10000){
	throw new RuntimeException("AWE_BUDGET_MICROS must be between 100 and 10000");
}
$pluginConfig = str_replace("budgetMicros = 2000", "budgetMicros = " . $budget, file_get_contents($root . "/resources/config.toml"));
file_put_contents($pluginData . "/config.toml", $pluginConfig);
$result = $runtime . "/data/integration-results.json";
if(is_file($result)){
	unlink($result);
}
echo $runtime . PHP_EOL;

