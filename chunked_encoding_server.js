/**
 * Use with `http_api.php` to test chunked transfer encoding:
 *
 * ```php
 * $requests = [
 * 	  new Request( "http://127.0.0.1:3000/", [
 * 	  	'http_version' => '1.1'
 * 	  ] ),
 *   	new Request( "http://127.0.0.1:3000/", [
 * 	  	'http_version' => '1.0',
 *   		'headers'      => [
 * 			  'please-redirect' => 'yes',
 * 		  ],
 * 	  ] ),
 * ];
 */

const http = require('http');
const zlib = require('zlib');

const server = http.createServer((req, res) => {
  // Check if the client is using HTTP/1.1
  const isHttp11 = req.httpVersion === '1.1';
  res.useChunkedEncodingByDefault = false

  // Check if the client accepts gzip encoding
  const acceptEncoding = req.headers['accept-encoding'];
  const useGzip = acceptEncoding && acceptEncoding.includes('gzip');

  if (req.headers['please-redirect']) {
    res.writeHead(301, { Location: req.url });
    res.end();
    return;
  }

  // Set headers for chunked transfer encoding if HTTP/1.1
  if (isHttp11) {
    res.setHeader('Transfer-Encoding', 'chunked');
  }

  res.setHeader('Content-Type', 'text/plain');

  // Create a function to write chunks
  const writeChunks = (stream) => {
    stream.write(`<!DOCTYPE html>
<html lang=en>
<head>
<meta charset='utf-8'>
<title>Chunked transfer encoding test</title>
</head>\r\n`);

    stream.write('<body><h1>Chunked transfer encoding test</h1>\r\n');

    setTimeout(() => {
        stream.write('<h5>This is a chunked response after 100 ms.</h5>\n');

      setTimeout(() => {
        stream.write('<h5>This is a chunked response after 1 second. The server should not close the stream before all chunks are sent to a client.</h5></body></html>\n');
        stream.end();
      }, 1000);
    }, 100);
  };

  if (useGzip) {
    res.setHeader('Content-Encoding', 'gzip');
    const gzip = zlib.createGzip();
    gzip.pipe(res);

    if (isHttp11) {
        writeChunks({
            write(data) {
                gzip.write(data);
                gzip.flush();
            },
            end() {
                gzip.end();
            }
        });
    } else {
      gzip.write('Chunked transfer encoding test\n');
      gzip.write('This is a chunked response after 100 ms.\n');
      gzip.write('This is a chunked response after 1 second.\n');
      gzip.end();
    }
  } else {
    if (isHttp11) {
      writeChunks(res);
    } else {
      res.write('Chunked transfer encoding test\n');
      res.write('This is a chunked response after 100 ms.\n');
      res.write('This is a chunked response after 1 second.\n');
      res.end();
    }
  }
});

const port = 3000;
server.listen(port, () => {
  console.log(`Server is listening on http://127.0.0.1:${port}`);
});
