<?php
/**
 * coreylib
 * Add universal web service parsing and view caching to your PHP project.
 * @author Aaron Collegeman aaroncollegeman.com
 * @version 1.0.7 (beta): $Id: coreylib.php 139 2008-12-29 00:02:12Z acollegeman $
 *
 * Copyright (C)2008 Collegeman.net, LLC.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA. 
 */

// ----------------------------------------------------------------------------
// Don't touch anything in this file.  That way when there's an update, you
// can just overwrite this file with the new one.  Go ahead, we warned you!
// ----------------------------------------------------------------------------

define('COREYLIB_PARSER_XML', 'xml');
//define('COREYLIB_PARSER_JSON', 'json');
//define('COREYLIB_PARSER_CSV', 'csv');

@include_once('coreylib-cfg.php');
	
/**
 * Universal web service parser.
 * @package coreylib
 * @since 1.0.0
 */
class clAPI {
	
	const METHOD_GET = 'get';
	
	const METHOD_POST = 'post';
	
	private $params = array();
	
	private $username;
	
	private $password;
	
	private $content;
	
	private $parserType;
	
	private $parsed;
	
	private $url;
	
	private $sxml;
	
	private $tree;
	
	private $method = self::METHOD_GET;
	
	public static $options = array(
		"display_errors" => false,
		"debug" => false,
		"nocache" => false,
		"max_download_tries" => 3,
		"trace" => false
	);
	
	function __construct($url, $parserType = COREYLIB_PARSER_XML) {
		if (clAPI::$options['debug'] || clAPI::$options['display_errors']) {
			error_reporting(E_ALL);
			ini_set('display_errors', true);
		}
		
		if (!empty($url)) {
			$this->url = $url;
			
			if (!$parserType) { // attempt autodetection
				if (preg_match('/(xml|rss)$/', $url))
					$parserType = COREYLIB_PARSER_XML;
				else if (preg_match('/(json)$/', $url))
					$parserType = COREYLIB_PARSER_JSON;
				else
					self::error("Please specify a parser type for $url - parameter two of the constructor should be one of COREYLIB_PARSER_XML or COREYLIB_PARSER_JSON.");
			}
			
			$this->parserType = $parserType;
		}
		else
			self::error("Um... you have to tell me what URL you want to parse.");
	}
	
	function __toString() {
		return ($this->content ? $this->content : '');
	}
	
	static function configure($option_name_or_array, $value_or_null = null) {
		if (is_array($option_name_or_array))
			self::$options = array_merge(self::$options, $option_name_or_array);
		else
			self::$options[$option_name_or_array] = $value_or_null;
	}
	
	static function error($msg) {
		if (clAPI::$options['debug'] || clAPI::$options['display_errors']) {
			?>
				<div style="color: black; font-family:sans-serif; background-color:#fcc; padding:10px; margin:5px 0px 5px 0px;">
					<?php echo $msg ?>
				</div>
			<?php
		}
		else {
			
		}
	}
	
	static function warn($msg) {
		if (clAPI::$options['debug']) {
			?>
				<div style="color: black; font-family:sans-serif; background-color:#fc3; padding:10px; margin:5px 0px 5px 0px;">
					<?php echo $msg ?>
				</div>
			<?php
		}
		else {

		}
	}
	
	static function debug($msg) {
		if (clAPI::$options['debug']) {
			?>
				<div style="color: black; font-family:sans-serif; background-color:#ffc; padding:10px; margin:5px 0px 5px 0px;">
					<?php echo $msg ?>
				</div>
			<?php
		}
		else {

		}
	}
	
	static function trace($msg) {
		if (clAPI::$options['trace']) {
			?>
				<div style="color: black; font-family:sans-serif; background-color:#ffc; padding:10px; margin:5px 0px 5px 0px;">
					<?php echo $msg ?>
				</div>
			<?php
		}
		else {

		}
	}
	
	function basicAuth($username, $password) {
		$this->username = $username;
		$this->password = $password;
		return $this;
	}
	
	private function queryString() {
		$qs = array();
		foreach($this->params as $name => $value)
			$qs[] = $name."=".urlencode($value);
		return join('&', $qs);	
	}
	
