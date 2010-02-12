<?php
/**
 * coreylib
 * Add universal Web service parsing and view caching to your PHP project.
 * @author Aaron Collegeman aaroncollegeman.com
 * @version 1.1.1
 *
 * Copyright (C)2008-2010 Collegeman.net, LLC.
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

define('MASHUP_SORT_STRING', 'string');
define('MASHUP_SORT_DATE', 'date');

@include_once('coreylib-cfg.php');
	
class clMashup implements ArrayAccess, Iterator {
	
	private $spuds = array();
	
	private $fries = array();
	
	function __construct($items_at = null, $sort_by = null, $urls = array()) {
		foreach($urls as $i => $url) {
			$spud = new clSpud($i, new clAPI($url), $items_at, $sort_by);
			$this->spuds[] = $spud;
		}
	}
	
	function add($source, clAPI $api, $items_at = null, $sort_by = null) {
		$spud = new clSpud($source, $api, $items_at, $sort_by);
		$this->spuds[] = $spud;
		return $spud;
	}
	
	function info() {
		foreach($this->spuds as $s) 
			$s->api()->info();
	}
	
	function count() {
		return count($this->fries);
	}
	
	function parse($cacheFor = 0) {
		$success = true;

		$mh = curl_multi_init();
		
		$spuds_to_parse_now = array();
		$curl_handles = array();
		$queued_spuds = array();
		
		foreach($this->spuds as $i => $spud) {
			$from_parser = $spud->api()->queue($cacheFor);
			if (!is_string($from_parser)) {
				// store reference to spud for later parsing
				$url = curl_getinfo($from_parser, CURLINFO_EFFECTIVE_URL);
				$key = md5($i.$url);
				$queued_spuds[$key] = $spud;
				$curl_handles[$i] = $from_parser;
				
				// queue in multi handle
				curl_multi_add_handle($mh, $from_parser);
			}
			else {
				$spud->api()->parseText($from_parser, true);
				$spuds_to_parse_now[] = $spud;
			}
		}
		
		// go, go gadget, multi-curl!
		$running = count($curl_handles);
		curl_multi_exec($mh, $running); // doesn't block
		
		// before we start waiting for queued curl requests, parse the one's we had cached content for
		foreach($spuds_to_parse_now as $spud) {
			$this->makeFries($spud);
		}
		

		// wait for curl to finish
		if ($running > 0) {
			do {
				curl_multi_exec($mh, $running);
			} while ($running > 0);
		}
		
		foreach($curl_handles as $i => $ch) {
			$key = md5($i.curl_getinfo($ch, CURLINFO_EFFECTIVE_URL));
			$content = curl_multi_getcontent($ch);
			$spud = $queued_spuds[$key];
			$spud->api()->parseText($content, false);
			$this->makeFries($spud);
		}
	}
	
	private function makeFries(clSpud $spud) {
		if ($items_at = $spud->getItemsAt()) {
			foreach($spud->api()->get($items_at) as $node) {
				$this->fries[] = new clFry($spud, $node);
			}
		}
	}
	
	function sort($order = 'descending', $type = MASHUP_SORT_DATE) {
		$arr = array();
	
		foreach($this->fries as $fry) {
			if ($sort_by = $fry->spud->getSortOn()) {
				$key = ''.$fry->get($sort_by);
				if ($type == MASHUP_SORT_DATE)
					$key = date('c', strtotime($key));
				
				while(isset($arr[$key]))
					$key .= 'a';
				$arr[$key] = $fry;
			}
			else {
				$key = 0;
				while (isset($arr[$key]))
					$key .= 'a';
				$arr[$key] = $fry;
			}
		}
		
		if (preg_match('/desc.*/i', $order))
			krsort($arr);
		else
			ksort($arr);
		
		$this->fries = array();
	
		$keys = array_keys($arr);
		for($i=0; $i<count($keys); $i++)
			$this->fries[] = $arr[$keys[$i]];
	}

	private $i = 0;

	private $limit = null;

	function limit($limit) {
		$this->limit = $limit;
		return $this;
	}
	
	function clearLimit() {
		$this->limit = null;
		return $this;
	}

	function current() {
		return $this->fries[$this->i];
	}
	
	function key() {
		return $this->i;
	}
	
	function next() {
		$this->i++;
	}
	
	function rewind() {
		$this->i = 0;
	}
	
	function valid() {
		return ($this->limit == null || ($this->i < $this->limit)) && isset($this->fries[$this->i]);
	}
	
	function offsetExists($offset) {
		return isset($this->fries[$offset]);
	}
	
	function offsetGet($offset) {
		return $this->fries[$offset];
	}
	
	function offsetSet($offset, $value) {
		throw new Exception("Mashups are read-only.");
	}
	
	function offsetUnset($offset) {
		throw new Exception("Mashups are read-only.");
	}
	
} // end clMashup

