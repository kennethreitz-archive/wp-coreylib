<?php 

/*
Plugin Name: Coreylib
Plugin URI: http://github.com/kennethreitz/coreylib-wordpress-plugin
Description: Coreylib for WordPress 
Version: 0.6
Author: Kenneth Reitz
Author URI: http://kennethreitz.com/
Min WP Version: 2.0
Max WP Version: 3.5
License: MIT License - http://www.opensource.org/licenses/mit-license.php

Original coding by Aaron Collegeman.
 
*/

try {
	// Include coreylib, yo.
	require_once(dirname(__FILE__).DIRECTORY_SEPARATOR.'lib'.DIRECTORY_SEPARATOR.'coreylib.php'); 
	// No one wants to hear about that... 
	clAPI::configure('debug', false);	
} catch (Exception $e) {
	// Just in case... :)
}