	private function download($url) {
		self::debug("Using curl to download <b>$this->url</b>");
		
		$qs = $this->queryString();
		$url = ($this->method == self::METHOD_GET ? $this->url.($qs ? '?'.$qs : '') : $this->url);
		
		$ch = curl_init($url);
		
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_USERAGENT, 'coreylib');
		
		if ($this->username && $this->password) 
			curl_setopt($ch, CURLOPT_USERPWD, "$this->username:$this->password");
		
		curl_setopt($ch, CURLOPT_HTTPHEADER, array('Expect:'));
		
		if ($this->method == self::METHOD_POST) {
			curl_setopt($ch, CURLOPT_POST, true);
			curl_setopt($ch, CURLOPT_POSTFIELDS, $this->params);
		}
		else
			curl_setopt($ch, CURLOPT_HTTPGET, true);
		
		return curl_exec($ch);
	}
	
	/**
	 * 
	 * @param $cacheFor
	 * @return unknown_type
	 * @since 1.0.6
	 */
	function post($cacheFor = 0) {
		$this->method = self::METHOD_POST;
		return $this->parse($cacheFor);
	}
	
	function parse($cacheFor = 0) {
		$this->content = false;
		$qs = $this->queryString();
		$url = $this->url.($qs ? '?'.$qs : '');
		$cacheName = $url.md5($this->username.$this->password);
		$contentCameFromCache = false;
		
		if ($cacheFor && !clAPI::$options['nocache']) 
			$this->content = clCache::cached($cacheName, $cacheFor, true);
			
		if ($this->content === false) {
			$try = 0;
			do {
				$try++;
				$this->content = $this->download($url);
			} while (empty($this->content) && $try < clAPI::$options['max_download_tries']);
		}
		else
			$contentCameFromCache = true;

			
		if (!$this->content) {
			self::error("Failed to download $this->url"); 
			return false;
		}
		
		if ($this->parserType == COREYLIB_PARSER_XML) {
			if (!$this->sxml = simplexml_load_string($this->content, null, LIBXML_NOCDATA)) {
				self::error("Failed to parse $this->url");
				return false;
			}
			
			$this->tree = new clNode($this->sxml);
		}
		
		if ($cacheFor && !$contentCameFromCache && !clAPI::$options['nocache'])
			clCache::saveContent($cacheName, $this->content, $cacheFor);
		
		return true;
	}
	
	function flush() {
		$qs = $this->queryString();
		$url = $this->url.($qs ? '?'.$qs : '');
		$cacheName = $url.$this->username.$this->password;
		clCache::flush($cacheName);	
	}
	
	function get($path = null, $limit = null, $forgive = false) {
		if ($this->tree === null)
			self::error("Can't extract <b>$path</b> until you parse.");
		else
			return $this->tree->get($path, $limit, $forgive);
	}
	
	function info($path = null, $limit = null) {
		if ($this->tree === null)
			self::error("Can't get info until you parse.");
		else {
			return $this->tree->info($path, $limit, $this->url);
		}
	}
	
	function has($path = null, $atLeast = 1) {
		if ($this->tree === null)
			self::error("Can't look for <b>$path</b> until you parse.");
		else
			return $this->tree->has($path, $atLeast);	
	}
	
	function param($name_or_array, $value = null) {
		if (is_array($name_or_array))
			$this->params = $name_or_array;
		else
			$this->params[$name_or_array] = $value;

		return $this;
	}
	
	function clearParams() {
		$this->params = array();	
	}
}

/**
 * An intelligent wrapper for SimpleXMLElement objects: forget about namespaces, focus on data.
 * @package coreylib
 * @since 1.0.0
 */ 
class clNode implements Iterator {
	
	private static $jqueryOut = false;
	
	public $__name;
	public $__value;
	public $__children = array();
	public $__attributes = array();
	
	private $__position;
	
