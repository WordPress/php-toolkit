<?php
namespace WordPress\XML;

/**
 * XML API: XMLElement class
 *
 * @package WordPress
 * @subpackage XML-API
 * @since 6.2.0
 */

class XMLStackOfOpenElements {

	/**
	 * @var XMLElement[]
	 */
	private $stack = array();

	private $document_namespaces = array(
		'xml'   => 'http://www.w3.org/XML/1998/namespace', // Predefined, cannot be unbound or changed
		'xmlns' => 'http://www.w3.org/2000/xmlns/',        // Reserved for xmlns attributes, not a real namespace for elements/attributes
		''      => '', // Default namespace is initially empty (no namespace)
	);

	public function __construct($document_namespaces=array()) {
		$this->document_namespaces = array_merge(
			$document_namespaces,
			$this->document_namespaces
		);
	}

	/**
	 * Pushes an XMLElement onto the stack.
	 *
	 * @param XMLElement $element
	 * @return void
	 */
	public function push( XMLElement $element ) {
		$this->stack[] = $element;
	}

	/**
	 * Pops the top XMLElement from the stack.
	 *
	 * @return XMLElement|null Returns the popped element, or null if stack is empty.
	 */
	public function pop() {
		if ( empty( $this->stack ) ) {
			return null;
		}
		return array_pop( $this->stack );
	}

	/**
	 * Returns the top XMLElement on the stack without removing it.
	 *
	 * @return XMLElement|null Returns the top element, or null if stack is empty.
	 */
	public function top() {
		if ( empty( $this->stack ) ) {
			return null;
		}
		return $this->stack[ count( $this->stack ) - 1 ];
	}

	/**
	 * Returns the number of elements in the stack.
	 *
	 * @return int
	 */
	public function count() {
		return count( $this->stack );
	}

	public function get_items() {
		return $this->stack;
	}

	/**
	 * Returns the namespaces in scope for the top element.
	 *
	 * @return array<string, string>|null Namespaces in scope, or null if stack is empty.
	 */
	public function get_namespaces_in_scope() {
		$top = $this->top();
		if ( null === $top ) {
			// Namespaces defined by default in every XML document.
			return $this->document_namespaces;
		}
		return $top->namespaces_in_scope;
	}

	public function get_namespace_prefix($namespace) {
		$namespaces_in_scope = $this->get_namespaces_in_scope();
		foreach($namespaces_in_scope as $prefix => $uri) {
			if($uri === $namespace) {
				return $prefix;
			}
		}
		return false;
	}

}
