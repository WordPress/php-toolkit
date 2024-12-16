<?php

namespace WordPress\Filesystem;

class WP_Filesystem_Visitor {
	private $filesystem;
	private $root_dir;
	private $directories = array();
	private $files       = array();
	private $current_event;
	private $iterator_stack = array();
	private $current_iterator;
	private $depth = 0;

	public function __construct( WP_Abstract_Filesystem $filesystem, $dir ) {
		$this->filesystem = $filesystem;
		$this->root_dir = $dir;
		$this->iterator_stack[] = $this->create_iterator( $dir );
	}
	
	public function get_current_depth() {
		return $this->depth;
	}

	public function get_root_dir() {
		return $this->root_dir;
	}

	public function next() {
		while ( ! empty( $this->iterator_stack ) ) {
			$this->current_iterator = end( $this->iterator_stack );

			if ( ! $this->current_iterator->valid() ) {
				array_pop( $this->iterator_stack );
				continue;
			}
			$current = $this->current_iterator->current();
			$this->current_iterator->next();

			if ( ! ( $current instanceof WP_File_Visitor_Event ) ) {
				// It's a directory path, push a new iterator onto the stack
				$this->iterator_stack[] = $this->create_iterator( $current );
				continue;
			}

			if ( $current->is_entering() ) {
				++$this->depth;
			}
			$this->current_event = $current;
			if ( $current->is_exiting() ) {
				--$this->depth;
			}
			return true;
		}

		return false;
	}

	public function get_event(): ?WP_File_Visitor_Event {
		return $this->current_event;
	}

	private function create_iterator( $dir ) {
		$this->directories = array();
		$this->files       = array();

		$filesystem = $this->filesystem;
		$children = $filesystem->ls($dir);
		if ( $children === false ) {
			return new \ArrayIterator( array() );
		}

		foreach($children as $child) {
			if ( $filesystem->is_dir( $dir . '/' . $child ) ) {
				$this->directories[] = $child;
				continue;
			}
			$this->files[] = $child;
		}

		$events = array();
		$events[] = new WP_File_Visitor_Event( WP_File_Visitor_Event::EVENT_ENTER, $dir, $this->files );
		$prefix = $dir === '/' ? '' : $dir;
		foreach ( $this->directories as $directory ) {
			$events[] = $prefix . '/' . $directory; // Placeholder for recursion
		}
		$events[] = new WP_File_Visitor_Event( WP_File_Visitor_Event::EVENT_EXIT, $dir );
		return new \ArrayIterator( $events );
	}

}