class clFry {
	
	public $spud;

	public $api;
	
	public $node;
	
	public $source;
	
	function __construct(clSpud $spud, clNode $node) {
		$this->source = $spud->source();
		$this->api = $spud->api();
		$this->spud = $spud;
		$this->node = $node;
	}	
	
	function get($path = null) {
		return $this->node->get($path);
	}
	
	function has($path = null) {
		return $this->node->has($path);
	}
	
	function info($path = null) {
		return $this->node->info($path);
	}
	
}

class clSpud {
	
	private $api;
	
	private $source;
	
	private $sort_by;
	
	private $items_at;
	
	function __construct($source, clAPI $api, $items_at = null, $sort_by = null) {
		$this->source = $source;
		$this->api = $api;
		$this->items_at = $items_at;
		$this->sort_by = $sort_by;
	}
	
	function source() {
		return $this->source;
	}
	
	function api() {
		return $this->api;
	}
	
	function sort_by($sort_by) {
		$this->sort_by = $sort_by;
		return $this;
	}
	
	function items_at($items_at) {
		$this->items_at = $items_at;
		return $this;
	}

	function getItemsAt() {
		return $this->items_at;
	}
	
	function getSortOn() {
		return $this->sort_by;
	}

}
	
/**
 * Universal AWS ECS parser.
 * @since 1.0.8
 */
class clAWSECS extends clAPI {
	
	private $aws_secret_key;
	
	private $aws_access_key_id;
	
	private $aws_service;
	
	private $aws_associate_tag;
	
	/**
	 * @param String associate_tag The Amazon associate tag to associate with this request
	 * @param String access_key_id Your public AWS access key
	 * @param String secret_key Your private AWS secret key
	 */
	function __construct($associate_tag, $access_key_id, $secret_key, $service='AWSECommerceService') {
		$this->aws_associate_tag = $associate_tag;
		$this->aws_access_key_id = $access_key_id;
		$this->aws_secret_key = $secret_key;
		$this->aws_service = $service;
		parent::__construct('http://ecs.amazonaws.com/onca/xml');
	}
	
	function parse($cacheFor = 0) {
		$this->signAWSECS();
		return parent::parse($cacheFor);
	}
	
	/**
	 * Properly sign an AWS ECS request. Based on signature implementation by Blake Schwendiman.
	 * @see http://www.thewhyandthehow.com/signing-aws-requests-in-php/
	 */
	private function signAWSECS() {
		$url_parts = split('://', $this->url);
		$host_and_path = split('/', $url_parts[1]);
		$host = array_shift($host_and_path);
		$path = '/'.join('/', $host_and_path);

		// parameter defaults:
		$params = array_merge(array(
			'Operation' => 'ItemSearch',
			'Service' => $this->aws_service,
			'Version' => '2009-06-01',
			'AWSAccessKeyId' => $this->aws_access_key_id,
			'AssociateTag' => $this->aws_associate_tag
		), $this->params);

		if ($params['Operation'] == 'ItemSearch' && !isset($params['SearchIndex']))
			$params['SearchIndex'] = 'Blended';

		// next, override timestamp:
		$params = array_merge($params, array(
			'Timestamp' => gmdate('Y-m-d\TH:i:s\Z')
		));

		// do a case-insensitive, natural order sort on the array keys.
		ksort($params);

		// create the signable string
		$temp = array();
		foreach ($params as $k => $v) {
			$temp[] = str_replace('%7E', '~', rawurlencode($k)) . '=' . str_replace('%7E', '~', rawurlencode($v));
		}
		$signable = join('&', $temp);

		$stringToSign = strtoupper($this->method)."\n$host\n$path\n$signable";

		$this->debug($stringToSign);
		
		// Hash the AWS secret key and generate a signature for the request.
		$hex_str = hash_hmac('sha256', $stringToSign, $this->aws_secret_key);
		$raw = '';
		for ($i = 0; $i < strlen($hex_str); $i += 2) {
			$raw .= chr(hexdec(substr($hex_str, $i, 2)));
		}

		$params['Signature'] = base64_encode($raw);

		ksort($params);

		$this->params = $params;
	}
	
}
	
