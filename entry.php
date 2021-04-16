<?php
/*
 * ComposerShader, Experimental shading utility.
 *
 * Licensed under the Open Software License version 3.0 (OSL-3.0)
 * Copyright (C) 2021 JaxkDev
 *
 * Twitter :: @JaxkDev
 * Discord :: JaxkDev#2698
 * Email   :: JaxkDev@gmail.com
 */

function error(string $message, int $exitCode = 1): void{
	echo "[Error] | ".$message."\n";
	exit($exitCode);
}

function info(string $message): void{
	echo "[Info] | ".$message."\n";
}

if(PHP_VERSION_ID < 80000){
	//namespaced tokens changed dramatically in 8, too lazy to have BC with 7.
	error("PHP 8+ Required.");
}
if(!file_exists("vendor/composer/autoload_static.php")){
	error("'vendor/composer/autoload_static.php' not found, are composer dependencies installed ?");
}

info("Fetching composer UID...");

$f = fopen(__dir__."/vendor/composer/autoload_static.php", "r");
$class = null;
$buffer = "";
$buffer = fread($f, 512);
preg_match('/class\s+(\w+)(.*)/', $buffer, $matches);
$class = $matches[1];
$id = substr($class, strlen("ComposerStaticInit"));
$class = "Composer\\Autoload\\$class";
info("Found UID: $id");

info("Analysing namespaces, this can take a few seconds depending on size.");

require_once(__DIR__."/vendor/composer/autoload_static.php");
$data = (new ReflectionClass($class))->getStaticProperties();
$stub_files = array_values($data["files"]);
$psr4 = $data["prefixDirsPsr4"];
$psr0 = array_values($data["prefixesPsr0"]);

info("Found ".sizeof($psr0)." PSR-0 and ".sizeof($psr4)." PSR-4 namespaces.");