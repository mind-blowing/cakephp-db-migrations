<?php
/**
 * The FixtureTask runs a specified database fixture.
 *
 * Run 'cake fixtures help' for more info and help on using this script.
 * 
 * PHP versions 4 and 5
 *
 * Licensed under The MIT License
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright		Copyright 2008, Joel Moss
 * @link            http://developwithstyle.com
 * @since			CakePHP(tm) v 1.2
 * @license			http://www.opensource.org/licenses/mit-license.php The MIT License
 */

App::import('Core', array('file', 'folder', 'model', 'connection_manager'));

class FixturesShell extends Shell
{
    var $dataSource = 'default';
    var $db;
    
    /**
     * Array of database tables
     */
    var $tables = array();

    function startup()
    {
        if (isset($this->params['ds'])) $this->dataSource = $this->params['ds'];
        if (isset($this->params['datasource'])) $this->dataSource = $this->params['datasource'];

        define('FIXTURES_PATH', APP_PATH .'config' .DS. 'fixtures');
        if (!$this->_loadDbConfig()) exit;
        $this->db =& ConnectionManager::getDataSource($this->dataSource);

        $this->welcome();
        $this->out('App:  '. APP_DIR);
        $this->out('Path: '. ROOT . DS . APP_DIR);
        $this->out('');
        $this->hr();
        
		$this->tables = $this->db->sources();
		if (!$this->tables) {
		    $this->out($this->_colorize("Database does not contain any tables.", 'COMMENT'));
		    $this->out($this->_colorize("Don't forget to run your migrations before your fixtures..\n", 'COMMENT'));
		    $this->hr();
		    $this->out();
		    exit;
		}
    }

	function main()
	{
	    $this->out();
	    $fixtures = count($this->args) ? $this->args : '*';
	    
		$folder = new Folder(FIXTURES_PATH, true, 0777);
		list($dirs, $files) = $folder->read();
		if (!$files) {
		    $this->out($this->_colorize("There are no Fixtures yet created.", 'COMMENT'));
		    $this->out($this->_colorize("Run 'cake fixtures g' to generate empty fixture files for each table.\n", 'COMMENT'));
		    $this->hr();
		    $this->out();
		    exit;
	    }
		
	    if ($fixtures == '*') {
	        $fixtures = $this->tables;
	    }

		require 'fixture_helpers.php';
		$this->helpers = new FixtureHelpers();
		
		$fixtures = (array)$fixtures;
        foreach ($fixtures as $t) {
            if ($t == 'schema_migrations' || $t == Configure::read('Session.table')) continue;
            
            if (!in_array($t, $this->tables)) {
                if (!isset($this->params['verbose']) && !isset($this->params['v'])) continue;
                
                $this->out("Running fixtures for '".$t."' ...", false);
                $this->out($this->_colorize("FAIL", 'ERROR') . $this->_colorize(" table $t does not exist", 'COMMENT'));
            } else {
                if (!file_exists(FIXTURES_PATH .DS. $t .'.yml')) {
                    if (!isset($this->params['verbose']) && !isset($this->params['v'])) continue;
                    
                    $this->out("Running fixtures for '".$t."' ...", false);
                    $this->out($this->_colorize("FAIL", 'ERROR') . $this->_colorize(" $t.yml does not exist", 'COMMENT'));
                } else {
                    $this->_startFixture($t);
                }
            }
        }
        
		$this->out();
		$this->hr();
		$this->out();
	}

