<?php
namespace WordPress\XML;

/**
 * XML API: XMLElement class
 *
 * @package WordPress
 * @subpackage XML-API
 * @since 6.2.0
 */

/**
 * Core class used by the XML tag processor as a data structure for the attribute token,
 * allowing to drastically improve performance.
 *
 * This class is for internal usage of the XMLProcessor class.
 *
 * @see XMLProcessor
 */
class XMLElement {
	/**
	 * Local name.
	 *
	 * @var string
	 */
	public $local_name;

	/**
	 * Namespace Prefix.
	 *
	 * @var string
	 */
	public $namespace_prefix;

	/**
	 * Full XML namespace name.
	 *
	 * @var string
	 */
	public $namespace;

	/**
	 * Namespaces in current element's scope.
	 *
	 * @var array<string, string>
	 */
	public $namespaces_in_scope;

	/**
	 * Qualified name.
	 *
	 * @var string
	 */
	public $qualified_name;

	/**
	 * Constructor.
	 *
	 * @param string $local_name Local name.
	 * @param string $namespace_prefix Namespace prefix.
	 * @param string $namespace Full XML namespace name.
	 * @param array<string, string> $namespaces_in_scope Namespaces in current element's scope.
	 */
	public function __construct( $local_name, $namespace_prefix, $namespace, $namespaces_in_scope ) {
		$this->local_name = $local_name;
		$this->namespace_prefix = $namespace_prefix;
		$this->namespace = $namespace;
		$this->namespaces_in_scope = $namespaces_in_scope;
		$this->qualified_name = $namespace_prefix ? $namespace_prefix . ':' . $local_name : $local_name;
	}
	
}
