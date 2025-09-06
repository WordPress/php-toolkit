# HttpServer

Minimal TCP HTTP server for local development and testing. Accepts incoming connections, parses requests, and writes responses via streaming writers.

## Problems Solved
- Spin up a small HTTP endpoint from PHP without external deps
- Stream responses (buffered or chunked) from user handlers

## Usage
```php
use WordPress\HttpServer\TcpServer;
use WordPress\HttpServer\Response\TcpResponseWriteStream;

$server = new TcpServer('127.0.0.1', 8080);
$server->set_handler(function($req, TcpResponseWriteStream $res) {
  $res->write_status(200, 'OK');
  $res->write_header('Content-Type', 'text/plain');
  $res->end_headers();
  $res->append_bytes("Hello from PHP HTTP Server\n");
  $res->close_writing();
});

$server->serve(function($host, $port){
  echo "Listening on http://$host:$port\n";
});
```

## Notes
- Blocking I/O, intended for small demos/tests
- See `Response/` classes for buffering vs streaming writers