    function __construct(SimpleXMLElement $node = null, $namespaces = null) {
    	if ($node !== null) {
		   	$this->__position = 0;
	    	
			$this->__name = $node->getName();
			$this->__value = $node;
			
			if ($namespaces === null)
				$namespaces = $node->getNamespaces(true);
			
			if ($namespaces === null)
				$namespaces = array();
	
			$namespaces[''] = null;
				
			foreach($namespaces as $ns => $uri) {
				
				clAPI::trace("Namespace $ns => $uri");
				
				foreach($node->children(($uri ? $uri : null)) as $child) {
					$childName = ($ns ? "$ns:".$child->getName() : $child->getName());
				
					clAPI::trace("Child $childName");
					
					if (array_key_exists($childName, $this->__children) && is_array($this->__children[$childName])) {
						$this->__children[$childName][] = new clNode($child, $namespaces);
					}
					else if (array_key_exists($childName, $this->__children) && get_class($this->__children[$childName]) == "clNode") {
						$childArray = array();
						$childArray[] = $this->__children[$childName];
						$childArray[] = new clNode($child, $namespaces);
						$this->__children[$childName] = $childArray;
					}
					else
						$this->__children[$childName] = new clNode($child, $namespaces);
				}
				
				foreach($node->attributes(($ns ? $ns : null)) as $a) {
					$a = $a->asXML();
					@list($name, $value) = split('=', $a);
					$this->__attributes[trim($name)] = substr($value, 1, strlen($value)-2);
				}
			}
    	}
	}
	
	function has($path = null, $atLeast = 1) {
		$node = $this->get($path, null, true);
		if (is_array($node)) 
			return (count($node) >= $atLeast);
		else if (is_object($node) && $node->__value != null)
			return (1 >= $atLeast);
		else if (strlen($node) > 0)
			return (1 >= $atLeast);
		else
			return (0 >= $atLeast);	
	}

	function get($path = null, $limit = null, $forgive = false) {
		if ($path)
			clAPI::trace("Searching for <b>&quot;$path&quot;</b>");	
		
		if ($path === null)
			return $this;
			
		$node = $this;
		
		foreach(split('\/', $path) as $childName) {
			$index = $attribute = null;
			
			if (preg_match('/^@(.*)?$/', $childName, $matches)) { // attribute only
				$childName = null;
				$attribute = $matches[1];
				clAPI::trace("Searching for attribute named <b>$attribute</b>");
			}
			else if (preg_match('/(.*)\[(\d+)\](@(.*))?$/', $childName, $matches)) { // array request with/out attribute
				$childName = $matches[1];	
				$index = (int) $matches[2];	
				$attribute = (isset($matches[4])) ? $matches[4] : null;
				clAPI::trace("Searching for element <b>$childName".'['."$index]</b>".($attribute ? ", attribute <b>$attribute</b>" : ''));
			}
			else if (preg_match('/([^@]+)(@(.*))?$/', $childName, $matches)) { // element request with/out attribute
				$childName = $matches[1];	
				$attribute = (isset($matches[3])) ? $matches[3] : null;
				clAPI::trace("Searching for element <b>$childName</b>".($attribute ? ", attribute <b>$attribute</b>" : ''));
			}
			
			if (!$childName && $attribute) {
				if (!isset($node->__attributes[$attribute])) {
					if (!$forgive) clAPI::warn("<b>$node->__name</b> does not have an attribute named <b>$attribute</b>");
					return null;
				}
				
				return (isset($node->__attributes[$attribute]) ? $node->__attributes[$attribute] : null);		
			}
			
			if ($childName && is_array($node)) {
				if (!$forgive) clAPI::error("You are looking for <b>$childName</b> in an array of elements, which isn't possible");
				return new clNode();	
			}
			
			else if (!isset($node->__children[$childName])) {
				if (!$forgive) clAPI::error("$childName is not a child of $node->__name");
				return new clNode();
			}
			
			else {
				$node = $node->__children[$childName];
				
				if ($index !== null) {
					if ($index === 0 && !is_array($node) && is_object($node)) {
						$node = $node; // weird, eh?
					}
					else if (is_array($index) && $index > count($node)-1) {
						if (!$forgive) clAPI::warn("$node->__name did not have an element at index $index");
						return new clNode();	
					}
					else if (!is_array($node)) {
						if (!$forgive) clAPI::error("$node->__name is not an array of elements");
						return new clNode();
					}
					else
						$node = $node[$index];
				}
				
				if ($attribute !== null) {
					
					if (!count($node->__attributes)) {
						if (!$forgive) clAPI::warn("$childName does not have any attributes");
						return null;
					}
					
					if (!isset($node->__attributes[$attribute])) {
						if (!$forgive) clAPI::warn("$node->__name does not have an attribute named $attribute");
						return null;
					}
					
					return isset($node->__attributes[$attribute]) ? $node->__attributes[$attribute] : null;	
				}
			}
		}
		
		if (is_array($node) && is_numeric($limit))
			return array_slice($node, 0, $limit);
		else
			return $node;
	}
	
	
	function text($path = null, $limit = null) {
		$node = $this->get($path, $limit);
		if (is_array($node))
			return '['.join(', ', $node).']';
		else
			return $node;
	}
	
