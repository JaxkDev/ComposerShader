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

if(PHP_VERSION_ID < 80000){
	//namespaced tokens changed dramatically in 8, too lazy to have BC with 7.
	error("PHP 8+ Required.");
}
if(!extension_loaded("yaml")){
	error("PHP Extension yaml must be installed to use this utility.");
}
if(!file_exists("vendor/composer/autoload_static.php")){
	error("'vendor/composer/autoload_static.php' not found, are composer dependencies installed ?");
}
if(!file_exists("plugin.yml") || !file_exists("src")){
	error("'plugin.yml' and 'src/' not found, make sure plugin source is in same directory.");
}

$startTime = microtime(true);

//TODO cli arg.
$mainNamespace = implode("\\", array_slice(explode("\\", yaml_parse_file("plugin.yml")["main"]), 0, -1));
$shadePrefix = $mainNamespace."\\vendor".bin2hex(random_bytes(4));
@mkdir(__DIR__."/src/$shadePrefix");
info("Using shade-prefix '$shadePrefix'");


$f = fopen(__dir__."/vendor/composer/autoload_static.php", "r");
$class = null;
$buffer = "";
$buffer = fread($f, 512);
preg_match('/class\s+(\w+)(.*)/', $buffer, $matches);
$class = $matches[1];
$id = substr($class, strlen("ComposerStaticInit"));
$class = "Composer\\Autoload\\$class";
info("Found Composer UID: $id");


info("Analysing namespaces...");

require_once(__DIR__."/vendor/composer/autoload_static.php");
$data = (new ReflectionClass($class))->getStaticProperties();
$stub_files = array_values($data["files"]); //TODO.
$psr4 = $data["prefixDirsPsr4"];
$psr0 = array_values($data["prefixesPsr0"]);
//TODO classMap.

//Paths of files to shade & inject.
$psr4_paths = $psr0_paths = $namespaces = [];
$refCount = $fileCount = 0;

foreach($psr4 as $k => $p){
	$k = rtrim($k, "\\");
	if(str_starts_with($k, $mainNamespace) || str_starts_with($mainNamespace, $k)){
		//Dont want to shade the entire plugin...
		error("PSR-4 Namespace ($k) conflicts with plugins main namespace ($mainNamespace)");
	}
	$namespaces[$k[0]][] = $k;
	$psr4_paths[$k] = $p;
}

foreach($psr0 as $v){
	foreach($v as $k => $p){
		if(str_starts_with($mainNamespace, $k)){
			error("PSR-0 Namespace ($k) conflicts with plugins main namespace ($mainNamespace)");
		}
		$i = $k[0];
		foreach($namespaces[$i]??[] as $n){
			if(str_starts_with($n, $k)){
				//Woah a PSR-4 Namespace covers a PSR-0 namespace, what to do, what to do....
				error("A PSR-0 Namespace ($k) collides with the PSR-4 Namespace ($n)");
			}
		}
		$namespaces[$i][] = $k;
		$psr0_paths[$k] = $p;
	}
}
/*
var_dump(shadeReferences(<<<code
<?php

$str = $type.\Discord\studly($key).'Attribute';
code
, $shadePrefix, $namespaces, $refCount));

exit(0);*/
//Shade references in plugin:
$path = __DIR__."\\src";
if(!is_dir($path)) error("Plugin directory '$path' not found.");
$files = getDirFiles($path);
info("Shading plugin file references.");
foreach($files as $file){
	$fileContent = @file_get_contents($file);
	if($fileContent === false) error("Failed to get file contents of '$file'");
	$newContent = shadeReferences($fileContent, $shadePrefix, $namespaces, $refCount);
	@file_put_contents($file, $newContent);
}