	function _startFixture($name)
	{
		$file = FIXTURES_PATH .DS. $name .'.yml';
        if (function_exists('syck_dump')) {
            $data = syck_load($this->_parsePhp($file));
        } else {
            App::import('Vendor', 'Spyc');
            $data = Spyc::YAMLLoad($this->_parsePhp($file));
        }
		
		if (!is_array($data) || !count($data)) {
		    if (!isset($this->params['verbose']) && !isset($this->params['v'])) return;
		    $this->out("Running fixtures for '".$name."' ...", false);
		    $this->out($this->_colorize("FAIL", 'ERROR') . $this->_colorize(" unable to parse YAML", 'COMMENT'));
		    return;
	    }
	    
	    $this->out("Running fixtures for '".$name."' ...", false);

		$model = new Model(false, $name);
		$this->db->truncate($model);

		$count = 0;
		$created = array();
        foreach (Set::extract($data, '{n}.' . $name) as $ri => $r) {
            $records = array();
            foreach ($r as $fi => $f) {
                if (preg_match("/_id$/", $fi) && !is_id($f) && isset($created[$f])) {
                    $records[$fi] = $created[$f]['id'];
                } elseif (preg_match("/^\.([A-Z_]+)$/", $f, $matches)) {
                    $helper = Inflector::variable(strtolower($matches[1]));
                    if (!method_exists($this->helpers, $helper)) $this->err("Found Helper '$f' in fixture '$name.yml', but Helper method '$helper()' does not exist.");
                    $records[$fi] = $this->helpers->$helper();
                } else {
                    $records[$fi] = $f;
                }
            }

            if (isset($model->_schema['created']) && !array_key_exists('created', $records)) {
                $records['created'] = date('Y-m-d H:i:s');
            }

            $use_uuid = false;
            if (!array_key_exists('id', $r) && isset($model->_schema['id']) && $model->_schema['id']['type'] == 'string' && $model->_schema['id']['length'] == 36) {
                $records['id'] = String::uuid();
                $use_uuid = true;
            }

            $res = $this->db->create($model, array_keys($records), array_values($records));
            if ($res) {
                $records['id'] = $use_uuid ? $records['id'] : $model->id;
                $created[$ri] = $records;
            }
            $count++;
        }
        
		$this->out($this->_colorize("$count rows inserted.", 'COMMENT'));
	}
	
/**
 * Generates a fixture file for each database table.
 * This has been taken from the generator shell which is now deprecated
 */
	function generate()
	{
	    if (!$this->args) {
	        $fixtures = '*';
	    } else {
	        if ($this->args[0] == 'fromdb') {
	            $all_fromdb = true;
	            $fixtures = '*';
	        } else {
	            $fixtures = $this->args;
	            if ($all_fromdb = (is_array($fixtures) && end($fixtures) == 'fromdb')) array_pop($fixtures);
	        }
	    }
		
	    if ($fixtures == '*') {
	        $fixtures = $this->tables;
	    }
		
		$this->out('');
		$data = "#\n# Fixture YAML file\n#\n#\n# Example:-\n# -\n#  first_name: Bob\n#  last_name: Bones\n#  created: NOW\n#\n";
		foreach ($fixtures as $t) {
		    if ($t == 'schema_migrations' || $t == Configure::read('Session.table')) continue;
		    
		    $_option = false;
		    if (strpos($t, ':')) list($t, $_option) = explode(':', $t);
		    
			if ($all_fromdb || $_option == 'fromdb') {
			    $this->out("Generating and populating fixtures file for '".$t."' table ...", false);
		    } else {
		        $this->out("Generating fixtures file for '".$t."' table ...", false);
		    }
		    
			if (!in_array($t, $this->tables)) {
			    $this->out($this->_colorize("FAIL", 'ERROR') . $this->_colorize(" table $t does not exist", 'COMMENT'));
			    continue;
			}
			
		    if (isset($this->params['force']) || !file_exists(FIXTURES_PATH .DS. $t . '.yml')) {
        	    if ($all_fromdb || $_option == 'fromdb') {
        	       $data = $this->_fromDB($t);
        	    }

        	    $file = new File(FIXTURES_PATH .DS. $t . '.yml', true);
				$file->write($data);

				$this->out($this->_colorize("DONE!", 'INFO'));
			} else {
			    $this->out($this->_colorize("FAIL", 'ERROR') . $this->_colorize(" fixtures file already exists", 'COMMENT'));
			}
		}

		$this->out();
	    $this->hr();
	    $this->out();
	}
	
	/**
	 * Alias for generate method
	 */
	function g()
	{
	    $this->generate();
	}
	
	/**
	 * Alias for generate method
	 */
	function gen()
	{
	    $this->generate();
	}
	
	/**
	 * Alias for generate method
	 */
	function create()
	{
	    $this->generate();
	}
	
	function _fromDb($table)
	{
	    $data = $this->db->query("SELECT * FROM $table");
		if (function_exists('syck_dump')) {
			return @syck_dump($data);
		} else {
			return Spyc::YAMLDump($data);
		}
	}
	
	function _parsePhp($file)
	{
		ob_start();
		include ($file);
		$buf = ob_get_contents();
		ob_end_clean();
		return $buf;
	}
	