	function info($path = null, $limit = null, $source = null) {
		if (!self::$jqueryOut) {
			?>	
				<script type="text/javascript" src="http://ajax.googleapis.com/ajax/libs/jquery/1.2.6/jquery.min.js"></script>
				<script type="text/javascript">
					function coreylib_toggle(a) {
						$(a).parent().children('blockquote').toggle();
						if ($(a).children('img').attr('src').match(/add.png$/))
							$(a).children('img').attr('src', 'http://coreylib.googlecode.com/svn/trunk/docs/delete.png');
						else if ($(a).children('img').attr('src').match(/delete.png$/))
							$(a).children('img').attr('src', 'http://coreylib.googlecode.com/svn/trunk/docs/add.png');
					}
				</script>
			<?php
			
			self::$jqueryOut = true;
		}
		
		if ($source) echo '<blockquote style="background-color:#fff; color:black; border:1px solid black; padding: 10px; margin: 0px 0px 10px 0px;"><a style="font-weight:bold; text-decoration:none;" href="javascript:;" onclick="coreylib_toggle(this);"><img src="http://coreylib.googlecode.com/svn/trunk/docs/add.png" border="0" /></a> '.$source;
		
		$node = $this->get($path, $limit);
		if (is_array($node)) {
			foreach($node as $n)
				self::blockquote($n->__name, $n);
		}
		else if (is_object($node))
			self::blockquote($node->__name, $node, ($source));
		else if ($node !== null)
			echo "$path: $node";
			
		if ($source) echo '</blockquote>';
		
		return '';
	}
	
	private static function blockquote($name, $node, $hide = true) {
		if (is_object($node)) {
			$attributes = array();
			foreach($node->__attributes as $n => $v) 
				$attributes[] = "<span style=\"font-weight:bold; color:purple;\">$n</span>=&quot;".htmlentities($v)."&quot;";
			
			echo '<blockquote style="'.($hide ? 'display:none; margin: 0px 0px 0px 15px' : 'color:black; background-color:#fff; border: 1px solid black; padding:10px; margin:0px 0px 10px 0px;').'">&lt;'.(count($node->__children) ? '<a style="font-weight:bold; text-decoration:none; width:20px;" href="javascript:;" onclick="coreylib_toggle(this);">'.$name.'</a>' : '<b>'.$name.'</b>').(count($attributes) ? ' '.join(' ', $attributes) : '').'&gt;';
			echo htmlentities(trim($node->__value[0]));
			foreach($node->__children as $childName => $child)
				self::blockquote($childName, $child);
			echo '&lt;/<b>'.$name.'&gt;</b></blockquote>';
		}
		else if (is_array($node)) {
			foreach($node as $instance)
				self::blockquote($name, $instance);	
		}
	}	
		
	function __get($name) {
		$sxml = $this->__value;
		return $sxml->$name;
	}
	
	function __toString() {
		ob_start();
		echo $this->__value;
		$content = ob_get_clean();
		return ($content !== null ? $content : '');
	}
	
	function renderTwitterLink($anchorText = '&raquo;') {
		$text = $this->get('text');
		$text = preg_replace('#http://[^ ]+#i', '<a href="\\0">\\0</a>', $text); 
		$text = preg_replace('/@([a-z0-9_]+)/i', '<a href="http://twitter.com/\\1">\\0</a>', $text);
		return $text.'&nbsp;<a class="gotostatus" href="http://twitter.com/'.$this->get('user/screen_name').'/statuses/'.$this->get('id').'">'.$anchorText.'</a>';
	}
	
