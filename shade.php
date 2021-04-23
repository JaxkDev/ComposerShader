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

// Beware, there some dragons hiding, waiting to burn your eyes, you have been warned...

const VERSION = "0.2.0-dev";

//PHP8 Introduced namespace tokens which makes this 100x easier, https://wiki.php.net/rfc/namespaced_names_as_token
if(PHP_VERSION_ID < 80000){
	error("PHP 8+ Required.");
}

//Required to parse main file (& namespace) from plugin.yml
if(!extension_loaded("yaml")){
	error("PHP Extension yaml must be installed to use this utility.");
}

//All composer files that store information about autoload files, namespaces and directories.
foreach(["autoload_classmap.php", /*"autoload_files.php",*/ "autoload_namespaces.php", "autoload_psr4.php"] as $file){
	if(!file_exists("vendor/composer/$file")){
		error("'vendor/composer/$file' not found, are composer dependencies installed ?");
	}
}

//Plugin must be in source form in the same directory as vendor/
if(!file_exists("plugin.yml") || !file_exists("src")){
	error("'plugin.yml' and 'src/' not found, make sure plugin source is in same directory.");
}

$START_TIME = microtime(true);

//Parse main files location and namespace from plugin.yml
$mainFile = @yaml_parse_file("plugin.yml")["main"];
$mainNamespace = implode("\\", array_slice(explode("\\", $mainFile), 0, -1));
$mainFile = __DIR__."\\src\\$mainFile.php";

//Generate a UID and shade-prefix.
$UID = "99999999";
try{
	$UID = bin2hex(random_bytes(4));
}catch(Exception $e){
	warning("Failed to generate random UID, using 99999999");
}
$rawShadePrefix = trim($argv[1]??"");
if($rawShadePrefix === ""){
	$rawShadePrefix = "vendor$UID";
}else{
	if(preg_match("/^[a-zA-Z0-9]+$/", $rawShadePrefix) !== 1|| strlen($rawShadePrefix) < 4){
		error("Specified shade-prefix '$rawShadePrefix' does not match the requirement of 4+ characters (a-Z,0-9)");
	}
}
$shadePrefix = "$mainNamespace\\$rawShadePrefix";
info("Using shade-prefix '$shadePrefix'");


info("Analysing namespaces...");

//Files that should be 'required'/'imported' at start often to register functions / constants in their namespace, once and once only.
//No file is present if not files need to be required at start.
$autoload_files = is_file("vendor/composer/autoload_files.php") ? require("vendor/composer/autoload_files.php") : [];
//The expected method of namespace auto-loading, PSR-4.
$psr4 = require("vendor/composer/autoload_psr4.php");
//Although deprecated several years ago, *many* dependencies still used, specify a PSR-0 namespace.
$psr0 = require("vendor/composer/autoload_namespaces.php");
//TODO classMap? this will require a special method of shading (checking entire namespace not just prefix),
//Note could be `T_USE T_STRING`

//Validate autoload data before using it.
if(!is_array($autoload_files)) error("'vendor/composer/autoload_files.php' is corrupt or in an unknown format.");
else $autoload_files = array_values($autoload_files);
if(!is_array($psr4)) error("'vendor/composer/autoload_psr4.php' is corrupt or in an unknown format.");
if(!is_array($psr0)) error("'vendor/composer/autoload_namespaces.php' is corrupt or in an unknown format.");

//Paths of files to shade & inject.
$psr4_paths = $psr0_paths = $namespaces = $new_autoload_files = [];
$refCount = $fileCount = 0;

//Parse the psr4 namespaces and check for any conflicts with plugin.
foreach($psr4 as $namespace => $path){
	$namespace = rtrim($namespace, "\\");
	if(str_starts_with($namespace, $mainNamespace) || str_starts_with($mainNamespace, $namespace)){
		//If we were to shade the namespace that conflicts the plugin we would end up shading the entire plugin and breaking the plugin.
		//So we just ignore it, it may be the plugins namespace specified in composer.json (this is added to the autoload files along with dep's)
		warning("Ignoring PSR-4 Namespace ($namespace) as it conflicts with plugins main namespace ($mainNamespace)");
		continue;
	}
	//Index the namespaces by first letter for slightly faster search times in much larger and complex dependency tree's
	$namespaces[$namespace[0]][] = $namespace;
	$psr4_paths[$namespace] = $path;
}