//Shade and copy psr4 composer namespaces.
foreach($psr4_paths as $namespace => $paths){
	foreach($paths as $path){
		$path = realpath($path);
		if(!is_dir($path)){
			error("Mismatch between composer autoloader data and files on system, re-install composer dependencies and try again.");
		}
		$files = getDirFiles($path);
		info("Shading PSR-4 '$namespace' (" . substr($path, strlen(__DIR__)+1) . " - " . sizeof($files) . " files)");
		foreach($files as $file){
			$fileContent = file_get_contents($file);
			if($fileContent === false) error("Failed to get file contents of '$file'");
			$newContent = shadeReferences($fileContent, $shadePrefix, $namespaces, $refCount);
			$suffix = substr($file, strlen($path));
			$newPath = __DIR__ . "/src/$shadePrefix/" . $namespace . $suffix;
			$newDir = implode("\\", array_slice(explode("\\", $newPath), 0, -1));
			@mkdir($newDir, 0777, true);
			@file_put_contents($newPath, $newContent);
			++$fileCount;
		}
	}
}

//Shade and copy psr0 composer namespaces.
foreach($psr0_paths as $baseNamespace => $paths){
	foreach($paths as $path){
		//PSR-0, Deprecated several years ago but still found in many common dependencies.
		$path = realpath($path);
		if(!is_dir($path)){
			error("Mismatch between composer autoloader data and files on system, re-install composer dependencies and try again.");
		}
		$files = getDirFiles($path);
		info("Shading PSR-0 '$baseNamespace' (" . substr($path, strlen(__DIR__)+1) . " - " . sizeof($files) . " files)");
		foreach($files as $file){
			$fileContent = file_get_contents($file);
			if($fileContent === false) error("Failed to get file contents of '$file'");
			$newContent = shadeReferences($fileContent, $shadePrefix, $namespaces, $refCount);
			$suffix = substr($file, strlen($path));
			$newPath = __DIR__ . "/src/$shadePrefix/" . $suffix;
			$newDir = implode("\\", array_slice(explode("\\", $newPath), 0, -1));
			@mkdir($newDir, 0777, true);
			@file_put_contents($newPath, $newContent);
			++$fileCount;
		}
	}
}

info("Shaded $fileCount files and $refCount references in ".round(microtime(true)-$startTime,2)."s");
exit(0);





function shadeReferences(string $fileContent, string $shadePrefix, array $namespaces, &$refCount): string {
	$tokens = token_get_all($fileContent);
	$ret = "";
	$namespaceStarted = false;
	foreach($tokens as $token){
		if(is_array($token)){
			$id = $token[0];
			$str = $token[1];
			if($id === T_NAMESPACE){
				$namespaceStarted = true;
			} elseif(($id === T_STRING && $namespaceStarted) or $id === T_NAME_QUALIFIED or $id === T_NAME_FULLY_QUALIFIED){
				$prefix = ($id === T_NAME_FULLY_QUALIFIED ? "\\" : "");
				$namespaceKey = $str[$id === T_NAME_FULLY_QUALIFIED ? 1 : 0];
				$possibleNamespaces = $namespaces[$namespaceKey] ?? [];
				foreach($possibleNamespaces as $pn){
					if(str_starts_with($str, $prefix.$pn)){
						$str = $prefix.$shadePrefix.($id === T_NAME_FULLY_QUALIFIED ? "" : "\\").$str;
						++$refCount;
					}elseif(stripos($str, $prefix . $pn) === 0){
						warning("Not replacing FQN $str case-insensitively.");
					}
				}
				$namespaceStarted = false;
			}
			$ret .= $str;
		}else{
			$ret .= $token;
		}
	}
	++$refCount;
	return $ret;
}

/** @return string[] */
function getDirFiles(string $dir): array{
	$f = [];
	if(!is_dir($dir)) return $f;
	foreach(new DirectoryIterator($dir) as $file){
		if($file->isDot()) continue;
		if($file->isDir()){
			array_push($f, ...getDirFiles($file->getPathname()));
			continue;
		}
		if($file->getExtension() === "php"){
			$f[] = $file->getPathname();
		}
	}
	return $f;
}

/** @noinspection PhpNoReturnAttributeCanBeAddedInspection */
function error(string $message, int $exitCode = 1){
	echo "\033[1;31m[Error]   | $message\033[0;39m\n";
	exit($exitCode);
}

function warning(string $message): void{
	echo "\033[1;33m[Warning] | $message\033[0;39m\n";
}

function info(string $message): void{
	echo "[Info]    | $message\n";
}
