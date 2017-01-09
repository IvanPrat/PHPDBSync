# PHPDBSync

This class will handle the syncronization between two or more databases when you run it. It will handle not just the tables, but also the cols and its values at once in order to make each other equal.

## Options

Just include the file, set the options and sync two databases or more.

* __fromDBHost__: the host for the main database with correct structure
* __fromDB__: your main database
* __fromDBUser__: main database username
* __fromDBPassword__: main database password
* __toDBHost__: host for the targeted database(s) to get sync
* __toDBUser__: targeted database username
* __toDBPassword__: targeted database password
* __databases__: array with all the databases that must get sync

## Using the Class

```php
<?php

/*
* Sometimes it takes a long time, we recommend to Set Time Limit to 0
*/  
$sync = new PHPDBSync;

/*
* The main Database that possesses the good structure
*/
$sync->fromDBHost = 'localhost';
$sync->fromDB = 'mymain_db';
$sync->fromDBUser = 'root';
$sync->fromDBPassword = 'root';

/*
* Targeted Databases to get sync
*/
$sync->toDBHost = 'localhost';
$sync->toDBUser = 'root';
$sync->toDBPassword = 'root';

/*
* All the databases listed that you want to sync
*/
$sync->databases = array(
  'db_to_get_sync'
);

/*
* Run the Class
*/
$sync->syncDatabases();

/*
* Show Debug Results
*/
$sync->debugResults();

?>
```

## License

Copyright (c) 2017 Iván Prat (https://github.com/IvanPrat)

Licensed under the MIT License (http://www.opensource.org/licenses/mit-license.php)