foreach($psr0 as $namespace => $path){
	if(str_starts_with($mainNamespace, $namespace)){
		warning("Ignoring PSR-0 Namespace ($namespace) as it conflicts with plugins main namespace ($mainNamespace)");
		continue;
	}
	foreach($namespaces[$namespace[0]]??[] as $n){
		if(str_starts_with($n, $namespace)){
			//Not actually seen this case occur but it's probably best to check it before chancing it,
			//If anyone gets this let me know, even if PSR-0 was deprecated several years ago.
			error("A PSR-0 Namespace ($namespace) collides with the PSR-4 Namespace ($n)");
		}
	}
	$namespaces[$namespace[0]][] = $namespace;
	$psr0_paths[$namespace] = $path;
}
/*
var_dump(shadeReferences(<<<code
<?php

$str = $type.\Discord\studly($key).'Attribute';
code
, $shadePrefix, $namespaces, $warnings, $refCount));

exit(0);*/
//Shade references in plugin:
$path = __DIR__."\\src";
if(!is_dir($path)) error("Plugin directory '$path' not found.");
$files = getDirFiles($path);
info("Shading plugin file references.");
$foundComposer = false;
foreach($files as $file){
	$fileContent = @file_get_contents($file);
	if($fileContent === false) error("Failed to get file contents of '$file'");
	if(str_contains($fileContent, "COMPOSER_AUTOLOAD")) $foundComposer = true;
	$warnings = [];
	$newContent = shadeReferences($fileContent, $shadePrefix, $namespaces, $warnings, $refCount);
	if(sizeof($warnings) !== 0){
		warning("Problems identified in plugin source at '$file':");
		foreach($warnings as $line => $warning){
			warning("L$line : ".implode("\n- ", $warning));
		}
	}
	@file_put_contents($file, $newContent);
}
if(sizeof($autoload_files) > 0 and !$foundComposer){
	error("Plugin source does not require 'COMPOSER_AUTOLOAD' but ".sizeof($autoload_files)." autoload files have been found.");
}

@mkdir(__DIR__."/src/$shadePrefix");

//TODO Merge with PSR-0, Duplicated entire segment because of difference in $newPath
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
			$warnings = [];
			$newContent = shadeReferences($fileContent, $shadePrefix, $namespaces, $warnings, $refCount);
			if(sizeof($warnings) !== 0){
				warning("Problems identified in '$file':");
				foreach($warnings as $line => $warning){
					warning("L$line : ".implode("\n- ", $warning));
				}
			}
			$suffix = substr($file, strlen($path));
			//PMMP Plugins only support PSR-0 so place the new file in its rightful shaded folder namespace.
			$newPath = __DIR__ . "/src/$shadePrefix/" . $namespace . $suffix;
			$newDir = implode("\\", array_slice(explode("\\", $newPath), 0, -1));
			@mkdir($newDir, 0777, true);
			@file_put_contents($newPath, $newContent);
			++$fileCount;

			//Check if file is auto-loaded at start.
			foreach($autoload_files as $k => $sf){
				if(realpath($sf) === $file){
					$new_autoload_files[] = $namespace.$suffix;
					unset($autoload_files[$k]);
				}
			}
		}
	}
}

//Shade and copy psr0 composer namespaces.
foreach($psr0_paths as $baseNamespace => $paths){
	foreach($paths as $path){
		$path = realpath($path);
		if(!is_dir($path)){
			error("Mismatch between composer autoloader data and files on system, re-install composer dependencies and try again.");
		}
		$files = getDirFiles($path);
		info("Shading PSR-0 '$baseNamespace' (" . substr($path, strlen(__DIR__)+1) . " - " . sizeof($files) . " files)");
		foreach($files as $file){
			$fileContent = file_get_contents($file);
			if($fileContent === false) error("Failed to get file contents of '$file'");
			$warnings = [];
			$newContent = shadeReferences($fileContent, $shadePrefix, $namespaces, $warnings, $refCount);
			$suffix = substr($file, strlen($path));
			//PMMP Plugins only support PSR-0 so place the new file in its rightful shaded folder namespace.
			$newPath = __DIR__ . "/src/$shadePrefix/" . $suffix;
			$newDir = implode("\\", array_slice(explode("\\", $newPath), 0, -1));
			@mkdir($newDir, 0777, true);
			@file_put_contents($newPath, $newContent);
			++$fileCount;
			//Check if file is auto-loaded at start.
			foreach($autoload_files as $k => $sf){
				if(realpath($sf) === $file){
					$new_autoload_files[] = $suffix;
					unset($autoload_files[$k]);
				}
			}
		}
	}
}

