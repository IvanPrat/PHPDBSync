<?php
/**
 * Copyright (c) 2017 Iván Prat. All Right Reserved.
 *
 * @name      phpdbsync
 *
 * @author    Iván <ivanprat92@gmail.com>
 *
 * @copyright 2017 Iván Prat
 *
 * @url       https://github.com/IvanPrat/PHPEasyDeploy
 *
 * @license   MIT License (http://www.opensource.org/licenses/mit-license.php)
 */

  Class PHPDBSync
  {
    /**
    * The host for the main database with correct structure
    * @var string
    */
    public $fromDBHost;

    /**
    * Your main Database
    * @var string
    */
    public $fromDB;

    /**
    * Main Database Username
    * @var string
    */
    public $fromDBUser;

    /**
    * Main Database Password
    * @var string
    */
    public $fromDBPassword;

    /**
    * Host for the targeted database(s) to get sync
    * @var string
    */
    public $toDBHost;

    /**
    * Targeted database username
    * @var string
    */
    public $toDBUser;

    /**
    * Targeted database password
    * @var string
    */
    public $toDBPassword;

    /**
    * Array with all the databases that must get sync
    * @var array
    */
    public $databases = array();

    /**
    * Information from the main Database (tables, cols...)
    * @var string
    */
    private $mainDatabaseInfo = array();

    /**
    * All debug information will get concatenated inside this prop
    * @var string
    */
    private $debug_log = '';

    /**
    * All the current tables from main DB
    * @var array
    */
    private $mainDBCurrentTables;

    /**
    * Current Connection to the Database
    * @var array
    */
    private $connection;

    /**
    * This Opens a New Connection with the Database
    *
    * @param string $host DB Host
    * @param string $database DB to Connect
    * @param string $user DB User
    * @param string $psswd DB Password
    * @access private
    * @return object
    */
    private function createNewDatabaseConnection($host, $database, $user, $psswd)
    {
      try
      {
        $this->connection = new PDO('mysql:host=' . $host . ';dbname=' . $database, $user, $psswd);
      }
      catch (PDOException $e)
      {
        die("You cannot connect to the DB: " .  $database);
      }

      return $this->connection;
    }

    /**
    * (void)
    * Close DB Connection
    *
    * @access private
    * @return
    */
    private function closeDBConnection()
    {
      $this->connection = null;
    }

    /**
    * (void)
    * Show the Debug Log
    *
    * @access public
    * @return
    */
    public function debugResults()
    {
      printf($this->debug_log);
    }

    /**
    * This will simply set all the information from the main database
    * (the one with the correct structure) in order to compare with the
    * rest of the databases (targeted)
    *
    * @access public
    * @return boolean
    */
    public function setMainDatabaseInfo()
    {
      if($this->createNewDatabaseConnection($this->fromDBHost, $this->fromDB, $this->fromDBUser, $this->fromDBPassword))
      {
        // Get all the tables from the DB we're going to compare
        $tablesFromDB = $this->showTablesFromDB($this->fromDB);

        foreach($tablesFromDB as $k => $table)
        {
          foreach($table as $table_info)
          {
            // Array with stored columns from the DB
            $columns = array();

            // Set all the Columns information into some array in order to check it at the bottom
            foreach($this->getAllTheColumns($table_info) as $cols)
              $columns[$cols['Field']] = $cols;

            // Put all the information into the global Prop (Array)
            $this->mainDatabaseInfo[$table_info] = $columns;

            foreach($this->mainDatabaseInfo as $k => $cols)
            {
              $cols_to_execute = '';

              $query_prepare_table = '';

              $query_prepare_table = "
                CREATE TABLE IF NOT EXISTS `" . $k . "` (
              ";

              $count_cols = count($cols); // last comma!
              $i = 0;

              $table_primary_keys = array();

              foreach($cols as $col)
              {
                $col_name                 = $col['Field'];
                $col_type 				        = $col['Type'];
                $col_is_null 			        = ($col['Null'] == 'NO' ? 'NOT NULL' : 'NULL');
                $col_key                  = $col['Key'];
                $col_default 			        = $col['Default'];
                $col_extra 					      = $col['Extra'];

                if($col_key == 'PRI')
                  $table_primary_keys[] = $col_name;

                $cols_to_execute = '`' . $col_name . '` ' . $col_type . ' ' . $col_is_null . ($col_default != '' ? ' DEFAULT \'' . $col_default . '\' ' : '') . ' ' . $col_extra;

                if($count_cols > $i + 1)
                  $cols_to_execute .= ',';

                $query_prepare_table .= $cols_to_execute;

                $i++;
              }

              // All the keys from this table will be dumped right here
              if(!empty($table_primary_keys))
              {
                $query_prepare_table .= ',PRIMARY KEY(';

                $count_keys = count($table_primary_keys); // last comma!
                $i = 0;

                foreach($table_primary_keys as $key)
                {
                  $query_prepare_table .= '`' . $key . '`';

                  if($count_keys > $i + 1)
                    $query_prepare_table .= ',';

                  $i++;
                }

                $query_prepare_table .= ')';
              }

              $query_prepare_table .= ') ENGINE=MyISAM DEFAULT CHARSET=utf8';

              $this->mainDBCurrentTables[$k] = $query_prepare_table;
            }
          }
        }
      }

      return true;
    }

    /**
    * (void)
    * Drop Indexes in order to re-set again
    *
    * @param string $table Table to Drop the Indexes
    * @access private
    * @return
    */
    private function dropIndexKeys($table)
    {
      foreach($this->showIndex($table) as $index)
      {
        $col_key_name = '';
        $query_remove_index = '';

        // Primary index
        if($index['Key_name'] == 'PRIMARY')
        {
          $col_key_name = $index['Column_name'];

          $query_remove_index = 'ALTER TABLE `' . $table . '` DROP PRIMARY KEY';
        }
        else
        {
          $col_key_name = $index['Key_name'];

          $query_remove_index = 'ALTER TABLE `' . $table . '` DROP INDEX ' . $col_key_name . ';';
        }

        $this->connection->query($query_remove_index);
      }
    }

    /**
    * (void)
    * This method will regenerate the Indexes for a certain table
    *
    * @param string $add_type type: UNIQUE, PRIMARY KEY...
    * @param array $primary_keys values to add
    * @param string $table Table you are working on
    * @access private
    * @return
    */
    private function regenerateIndexes($add_type, $primary_keys, $table)
    {
      // Now we have no indexes. Let's re-create it again

      if(!empty($primary_keys))
      {
        // If single: ALTER TABLE `table` ADD PRIMARY KEY( `col1`);
        // If multiple: ALTER TABLE `table` ADD PRIMARY KEY( `col1`, `col2`);

        $query_alter_key = "
          ALTER TABLE `$table`
            ADD $add_type(";

        $count_keys = count($primary_keys);

        $i = 0;

        foreach($primary_keys as $key)
        {
          $query_alter_key .= '`' . $key . '`';

          if($i < $count_keys - 1)
            $query_alter_key .= ',';

          $i++;
        }

        $query_alter_key .= ');';

        $this->connection->query($query_alter_key);
      }
    }

    /**
    * Return a list of tables from a certain database
    *
    * @param string $database database
    * @access private
    * @return array
    */
    private function showTablesFromDB($database)
    {
      return $this->connection->query("SHOW TABLES FROM " . $database)->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
    * Return a list of columns from a certain table
    *
    * @param string $table table
    * @access private
    * @return array
    */
    private function getAllTheColumns($table)
    {
      return $this->connection->query("SHOW COLUMNS FROM `" . $table . "`")->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
    * (void)
    * Drop a Table + Write Log
    *
    * @param string $table table you want to drop
    * @param string $database from database
    * @access private
    * @return
    */
    private function dropTable($table, $database)
    {
      $this->connection->query("DROP TABLE `$table`");

      $this->writeLog("<strong>Drop table:</strong> $table on $database <br/>");
    }

    /**
    * (void)
    * This will create a new col according to the defined params
    *
    * @param string $table target table
    * @param string $col_name name of the col
    * @param string $col_type type of the col
    * @param string $val_is_null_col check if null
    * @param string $col_default set as default or not
    * @param string $col_extra extras if there are
    * @access private
    * @return
    */
    private function createNewCol($table, $col_name, $col_type, $val_is_null_col, $col_default, $col_extra)
    {
      $query_add_col = "ALTER TABLE `$table` ADD `" . $col_name . "` " . $col_type . " " . $val_is_null_col . " " . (!empty($col_default) ? " DEFAULT '" . $col_default . "' " : '') . $col_extra . ";";

      $this->connection->query($query_add_col);

      $this->writeLog("<strong>Col added:</strong> $k_1 in $table on $database<br/>");
    }

    /**
    * (void)
    * Write a Debug Log
    *
    * @param string $log target table
    * @access private
    * @return
    */
    private function writeLog($log)
    {
      $this->debug_log .= $log;
    }

    /**
    * Execute a query (Show Index) based on table
    *
    * @param string $table target table
    * @access private
    * @return array
    */
    private function showIndex($table)
    {
      return $this->connection->query("SHOW INDEX FROM `" . $table . "`")->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
    * (void)
    * Delete a Col from a Table
    *
    * @param string $table target table
    * @param string $column column to delete
    * @param string $database target database
    * @access private
    * @return
    */
    private function deleteCol($table, $field, $database)
    {
      $this->connection->query("ALTER TABLE `$table` DROP COLUMN " . $field);

      $this->writeLog("<strong>Deleted col:</strong> " . $field . " in $table on $database <br/>");
    }

    /**
    * (void)
    * This function will sync all the databases based on the main already defined
    *
    * @access public
    * @return
    */
    public function syncDatabases()
    {
      foreach($this->databases as $database)
      {
        $this->setMainDatabaseInfo();

        if($this->createNewDatabaseConnection($this->toDBHost, $database, $this->toDBUser, $this->toDBPassword))
        {
          // These are the tables for this database
          $this_db_tables = $this->showTablesFromDB($database);

          // All tables will be dumped here
          $tables_array = array();

          // Key pair first
          // Just put table names in order to identify as soon as possible
          foreach($this_db_tables as $k => $tables)
            foreach($tables as $table)
              $tables_array[$table] = $table;

          $this->writeLog('<h1>' . $database . '</h1>');

          // Now check if there are new add
          // Compare with main tables/database
          foreach($this->mainDatabaseInfo as $k => $tables)
          {
            // If doesn't exists here and does in main... just create it.
            if(!isset($tables_array[$k]))
            {
              // New table to create detected
              // Table information: $this->mainDBCurrentTables[$k]);

              $sql_query = $this->mainDBCurrentTables[$k];

              $this->connection->query($sql_query);

              $this->writeLog("<strong>Create table:</strong> $k on $database <br/>");
            }
          }

          // Okay, we're gonna check the cols now.
          // Check for each table cols

          // We loop over the tables first
          foreach($this_db_tables as $tables)
          {

            // We keep looping... where $table is table name
            foreach($tables as $k => $table)
            {

            $queries_execute = array();

            // Query, and we get all the column names
            $get_this_columns = $this->getAllTheColumns($table);

            // Check if table exists
            // If table doesn't exists, then we're gonna remove it

            if(isset($this->mainDatabaseInfo[$table]))
            {
              // This will be useful in the next step, when we need to compare it.
              // By the moment we're just comparing them
              $current_table_cols                   = array();
              $found_cols_differences               = false;

              // We must place all own keys in a single array and then loop them
              // The following keys will be included if droping or adding new keys
              $current_table_cols_keys               = array();

              // Loop over the array query given
              foreach($get_this_columns as $k_1 => $col)
              {
                $primary_keys                         = array();
                $unique_keys                          = array();

                $current_table_cols[$col['Field']]    = $col['Field'];
                @$col_main_info                       = $this->mainDatabaseInfo[$table][$col['Field']];

                // Check if this col already exists in main

                if(isset($col_main_info))
                {
                  // Col has been found, let's compare them

                  $main_name_col                  = $this->mainDatabaseInfo[$table][$col['Field']]['Field'];
                  $main_type_col                  = $this->mainDatabaseInfo[$table][$col['Field']]['Type'];
                  $main_isnull_col                = $this->mainDatabaseInfo[$table][$col['Field']]['Null'];
                  $main_key_col                   = $this->mainDatabaseInfo[$table][$col['Field']]['Key'];
                  $main_default_col               = $this->mainDatabaseInfo[$table][$col['Field']]['Default'];
                  $main_extra_col                 = $this->mainDatabaseInfo[$table][$col['Field']]['Extra'];

                  $val_is_null_col                = ($this->mainDatabaseInfo[$table][$col['Field']]['Null'] == 'NO' ? 'NOT NULL' : 'NULL');

                  if($col_main_info !== $col)
                  {
                    // This will be useful when we set the keys. Basically this boolean is here for this reason.

                    $found_cols_differences = true;

                    // Diferences found, let's change it.
                    // If needed to change, just CHANGE

                    $queries_execute[] = array(
                      "query" => "ALTER TABLE `$table` CHANGE `" . $main_name_col . "` `" . $main_name_col . "` " . $main_type_col . " " . $val_is_null_col . " " . ($main_default_col != '' ? " DEFAULT '" . $main_default_col . "' " : ''),
                      "extras" => $main_extra_col
                    );

                    // If Primary...
                    if($main_key_col == 'PRI')
                      $primary_keys[] = $main_name_col;

                    // If Unique...
                    if($main_key_col == 'UNI')
                      $unique_keys[] = $main_name_col;

                    $this->writeLog("<strong>Changed col:</strong> " . $col['Field'] . " in $table structure on $database <br/>");
                  }
                }
                else
                {
                  // The col has been removed into main database

                  $this->deleteCol($table, $col['Field'], $database);
                }
              }

              if($found_cols_differences)
              {
                // Now the query W/O EXTRAS

                foreach($queries_execute as $query)
                  $this->connection->query($query['query'] . ' ' . ';');

                // This will remove all indexes
                // By default, it removes all indexes/keys, and we reset it again

                $this->dropIndexKeys($table);

                $this->regenerateIndexes('PRIMARY KEY', $primary_keys, $table);

                if(!empty($unique_keys))
                  $this->regenerateIndexes('UNIQUE', $unique_keys, $table);

                // Now the query WITH EXTRAS
                // We have removed the keys, so it won't crash for sure.

                foreach($queries_execute as $query)
                  $this->connection->query($query['query'] . ' ' . $query['extras'] . ';');

              }

              // Check if there are added cols
              // All columns are were listed above within' the function $current_table_cols.
              // So now, we're gonna compare them each other with the main ones.

              foreach($this->mainDatabaseInfo[$table] as $k_1 => $main_cols)
              {
                // Column information (from main table!)

                $col_value          = $this->mainDatabaseInfo[$table][$k_1];

                $col_name 				  = $col_value['Field'];              // ie: id
                $col_type 				  = $col_value['Type'];               // ie: int(10) unsigned
                $col_is_null 			  = $col_value['Null'];               // ie: NO
                $col_default 			  = $col_value['Default'];            // ie: default value
                $col_extra          = $col_value['Extra'];              // ie: auto_increment

                // Column information (self)

                $col_info			      = $this->mainDatabaseInfo[$table][$k_1];

                $this_name_col			= $col_info['Field'];
                $this_type_col      = $col_info['Type'];
                $this_is_null_col   = $col_info['Null'];
                $this_default_col   = $col_info['Default'];
                $this_extra         = $col_info['Extra'];

                $val_is_null_col 		= ($col_value['Null'] == 'NO' ? 'NOT NULL' : 'NULL');

                // Create col in this current table
                if(!isset($current_table_cols[$k_1]))
                  $this->createNewCol($table, $col_name, $col_type, $val_is_null_col, $col_default, $col_extra);

              }
            }
            else
            {
              // This table doesn't exists into main database, so just remove it.
              // It seems like this table has been removed.
              $this->dropTable($table, $database);
            }
          }

          $this->writeLog('<br/><br/>');

          // Close connection due the next one.
        }

        // Close this database connection each loop!
        $this->closeDBConnection();
      }
    }
  }
}