	function rewind() {
        $this->__position = 0;
    }

    function current() {
        if ($this->__position == 0)
    		return $this;
    }

    function key() {
        return 0;
    }

    function next() {
        ++$this->__position;
    }

    function valid() {
        return ($this->__position == 0 && $this->__value);
    }
	

}

/**
 * Cache whatever, dude.
 * @package coreylib
 * @version 1.0.0
 */
class clCache {
	
	private static $currentNameQueue = array();
	
	private static $mysqlTableExists = null;
	
	private static $mysqlConnection;
	
	private static $inMem = array();
	
	public static $options = array(
		'nocreate' => false,
		'mysql_host' => '',
		'mysql_database' => '',
		'mysql_username' => '',
		'mysql_password' => '',
		'mysql_table_prefix' => 'coreylib_'
	);
	

	static function configure($option_name_or_array, $value_or_null = null) {
		if (is_array($option_name_or_array))
			self::$options = array_merge(self::$options, $option_name_or_array);
		else
			self::$options[$option_name_or_array] = $value_or_null;
	}
	
	/**
     * Prepare the local filesystem for caching our stuff.
	 * @param $name The unique identifier from which to generate a unique cache file
	 * @returns mixed When initialization fails, returns false; otherwise, returns an array with three parameters: the new file name, the path to the cache folder, and the path to the cache file.
	 */ 
	private static function initFileSystem($name) {
		$fileName = md5($name);
		$cachePath = realpath(dirname(__FILE__)).DIRECTORY_SEPARATOR.'.coreycache';
		
		if (!file_exists($cachePath)) {
			if (@mkdir($cachePath) === false) {
				clAPI::error("Failed to create cache folder $cachePath");
				return false;
			}		
		}
		
		$pathToFile = $cachePath.DIRECTORY_SEPARATOR.$fileName;
		
		return array($fileName, $cachePath, $pathToFile);
	}
	
	/**
     * Retrieves the value of the first column of the first row in the query result.
	 * @return When now rows are returned by the query, null; otherwise, returns the first value.
	 */
	private static function getVar($query) {
		if ($result = @mysql_query($query, self::$mysqlConnection)) {
			$arr = mysql_fetch_array($result);
			return $arr[0];
		}
		else
			return null;
	}
	
	/**
     * @return The coreylib cache table name, with the proper prefix appended.
	 */
 	private static function cacheTableName() {
 		return (isset(self::$options['mysql_table_prefix']) ? self::$options['mysql_table_prefix'] : '')."cache";
 	}
	
 	/**
   	 * Initialize MySQL caching: connect to the database and create the cache table if needed.
     * @return When initialization succeeds, true; otherwise, false.
	 */
	private static function initMySql() {
		if (self::$mysqlTableExists === null) {
			clAPI::trace('Initializing MySQL cache.');
			
			if (!(self::$mysqlConnection = mysql_connect(self::$options['mysql_host'], self::$options['mysql_username'], self::$options['mysql_password']))) {
				clAPI::error('Failed to connect to the MySQL server.');	
				return false;
			}
			
			if (!@mysql_select_db(self::$options['mysql_database'], self::$mysqlConnection)) {
				clAPI::error('Unable to select database `'.self::$options['mysql_database'].'`');
				return false;
			}
				
			if (!self::$options['nocreate']) {
				self::$mysqlTableExists = self::cacheTableName() == self::getVar("SHOW TABLES LIKE '".self::cacheTableName()."'");
				
				if (!self::$mysqlTableExists) {
					clAPI::trace('Creating cache table <b>'.self::cacheTableName().'</b>');
					
					if (!@mysql_query('CREATE TABLE `'.self::cacheTableName().'` (`id` VARCHAR(32) NOT NULL PRIMARY KEY, `cached_on` DATETIME NOT NULL, `content` LONGBLOB)', self::$mysqlConnection)) {
						clAPI::error('Failed to create cache table `'.self::cacheTableName().'`: '.mysql_error(self::$mysqlConnection));
						return false;	
					}
					else
						self::$mysqlTableExists = true;
				}
				else
					return true;
			}
			else {
				self::$mysqlTableExists = true;
				clAPI::warn("You didn't let coreylib check to see if the cache table was there.  Hope you're right.");	
				return true;
			}
		}
		else
			return self::$mysqlTableExists;
	}
	