if($foundComposer){
	info("Generating autoloader file.");

	//if there is left over autoload_files its probable its an entire dependency with only polyfills which can break badly if requiring other files not shaded...
	//@mkdir("src/$shadePrefix/autoload$UID/");
	//TODO Recursively check tokens for any requires/includes and then cross reference the string/namespace with shaded files, if not shaded copy it over to autoload dir.

	$timestamp = date('Y-m-d H:i:s');
	$ver = VERSION;
	$autoloadFileContents = <<<code
	<?php
	
	/*
	 * This file has been automatically generated by ComposerShader, do not touch !
	 * 
	 * Version: v$ver
	 * Timestamp: $timestamp
	 * 
	 * Shaded namespace: $shadePrefix
	 * 
	 * https://github.com/JaxkDev/ComposerShader
	 */
	
	namespace $shadePrefix;
	
	
	code;

	foreach($new_autoload_files as $file){
		$autoloadFileContents .= <<<code
	require_once(__DIR__ . '\\$file');
	
	code;
	}

	@file_put_contents("src/$shadePrefix/autoload.php", $autoloadFileContents);

	//Add constant to main class namespace.
	@file_put_contents($mainFile, injectCode(@file_get_contents($mainFile), "const COMPOSER_AUTOLOAD = __DIR__.'/$rawShadePrefix/autoload.php';"));
}

info("Shaded $fileCount files and $refCount references in ".round(microtime(true)-$START_TIME,2)."s");
exit(0);


/*
 * TODO's:
 * shade (string args):
 *  - define(d) calls
 *  - class_exists / class_alias / interface_exists
 *  - get_class_vars / get_class_methods
 *  -
 *
 */
function shadeReferences(string $fileContent, string $shadePrefix, array $namespaces, &$warnings, &$refCount): string{
	$tokens = token_get_all($fileContent);
	$ret = "";
	$namespaceStarted = false;
	foreach($tokens as $token){
		if(is_array($token)){
			$id = $token[0];
			$str = $token[1];
			$line = $token[2];
			if($id === T_EVAL){
				$warnings[$line][] = "eval() is used here, this is insecure and strongly discouraged !!";
			} elseif($id === T_EXIT){
				$warnings[$line][] = "exit()/die() is used here, this will break your pmmp server if executed on the main thread.";
			} elseif($id === T_NAMESPACE){
				$namespaceStarted = true;
			} elseif(($id === T_STRING && $namespaceStarted) or $id === T_NAME_QUALIFIED or $id === T_NAME_FULLY_QUALIFIED){
				$prefix = ($id === T_NAME_FULLY_QUALIFIED ? "\\" : "");
				$namespaceKey = $str[$id === T_NAME_FULLY_QUALIFIED ? 1 : 0];
				$possibleNamespaces = $namespaces[$namespaceKey] ?? [];
				foreach($possibleNamespaces as $pn){
					if(str_starts_with($str, $prefix.$pn)){
						$str = $prefix.$shadePrefix.($id === T_NAME_FULLY_QUALIFIED ? "" : "\\").$str;
						++$refCount;
					} elseif(stripos($str, $prefix . $pn) === 0){
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


function injectCode(string $content, string $code): string{
	$tokens = token_get_all($content);
	$ret = "";
	$namespaceLine = null;
	foreach($tokens as $token){
		if(is_array($token)){
			$id = $token[0];
			$str = $token[1];
			$line = $token[2];
			if($id === T_NAMESPACE){
				$namespaceLine = $line;
			} elseif($namespaceLine !== null and $line > $namespaceLine and $id !== T_COMMENT and $id !== T_DOC_COMMENT and $id !== T_WHITESPACE){
				$str = "\n//The following code is generated by ComposerShader, do not touch.\n$code\n//End of generated code.\n\n" . $str;
				$namespaceLine = null;
			}
			$ret .= $str;
		} else{
			$ret .= $token;
		}
	}
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

function error(string $message, int $exitCode = 1){
	echo "\033[1;31m[Error]   | $message\033[0;39m\n";
	if($exitCode >= 0) exit($exitCode);
}

function warning(string $message): void{
	echo "\033[1;33m[Warning] | $message\033[0;39m\n";
}

function info(string $message): void{
	echo "[Info]    | $message\n";
}