	/**
	 * Help method
	 */
	function help()
	{
	    $this->out();
        $this->out('Fixtures are an easy way to insert test data into your database.');
        $this->out('This shell generates fixtures files for tables in your database, and can then insert');
        $this->out('those fixtures into the associated database tables. It can even create fixtures');
        $this->out('taken from existing data within your database tables.');
        $this->out();
        $this->out();
        $this->out($this->_colorize('COMMANDS', 'UNDERSCORE'));
        $this->out('');
        $this->out($this->_colorize('  cake fixtures', 'COMMENT'));
        $this->out('      Populates the database with all fixtures.');
        $this->out();
        $this->out($this->_colorize('  cake fixtures table_name [table_name] ...', 'COMMENT'));
        $this->out('      Populates the database with the specified fixtures (table names).');
        $this->out('      Separate each table name with a space.');
        $this->out();
        $this->out($this->_colorize('  cake fixtures g|gen|generate|create', 'COMMENT'));
        $this->out('      Generates fixture files for all tables in the database.');
        $this->out();
        $this->out($this->_colorize('  cake fixtures g|gen|generate|create table_name [table_name] [fromdb] ...', 'COMMENT'));
        $this->out('      Generates fixture files for the specified tables (table_name).');
        $this->out('      Separate each table name with a space.');
        $this->out();
        $this->out("      Append 'fromdb' and fixtures file will be populated from the associated database table.");
        $this->out();
        $this->out('      When generating several fixtures files, and you want to populate the fixtures from');
        $this->out("      existing data in the associated table, but only for specific tables, append ':fromdb'");
        $this->out('      onto the table name. (e.g. cake fixtures g table1 table2:fromdb table3)');
        $this->out();
        $this->out($this->_colorize('  cake fixtures help', 'COMMENT'));
        $this->out('      Displays this Help');
        $this->out();
        $this->out($this->_colorize('COMMANDS', 'UNDERSCORE'));
        $this->out();
        $this->out($this->_colorize('  -ds [data_source]', 'COMMENT'));
        $this->out("      The datasource to use from database.php (default is 'default')");
        $this->out();
        $this->out($this->_colorize('  -f|force', 'COMMENT'));
        $this->out("      When generating fixtures, will force the creation, overwriting any existing fixtures.");
        $this->out();
        $this->out($this->_colorize('  -v|verbose', 'COMMENT'));
        $this->out("      Will print out more info.");
        $this->out();
        $this->out();
        $this->out('For more information and for the latest release of this as part of the');
        $this->out('CakePHP Migrations Suite, go to http://github.com/joelmoss/cakephp-db-migrations');
        $this->out();
        $this->hr();
        $this->out();
	}
	
	function err($str)
	{
		$this->out('');
		$this->out($this->_colorize('  ** '.$str.' **', 'ERROR'));
		$this->out('');
		$this->hr();
		$this->out('');
		exit;
	}
	
    var $styles = array(
      'ERROR'    => array('bg' => 'red', 'fg' => 'white', 'bold' => true),
      'INFO'     => array('fg' => 'green', 'bold' => true),
      'COMMENT'  => array('fg' => 'yellow'),
      'QUESTION' => array('bg' => 'cyan', 'fg' => 'black', 'bold' => false),
      'BOLD'     => array('fg' => 'white', 'bold' => true),
      'UNDERSCORE'     => array('fg' => 'white', 'underscore' => true)
    );
    var $options    = array('bold' => 1, 'underscore' => 4, 'blink' => 5, 'reverse' => 7, 'conceal' => 8);
    var $foreground = array('black' => 30, 'red' => 31, 'green' => 32, 'yellow' => 33, 'blue' => 34, 'magenta' => 35, 'cyan' => 36, 'white' => 37);
    var $background = array('black' => 40, 'red' => 41, 'green' => 42, 'yellow' => 43, 'blue' => 44, 'magenta' => 45, 'cyan' => 46, 'white' => 47);
	
	function out($string = '', $newline = true)
	{
        return parent::out(' ' . $string, $newline);
	}
	
	function _colorize($text = '', $style = null)
	{
        if (!$this->_supportsColors() || is_null($style)) {
            return $text;
        }
        
        $parameters = $this->styles[$style];
        $codes = array();
        if (isset($parameters['fg'])) {
            $codes[] = $this->foreground[$parameters['fg']];
        }
        if (isset($parameters['bg'])) {
            $codes[] = $this->background[$parameters['bg']];
        }
        foreach ($this->options as $option => $value) {
            if (isset($parameters[$option]) && $parameters[$option]) {
                $codes[] = $value;
            }
        }

        return "\033[".implode(';', $codes).'m'.$text."\033[0m";
	}
	
    function _supportsColors()
    {
        return DS != '\\' && function_exists('posix_isatty') && @posix_isatty(STDOUT);
    }
	
	function welcome()
	{
		$this->out('');
        $this->out($this->_colorize(' __  __  _  _  __  __  _  _  __     __      ___      _   __  _ ', 'INFO'));
        $this->out($this->_colorize('|   |__| |_/  |__ |__| |__| |__|   |_  | \/  |  | | |_| |__ |_ ', 'INFO'));
        $this->out($this->_colorize('|__ |  | | \_ |__ |    |  | |      |   | /\  |  |_| | \ |__  _|', 'INFO'));
        $this->out('');
	}
	
}

if (!function_exists('is_id'))
{
  function is_id($id)
  {
    return (preg_match("/^[a-zA-Z0-9]{8}-[a-zA-Z0-9]{4}-[a-zA-Z0-9]{4}-[a-zA-Z0-9]{4}-[a-zA-Z0-9]{12}$/", $id) || is_numeric($id));
  }
}

?>