<?php

namespace WordPress\Filesystem;

use WordPress\AsyncHttp\Client;
use WordPress\AsyncHttp\Request;
use WordPress\ByteReader\WP_String_Reader;

/**
 * Represents a Google Drive filesystem implementation.
 */
class WP_Google_Drive_Filesystem extends WP_Abstract_Filesystem {

    private $root = '/';
    private $write_stream = null;
    private $last_file_reader = null;
    private $access_token;
    private $last_error = null;
    private $path_id_cache = [];
    private $http_client;

    public function __construct($root = '/', $config = []) {
        $this->root = rtrim($root, '/');
        $this->access_token = $config['access_token'] ?? '';
        $this->http_client = $config['http_client'] ?? new Client();
    }

    public function get_root() {
        return $this->root;
    }

    private function make_api_request($endpoint, $method = 'GET', $params = [], $data = null) {
        if(str_starts_with($endpoint, 'https://')) {
            $url = $endpoint;
        } else {
            $url = 'https://www.googleapis.com/drive/v3/' . $endpoint;
        }
        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }
        $request_info = [
            'method' => $method,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->access_token,
                'Accept' => 'application/json',
            ]
        ];
        if($data) {
            if(is_string($data)) {
                $request_info['body_stream'] = WP_String_Reader::create(
                    $data
                );
                if(isset($params['uploadType']) && $params['uploadType'] === 'multipart') {
                    $request_info['headers']['Content-Type'] = 'multipart/form-data; boundary=BOUNDARY_STRING';
                }
            } else {
                $request_info['body_stream'] = WP_String_Reader::create(
                    json_encode($data)
                );
                $request_info['headers']['Content-Type'] = 'application/json';
            }
        }
        $request = new Request($url, $request_info);
        $this->http_client->enqueue($request);

        $buffered_response = '';
        while($this->http_client->await_next_event()) {
            $event = $this->http_client->get_event();
            switch($event) {
                case Client::EVENT_BODY_CHUNK_AVAILABLE:
                    $buffered_response .= $this->http_client->get_response_body_chunk();
                    break;
                case Client::EVENT_FAILED:
                    return false;
            }
        }
        $response_json = json_decode($buffered_response, true);
        if(isset($response_json['error'])) {
            $this->last_error = $response_json['error']['message'];
            return false;
        }
        return $response_json;
    }

    public function ls($parent = '/') {
        $query = "'root' in parents and trashed = false";

        if ($parent !== '/') {
            $parent_id = $this->path_to_id($parent);
            $query = "'$parent_id' in parents and trashed = false";
        }

        $response = $this->make_api_request('files', 'GET', [
            'q' => $query,
            'fields' => 'files(id,name,mimeType)',
            'pageSize' => 1000
        ]);

        if (!$response || !isset($response['files'])) {
            return false;
        }

        $result = [];
        foreach ($response['files'] as $file) {
            $result[] = $file['name'];
        }
        return $result;
    }
    
    private function path_to_id($path) {
        $path = $this->resolve_path($path);

        if (isset($this->path_id_cache[$path])) {
            return $this->path_id_cache[$path];
        }

        if ($path === '/') {
            $this->path_id_cache[$path] = 'root';
            return 'root';
        }

        $path = trim($path, '/');
        $segments = explode('/', $path);
        $parent_id = 'root';
        $current_path = '';

        foreach ($segments as $segment) {
            $current_path .= '/' . $segment;
            
            if (isset($this->path_id_cache[$current_path])) {
                $parent_id = $this->path_id_cache[$current_path];
                continue;
            }

            $query = "'$parent_id' in parents and name = '$segment' and trashed = false";
            $response = $this->make_api_request('files', 'GET', [
                'q' => $query,
                'fields' => 'files(id,mimeType)',
                'pageSize' => 1
            ]);

            if (!$response || empty($response['files'])) {
                $this->path_id_cache[$current_path] = '';
                return '';
            }

            $parent_id = $response['files'][0]['id'];
            $this->path_id_cache[$current_path] = $parent_id;
        }

        return $parent_id;
    }

    public function is_dir($path) {
        $file_id = $this->path_to_id($path);
        $response = $this->make_api_request("files/$file_id", 'GET', [
            'fields' => 'mimeType'
        ]);
        return $response && $response['mimeType'] === 'application/vnd.google-apps.folder';
    }

    public function is_file($path) {
        $file_id = $this->path_to_id($path);
        $response = $this->make_api_request("files/$file_id", 'GET', [
            'fields' => 'mimeType'
        ]);
        return $response && $response['mimeType'] !== 'application/vnd.google-apps.folder';
    }

    public function exists($path) {
        return !!$this->path_to_id($path);
    }

    public function open_read_stream($path) {
        if ($this->last_file_reader) {
            $this->last_file_reader->close();
        }
        
        $file_id = $this->path_to_id($path);
        $response = $this->make_api_request("files/$file_id", 'GET', [
            'alt' => 'media'
        ]);

        if (!$response) {
            return false;
        }

        $this->last_file_reader = \WordPress\ByteReader\WP_String_Reader::create($response);
        return true;
    }

    public function next_file_chunk() {
        return $this->last_file_reader ? $this->last_file_reader->next_bytes() : false;
    }

    public function get_file_chunk() {
        return $this->last_file_reader ? $this->last_file_reader->get_bytes() : false;
    }

    public function get_streamed_file_length() {
        return $this->last_file_reader ? $this->last_file_reader->length() : false;
    }

    public function get_last_error() {
        return $this->last_file_reader ? $this->last_file_reader->get_last_error() : false;
    }

    public function close_read_stream() {
        if ($this->last_file_reader) {
            $this->last_file_reader->close();
            $this->last_file_reader = null;
        }
    }

    public function rename($old_path, $new_path) {
        $file_id = $this->path_to_id($old_path);
        return !!$this->make_api_request("files/$file_id", 'PATCH', [], [
            'name' => basename($new_path)
        ]);
    }

    public function mkdir($path) {
        if($this->path_to_id($path)) {
            return true;
        }
        return !!$this->make_api_request('files', 'POST', [], [
            'name' => basename($path),
            'mimeType' => 'application/vnd.google-apps.folder',
            'parents' => [$this->path_to_id(dirname($path))]
        ]);
    }

    public function rm($path) {
        $file_id = $this->path_to_id($path);
        return !!$this->make_api_request("files/$file_id", 'DELETE');
    }

    public function rmdir($path, $options = []) {
        $recursive = $options['recursive'] ?? false;
        if ($recursive) {
            $path = rtrim($path, '/');
            foreach ($this->ls($path) as $child) {
                $child_path = $path . '/' . $child;
                if ($this->is_dir($child_path)) {
                    $this->rmdir($child_path, $options);
                } else {
                    $this->rm($child_path);
                }
            }
        }
        return !!$this->rm($path);
    }

    public function put_contents($path, $data, $options = []) {
        $file_id = $this->path_to_id($path);
        if($file_id) {
            return $this->make_api_request(
                'https://www.googleapis.com/upload/drive/v3/files/' . $file_id,
                'PATCH',
                ['uploadType' => 'media'],
                $data
            );
        }
        $metadata_json = json_encode([
            'name' => basename($path),
            'parents' => [$this->path_to_id(dirname($path))]
        ]);
        $body = <<<BODY
--BOUNDARY_STRING
Content-Type: application/json; charset=UTF-8

$metadata_json

--BOUNDARY_STRING
Content-Type: text/plain

$data

--BOUNDARY_STRING--
BODY;
        return $this->make_api_request(
            'https://www.googleapis.com/upload/drive/v3/files',
            'POST',
            [
                'uploadType' => 'multipart'
            ],
            $body
        );
    }

    public function open_write_stream($path) {
        if ($this->write_stream) {
            _doing_it_wrong(__METHOD__, 'Cannot open a new write stream while another write stream is open.', '1.0.0');
            return false;
        }
        
        // Initialize upload session
        $this->write_stream = fopen('php://temp', 'w+b');
        return true;
    }

    public function append_bytes($data) {
        if (!$this->write_stream) {
            _doing_it_wrong(__METHOD__, 'Cannot append bytes to a write stream that is not open.', '1.0.0');
            return false;
        }
        return fwrite($this->write_stream, $data);
    }

    public function close_write_stream() {
        if (!$this->write_stream) {
            _doing_it_wrong(__METHOD__, 'Cannot close a write stream that is not open.', '1.0.0');
            return false;
        }

        fclose($this->write_stream);
        $this->write_stream = null;
        return true;
    }

    private function resolve_path($path) {
        return wp_join_paths($this->root, wp_canonicalize_path($path));
    }
}