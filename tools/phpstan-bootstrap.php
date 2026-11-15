<?php

declare(strict_types=1);

$quark = getenv('QUARK_PATH') ?: dirname(__DIR__, 2) . '/Quark';
if(is_file($quark . '/vendor/autoload.php')){
	require_once $quark . '/vendor/autoload.php';
}
