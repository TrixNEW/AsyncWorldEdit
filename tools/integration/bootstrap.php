<?php

declare(strict_types=1);

$source = getenv("QUARK_SOURCE") ?: dirname(__DIR__, 3) . "/Quark";
require $source . "/vendor/autoload.php";
$lock = json_decode(file_get_contents($source . "/composer.lock"), true, 512, JSON_THROW_ON_ERROR);
foreach($lock["packages"] as $package){
	$reference = $package["source"]["reference"] ?? $package["dist"]["reference"] ?? null;
	if(!\Composer\InstalledVersions::isInstalled($package["name"]) || ($reference !== null && \Composer\InstalledVersions::getReference($package["name"]) !== $reference)){
		throw new RuntimeException("Installed dependency differs from Quark lock: " . $package["name"]);
	}
}
$installed = require $source . "/vendor/composer/installed.php";
define("AWE_COMPOSER_REFERENCE_ORIGINAL", $installed["root"]["reference"]);
$installed["root"]["reference"] = explode("-", \quark\VersionInfo::GIT_HASH(), 2)[0];
\Composer\InstalledVersions::reload($installed);
$metadata = [];
foreach(array_keys(\Composer\Autoload\ClassLoader::getRegisteredLoaders()) as $vendor){
	if(realpath($vendor) === realpath($source . "/vendor")){
		$metadata[str_replace("\\", "/", $vendor)] = $installed;
	}
}
(new ReflectionProperty(\Composer\InstalledVersions::class, "installedByVendor"))->setValue(null, $metadata);
require $source . "/src/Quark.php";

