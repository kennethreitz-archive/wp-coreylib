<?php
clAPI::configure(array(
	"display_errors" => false, 
	"debug" => false,				// set to allow coreylib to talk to you about its caching and feed parsing
	"nocache" => false, 			// set to true to prevent all caching
	"max_download_tries" => 3, // maximum number of times to try to download a resource
	"trace" => false 				// set to true to get verbose reporting from the parser (not recommended)
));

clCache::configure(array(
'nocreate' => false, // set to true and coreylib won't try to create its cache table
'mysql_host' => DB_HOST,
'mysql_username' => DB_USER,
'mysql_password' => DB_PASSWORD,
'mysql_database' => DB_NAME,
'mysql_table_prefix' => 'coreylib_'
));
