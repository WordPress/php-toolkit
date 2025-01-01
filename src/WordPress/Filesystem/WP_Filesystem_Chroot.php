<?php

namespace WordPress\Filesystem;

/**
 * A filesystem wrapper that chroot's the filesystem to a specific path.
 */
class WP_Filesystem_Chroot extends WP_Abstract_Filesystem {

    /**
     * @var WP_Abstract_Filesystem
     */
    private $fs;

    /**
     * @var string
     */
    private $root;

    /**
     * @param WP_Abstract_Filesystem $fs The filesystem to chroot.
     * @param string $root The root path to chroot to.
     */
    public function __construct(WP_Abstract_Filesystem $fs, $root) {
        $this->fs = $fs;
        $this->root = rtrim($root, '/');
    }

    public function exists($path) {
        return $this->fs->exists($this->to_chrooted_path($path));
    }

    public function is_file($path) {
        return $this->fs->is_file($this->to_chrooted_path($path));
    }

    public function is_dir($path) {
        return $this->fs->is_dir($this->to_chrooted_path($path));
    }

    public function mkdir($path, $options = []) {
        return $this->fs->mkdir($this->to_chrooted_path($path), $options);
    }

    public function rmdir($path, $options = []) {
        return $this->fs->rmdir($this->to_chrooted_path($path), $options);
    }

    public function ls($path = '/') {
        return $this->fs->ls($this->to_chrooted_path($path));
    }

    public function open_read_stream($path) {
        return $this->fs->open_read_stream($this->to_chrooted_path($path));
    }

    public function next_file_chunk() {
        return $this->fs->next_file_chunk();
    }

    public function get_file_chunk() {
        return $this->fs->get_file_chunk();
    }

    public function get_streamed_file_length() {
        return $this->fs->get_streamed_file_length();
    }

    public function get_last_error() {
        return $this->fs->get_last_error();
    }

    public function close_read_stream() {
        return $this->fs->close_read_stream();
    }

    public function get_contents($path) {
        return $this->fs->get_contents($this->to_chrooted_path($path));
    }

    public function put_contents($path, $contents, $options = []) {
        return $this->fs->put_contents($this->to_chrooted_path($path), $contents, $options);
    }

    private function to_chrooted_path($path) {
        return wp_join_paths($this->root, $path);
    }

} 