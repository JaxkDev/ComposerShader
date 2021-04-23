<?php

namespace Test\Name;

use Test\Name\vendor09ef0ed1\poggit\libasynql\DataConnector;
use Test\Name\vendor09ef0ed1\poggit\libasynql\libasynql;

use function yaml_parse_file;

class SharedDatabase{

	private DataConnector $db;

	public function __construct(Plugin $plugin){
		$config = @yaml_parse_file($plugin->getDataFolder()."mysql.yml");
		$this->db = libasynql::create($plugin, $config, [
			"sqlite" => "sqlite.sql",
			"mysql" => "mysql.sql"
		]);
	}

	public function close(): void{
		if(isset($this->db)){
			$this->db->waitAll();
			$this->db->close();
		}
	}
}