	/**
     * Parses $cacheFor into a timestamp, representing a date in the past.
	 * @param $cache A value that strtotime() understand
	 * @return a Unix timestamp, or false when unable to parse $cacheFor
	 */
	private static function parseCacheFor($cacheFor) {
		if (!is_numeric($cacheFor)) {
			$original = trim($cacheFor);
			
			$firstChar = substr($cacheFor, 0, 1);
			if ($firstChar == "+")
				$cacheFor = '-'.substr($cacheFor, 1);
			else if ($firstChar != "-") {
				if (stripos($cacheFor, 'last') === false)
					$cacheFor = '-'.$cacheFor;
			}

			if (($cacheFor = strtotime($cacheFor)) === false) {
				clAPI::error("I don't understand $original as an expression of time.");
				return false;
			}
			
			return $cacheFor;
		}
		else {
			$cacheFor = time()-$cacheFor;
			return $cacheFor;
		}
	}	
	
	private static function getFileCacheUnlessIsOldOrDoesNotExist($name, $cacheFor) {
		if (!$cacheFor)
			return false;
			
		if (!($cacheFor = self::parseCacheFor($cacheFor)))
			return false;
			
		$init = self::initFileSystem($name);
		if ($init == false)
			return false;
		else
			list($fileName, $cachePath, $pathToFile) = $init;
			
		if (isset(self::$inMem[$fileName])) {
			clAPI::debug("File cache <b>$fileName</b> found in memory! Now that's fast.");
			return self::$inMem[$fileName];
		}
			
		if (!file_exists($pathToFile)) { // file does not exist
			clAPI::debug("File cache <b>$fileName</b> does not exist.");
			return false;
		}

		if (($fileAge = @filemtime($pathToFile)) === false) { // couldn't read the file last-modified time: have no idea how old the file is!
			clAPI::error("Unable to read file modification time of $pathToFile");
			return false;
		}
			
		$content = ($cacheFor < $fileAge) ? @file_get_contents($pathToFile) : false;
		if ($content === false)
			clAPI::debug("File cache <b>$fileName</b> was too old.");
		else
			self::$inMem[$fileName] = $content;
		
		return $content;
	}
	
	private static function getMysqlCacheUnlessIsOldOrDoesNotExist($name, $cacheFor) {
		if (!$cacheFor)
			return false;

		$md5 = md5($name);
			
		if (isset(self::$inMem[$md5])) {
			clAPI::debug("MySQL cache <b>$md5</b> found in memory! Now that's fast.");
			return self::$inMem[$md5];
		}
			
		if (self::initMySql()) {
			$cacheFor = date('Y/m/d H:i:s', self::parseCacheFor($cacheFor));

			clAPI::debug("Querying MySQL for cached content named <b>$md5</b> cached no earlier than <b>$cacheFor</b>."); 

			$content = self::getVar("SELECT content FROM `".self::cacheTableName()."` WHERE id='$md5' AND '$cacheFor' < cached_on");

			if ($error = mysql_error(self::$mysqlConnection)) {
				clAPI::error('Failed to query the database for cached content. See error log for details');
				error_log('Failed to query the database for cached content: '.$error);
				return false;	
			}
			else if ($content === null) {
				clAPI::debug("No MySQL cached content found for <b>$md5</b>");
				return false;	
			}
			else {
				clAPI::debug("MySQL cached content found for <b>$md5</b>. Yippie!");
				self::$inMem[$md5] = $content;
				return $content;
			}
		}
		else
			return false;
	}
	
	private static function getCacheUnlessIsOldOrDoesNotExist($name, $cacheFor) {
		return (self::isMysqlMode() ? self::getMysqlCacheUnlessIsOldOrDoesNotExist($name, $cacheFor) : self::getFileCacheUnlessIsOldOrDoesNotExist($name, $cacheFor));
	}
	