/**
 * Universal web service parser.
 * @package coreylib
 * @since 1.0.0
 */
class clAPI {
	
	const METHOD_GET = 'get';
	
	const METHOD_POST = 'post';
	
	protected $params = array();
	
	protected $username;
	
	protected $password;
	
	protected $content;
	
	protected $parserType;
	
	protected $parsed;
	
	protected $url;
	
	protected $sxml;
	
	protected $tree;
	
	protected $method = self::METHOD_GET;
	
	protected $ch;
	
	protected $multi_mode = false;
	
	protected $cacheName;
	
	protected $cacheFor;
	
	protected $curlopts = array();
	
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
	
	/**
	 * @since 1.0.10
	 */
	public final function curlopt($constant, $value) {
		$this->curlopts[$constant] = $value;
		return $this;
	}
	
	private function download($url) {
		self::debug(($this->multi_mode ? 'Queueing' : 'Downloading')." <b>$this->url</b>");
		
		$qs = $this->queryString();
		$url = ($this->method == self::METHOD_GET ? $this->url.($qs ? '?'.$qs : '') : $this->url);
		
		$this->ch = curl_init($url);
		
		curl_setopt($this->ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($this->ch, CURLOPT_USERAGENT, 'coreylib');
	
		// accept all SSL certificates:
		curl_setopt($this->ch, CURLOPT_SSL_VERIFYPEER, false);
		
		if ($this->username && $this->password) 
			curl_setopt($this->ch, CURLOPT_USERPWD, "$this->username:$this->password");
		
		curl_setopt($this->ch, CURLOPT_HTTPHEADER, array('Expect:'));
		
		if ($this->method == self::METHOD_POST) {
			curl_setopt($this->ch, CURLOPT_POST, true);
			curl_setopt($this->ch, CURLOPT_POSTFIELDS, $this->params);
		}
		else
			curl_setopt($this->ch, CURLOPT_HTTPGET, true);
			
		foreach($this->curlopts as $const => $value) {
			if ($const == CURLOPT_RETURNTRANSFER && $value != true) {
				throw new Exception("Can't set CURL option CURLOPT_RETURNTRANSFER to false; that would break coreylib!");
			}
			curl_setopt($this->ch, $const, $value);
		}
		
		// in multi mode, we return the curl handle reference; otherwise, we execute and return content
		return ($this->multi_mode ? $this->ch : curl_exec($this->ch));
	}
	
	/**
	 * @since 1.0.6
	 */
	function post($cacheFor = 0) {
		$this->method = self::METHOD_POST;
		return $this->parse($cacheFor);
	}
	
	function parse($cacheFor = 0) {
		$this->content = false;
		$this->cacheFor = $cacheFor;
		
		$qs = $this->queryString();
		$url = $this->url.($qs ? '?'.$qs : '');
		$this->cacheName = $url.md5($this->username.$this->password);
		
		$contentCameFromCache = false;
		
		if ($this->cacheFor && !clAPI::$options['nocache']) 
			$this->content = clCache::cached($this->cacheName, $this->cacheFor, true);
			
		if ($this->content === false) {
			if (!$this->multi_mode) {
				$try = 0;
				do {
					$try++;
					$this->content = $this->download($url);
				} while (empty($this->content) && $try < clAPI::$options['max_download_tries']);
			}
			else {
				// in multi mode, the curl handle is returned from download, not content
				return $this->download($url);
			}
		}
		else {
			// in multi mode, when content is cached, we return it
			if ($this->multi_mode)
				return $this->content;
			else
				$contentCameFromCache = true;
		}

		return $this->parseText($this->content, $contentCameFromCache);
	}
	
	/**
	 * @since 1.0.9
	 */
	function parseText($content, $contentCameFromCache = false) {
		$this->content = $content;
		
		if (!$this->content) {
			if ($this->ch) {
				$message = curl_error($this->ch);
				self::error("Failed to download $this->url<br /><small>$message</small>"); 
				return false;
			}
			else {
				self::error("Content queued from $this->url was null or empty.");
				return false;
			}
		}
		
		if ($this->parserType == COREYLIB_PARSER_XML) {
			if (!$this->sxml = simplexml_load_string($this->content, null, LIBXML_NOCDATA)) {
				self::error("Failed to parse content.");
				return false;
			}
			
			//$this->sxml['xmlns'] = '';
			
			$default_ns = null;
			if (count(array_keys($namespaces = $this->sxml->getNamespaces(true)))) {
				// capture the default namespace
				$default_ns = isset($namespaces['']) ? $namespaces[''] : null;
			}
			
			if ($default_ns) {
				$this->tree = new clNode($this->sxml, $namespaces, $this->sxml->getName(), $default_ns);
			}
			else {
				$this->tree = new clNode($this->sxml);
			}
		}
		
		if ($this->cacheFor && !$contentCameFromCache && !clAPI::$options['nocache'])
			clCache::saveContent($this->cacheName, $this->content, $this->cacheFor);
		
		return true;
	}
	
	/**
	 * @since 1.0.9
	 */
	function method($method) {
		if ($method != clAPI::METHOD_POST && $method != clAPI::METHOD_GET)
			throw new Exception("Unrecognized HTTP method: $method. Must be one clAPI::METHOD_POST and clAPI::METHOD_GET.");
		return $this;
	}
	
	/**
	 * Sometimes there will be multi uses of coreylib in a single request.  In these cases
	 * it may be helpful to use multi_curl to execute all HTTP requests in parallel. Calling
	 * this method returns one of two values: the content, when content was in the cache, or
	 * a curl handle reference in all other cases.
	 * @return false or a curl handle - see description.
	 * @since 1.0.9
	 */
	function queue($cacheFor = 0) {
		$this->multi_mode = true;
		return $this->parse($cacheFor);
	}
	
	/**
	 * @since 1.0.9
	 */
	function get_ch() {
		return $this->ch;
	}
	
	/**
	 * @deprecated Use clAPI->flushCache instead.
	 */
	function flush() {
		$this->flushCache();
	}
	
	/**
	 * @since 1.0.9
	 */
	function flushCache() {
		$qs = $this->queryString();
		$url = $this->url.($qs ? '?'.$qs : '');
		$cacheName = $url.md5($this->username.$this->password);
		clCache::flush($cacheName);
	}
	
	function get($path = null, $limit = null, $forgive = false) {
		if ($this->tree === null)
			self::error("Can't extract <b>$path</b> until you parse.");
		else
			return $this->tree->get($path, $limit, $forgive);
	}
	
	/**
	 * @since 1.1.0
	 */
	function xpath($xpath, $limit = null) {
		if ($this->tree === null)
			self::error("Can't extra <b>$path</b> until you parse.");
		else
			return $this->tree->xpath($xpath, $limit);
	}
	
	function first($xpath) {
		return $this->xpath($xpath, 1);
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
	public $__default_ns = null;
	public $__default_prefix = null;
	
	private $__position;
	
    function __construct(SimpleXMLElement $node = null, $namespaces = null, $default_prefix = null, $default_ns = null) {
    	if ($node !== null) {
		
			$this->__position = 0;
			$this->__name = $node->getName();
			$this->__value = $node;
			$this->__default_ns = $default_ns;
			$this->__default_prefix = $default_prefix;
			
			if ($namespaces === null)
				$namespaces = $node->getNamespaces(true);
			
			if ($namespaces === null)
				$namespaces = array();
	
	
			foreach($namespaces as $ns => $uri) {
				clAPI::trace("Namespace $ns => $uri");
				
				foreach($node->children(($ns && $uri ? $uri : null)) as $child) {
					$childName = ($ns ? "$ns:".$child->getName() : $child->getName());
				
					clAPI::trace("Child $childName");
					
					if (array_key_exists($childName, $this->__children) && is_array($this->__children[$childName])) {
						$this->__children[$childName][] = new clNode($child, $namespaces, $default_prefix, $default_ns);
					}
					else if (array_key_exists($childName, $this->__children) && get_class($this->__children[$childName]) == "clNode") {
						$childArray = array();
						$childArray[] = $this->__children[$childName];
						$childArray[] = new clNode($child, $namespaces);
						$this->__children[$childName] = $childArray;
					}
					else
						$this->__children[$childName] = new clNode($child, $namespaces, $default_prefix, $default_ns);
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

	/**
	 * @since 1.1.0
	 */
	function xpath($xpath, $limit = null) {
		if ($this->__default_ns && $this->__default_prefix) {
			$this->__value->registerXPathNamespace($this->__default_prefix, $this->__default_ns);
		}
		
		if (($elements = $this->__value->xpath($xpath)) !== false) {
			if ($limit == 1 && count($elements) > 0)
				return new clNode($elements[0]);
			
			$nodes = array();
			foreach($elements as $i => $el) {
				if ($limit != null && $i == $limit)
					break;
				$nodes[] = new clNode($el, null, $this->__default_prefix, $this->__default_ns);
			}
			return $nodes;
		}
		else {
			return new clNode();
		}
	}
	
	/**
	 * @since 1.1.0
	 */
	function first($xpath) {
		return $this->xpath($xpath, 1);
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
					jQuery.noConflict();
					function coreylib_toggle(a) {
						a = jQuery(a);
						a.parent().children('blockquote').toggle();
						if (a.hasClass('open')) {
							a.children('span:first').text('+'); 
							a.removeClass('open');
						}
						else {
							a.children('span:first').text('-');
							a.addClass('open');
						}
					}
				</script>
				<style>
					.coreylib blockquote {
						background-color:#fff; color:black; border:1px solid black; padding: 10px; margin: 0px 0px 10px 0px;
					}
					.coreylib blockquote a {
						font-weight:bold; text-decoration:none;
					}
					.coreylib blockquote blockquote {
						padding: 0;
						border: none;
					}
					.coreylib blockquote blockquote.hide {
						display:none; margin: 0px 0px 0px 15px
					}
					.coreylib blockquote blockquote.show {
						color:black; background-color:#fff; border: 1px solid black; padding:10px; margin:0px 0px 10px 0px;
					}
					.coreylib blockquote blockquote a {
						width:20px;
					}
					.coreylib .att {
						font-weight:bold; color:purple;
					}
				</style>
			<?php
			
			self::$jqueryOut = true;
		}
		
		if ($source) echo '<div class="coreylib"><blockquote><a href="javascript:;" onclick="coreylib_toggle(this);">[<span>+</span>]</a> '.$source;
		
		$node = $this->get($path, $limit);
		if (is_array($node)) {
			foreach($node as $n)
				self::blockquote($n->__name, $n);
		}
		else if (is_object($node))
			self::blockquote($node->__name, $node, ($source));
		else if ($node !== null)
			echo "$path: $node";
			
		if ($source) echo '</blockquote></div>';
		
		return '';
	}
	
	private static function blockquote($name, $node, $hide = true) {
		if (is_object($node)) {
			$attributes = array();
			foreach($node->__attributes as $n => $v) 
				$attributes[] = "<span class=\"att\">$n</span>=&quot;".htmlentities($v)."&quot;";
			
			echo '<blockquote class="'.($hide ? 'hide' : 'show').'">&lt;'.(count($node->__children) ? '<a href="javascript:;" onclick="coreylib_toggle(this);">'.$name.'</a>' : '<b>'.$name.'</b>').(count($attributes) ? ' '.join(' ', $attributes) : '').'&gt;';
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