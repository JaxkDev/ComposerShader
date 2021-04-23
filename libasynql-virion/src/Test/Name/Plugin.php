<?php

namespace Test\Name;

use pocketmine\plugin\PluginBase;

class Plugin extends PluginBase{

	/** @var Array<string, bool> */
	protected const DEFAULT_RESOURCES = ["mysql.sql" => true, "mysql.yml" => false];

	protected SharedDatabase $sharedDatabase;

	public function onLoad(){
		$this->saveDefaultResources();
		$this->sharedDatabase = new SharedDatabase($this);
	}

	private function saveDefaultResources(): void{
		foreach(self::DEFAULT_RESOURCES as $file => $force){
			$this->saveResource($file, $force);
		}
	}

	//Getters.

	public function getSharedDatabase(): SharedDatabase{
		return $this->sharedDatabase;
	}
}