	/**
     * If cached data exists for $name within $cacheFor, if $return is true, return the cached data, otherwise print it to the output buffer.
	 * @param $name String The unique name underwhich the cached data is expected to be stored
	 * @param $cacheFor String A string-representation of a point in the past, best expressed in terms of minutes, hours, days, weeks, or months, e.g., "1 minute" or "2 days" - anything that strtotime() can understand.
	 * @param $return boolean When true, if cached data is found, that cached data is returned by the function instead of being printed to the output buffer
	 * @return When no data is cached under $name or when cached data is older than $cacheFor, returns false; otherwise, returns data according to the value of $return.
	 */
	static function cached($name, $cacheFor = 0, $return = false) {
		clAPI::trace("Looking for cached content <b>$name</b> no older than <b>$cacheFor</b>.");
		
		if (empty($name)) 
			return false; 
		
		if (($content = self::getCacheUnlessIsOldOrDoesNotExist($name, $cacheFor)) === false) {
			if (!$return) {
				self::$currentNameQueue[] = $name;
				ob_start();
			}
			return false;
		}
		else {
			if ($return)
				return $content;
			else {
				echo $content;
				return true;
			}	
		}
	}
	
	static function flush($name) {
		
		if (self::isMysqlMode()) {
			if (!self::initMySql())
				return false;
			else { 
				$md5 = md5($name);
				if (!@mysql_query("DELETE FROM `".self::cacheTableName()."` WHERE id='$md5'", self::$mysqlConnection) === false && $error = mysql_error(self::$mysqlConnection)) {
					clAPI::error("Failed to flush cache <b>$md5</b>. See server error log for details.");
					error_log("Failed to flush cache [$md5]: $error");
					return false;	
				}
				else
					return true;
			}
		}
		else {
			if (!($init = self::initFileSystem($name)))
				return false;
			else
				list($fileName, $cachePath, $pathToFile) = $init;
				
			if (file_exists($pathToFile) && !@unlink($pathToFile)) {
				clAPI::error("Failed to delete cache file for <b>$name</b>.");
				return false;	
			}
			else
				return true;
		}
	}
	
	public static function saveContent($name, $content, $cacheFor = 0) {
		
		if (self::isMysqlMode()) { // mysql cache
			
			if (!self::initMySql())
				return false;
			else { 
				$md5 = md5($name);
				if (!@mysql_query("REPLACE INTO `".self::cacheTableName()."` (id, cached_on, content) VALUES ('$md5', NOW(), '".mysql_escape_string($content)."')", self::$mysqlConnection) && $error = mysql_error(self::$mysqlConnection)) {
					clAPI::error("Failed to cache <b>$md5</b>. See server error log for details.");
					error_log("Failed to cache [$md5]: $error");
					return false;
				}		
				else
					return true;
			}
			
		}
		
		else { // file system cache
			
			if (!($init = self::initFileSystem($name)))
				return false;
			else
				list($fileName, $cachePath, $pathToFile) = $init;
			
			if (@file_put_contents($pathToFile, $content) === false) {
				clAPI::error("Failed to save cache file $pathToFile.");
				return false;	
			}
			else
				return true;
		}
	
	}
	
	public static function save() {
		$content = ob_get_flush();
		self::saveContent(array_pop(self::$currentNameQueue), $content, 0);
	}

	private static function isMysqlMode() {
		return (
			@strlen(self::$options['mysql_host'])
			&& @strlen(self::$options['mysql_database'])
			&& @strlen(self::$options['mysql_username'])
			&& @strlen(self::$options['mysql_password'])
		);
	}
}

if (!function_exists('cached')) {
	/**
     * If cached data exists for $name within $cacheFor: if $return is true, return the cached data, otherwise print it to the output buffer.
	 * @param $name String The unique name underwhich the cached data is expected to be stored
	 * @param $cacheFor String A string-representation of a point in the past, best expressed in terms of minutes, hours, days, weeks, or months, e.g., "1 minute" or "2 days" - anything that strtotime() can understand.
	 * @param $return boolean When true, if cached data is found, that cached data is returned by the function instead of being printed to the output buffer
	 * @return When no data is cached under $name or when cached data is older than $cacheFor, returns false; otherwise, returns data according to the value of $return.
	 */
	function cached($name, $cacheFor = 0, $return = false) {
		return clCache::cached($name, $cacheFor, $return);
	}
}

if (!function_exists('save')) {
	function save() {
		clCache::save();
	}
}
