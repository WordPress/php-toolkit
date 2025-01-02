<?php

namespace WordPress\Filesystem;

use WP_REST_Request;
use WP_Error;
use WordPress\ByteReader\WP_File_Reader;
use WordPress\ByteReader\WP_String_Reader;

/**
 * A filesystem implementation that reads from an uploaded directory tree structure.
 * This is useful for handling file uploads through the REST API where files are
 * sent as part of a directory tree structure.
 */
class WP_Uploaded_Directory_Tree_Filesystem extends WP_Abstract_Filesystem {
    /**
     * @var array The directory tree structure
     */
    private $tree;

    /**
     * @var WP_REST_Request The request object containing uploaded files
     */
    private $request;

    /**
     * @var WP_File_Reader Currently opened file for streaming
     */
    private $file_reader;

    public static function create($request, $tree_parameter_name) {
        $tree_json = $request->get_param($tree_parameter_name);
        if (!$tree_json) {
            return new WP_Error('invalid_tree', 'Invalid file tree structure');
        }

        $tree = json_decode($tree_json, true);
        if (!$tree) {
            return new WP_Error('invalid_json', 'Invalid JSON structure');
        }

        if($tree['type'] !== 'folder' || $tree['name'] !== '') {
            $tree = [
                'type' => 'folder',
                'name' => '',
                'children' => $tree
            ];
        }

        return new self($request, $tree);
    }

    /**
     * @param array $tree The directory tree structure
     * @param WP_REST_Request $request The request object containing uploaded files
     */
    private function __construct($request, $tree) {
        $this->request = $request;
        $this->tree = $tree;
    }

    public function ls($parent = '/') {
        $parent = wp_canonicalize_path($parent);
        $node = $this->find_node($parent);
        if (!$node || $node['type'] !== 'folder') {
            return [];
        }
        return array_map(
            function($child) { return $child['name']; },
            $node['children'] ?? []
        );
    }

    public function is_dir($path) {
        $path = wp_canonicalize_path($path);
        $node = $this->find_node($path);
        return $node && $node['type'] === 'folder';
    }

    public function is_file($path) {
        $path = wp_canonicalize_path($path);
        $node = $this->find_node($path);
        return $node && $node['type'] === 'file';
    }

    public function open_read_stream($path) {
        $path = wp_canonicalize_path($path);
        $node = $this->find_node($path);
        if (!$node || $node['type'] !== 'file') {
            return false;
        }

        // Handle file content from request
        if (!isset($node['content']) || !is_string($node['content'])) {
            $node['content'] = '';
        }

        if(strpos($node['content'], '@file:') === 0) {
            $file_key = substr($node['content'], 6);
            $uploaded_file = $this->request->get_file_params()[$file_key] ?? null;
            
            if (!$uploaded_file || $uploaded_file['error'] !== UPLOAD_ERR_OK) {
                return false;
            }

            $this->file_reader = WP_File_Reader::create($uploaded_file['tmp_name']);
            return true;
        }

        // Handle inline content
        $this->file_reader = WP_String_Reader::create($node['content']);
        return true;
    }

    public function next_file_chunk() {
        if ($this->file_reader === null) {
            return false;
        }

        return $this->file_reader->next_bytes();
    }

    public function get_file_chunk() {
        if ($this->file_reader === null) {
            return false;
        }

        return $this->file_reader->get_bytes();
    }

    public function get_streamed_file_length() {
        if ($this->file_reader === null) {
            return false;
        }

        if ($this->file_reader instanceof WP_File_Reader) {
            return $this->file_reader->length();
        }

        return strlen($this->file_reader);
    }

    public function get_last_error() {
        if ($this->file_reader instanceof WP_File_Reader) {
            return $this->file_reader->get_last_error();
        }
        return false;
    }

    public function close_read_stream() {
        if ($this->file_reader instanceof WP_File_Reader) {
            $this->file_reader->close();
        }
        $this->file_reader = null;
    }

    /**
     * Find a node in the tree by its path
     * 
     * @param string $path The path to find
     * @return array|null The node if found, null otherwise
     */
    private function find_node($path) {
        $path = trim($path, '/');
        if($path === '') {
            return $this->tree;
        }

        $parts = explode('/', $path);
        $current = $this->tree;
        foreach ($parts as $part) {
            $found = false;
            foreach ($current['children'] as $node) {
                if ($node['name'] === $part) {
                    $found = true;
                    $current = $node;
                    break;
                }
            }
            if (!$found) {
                return null;
            }
        }

        return $current;
    }
}
