<?php

namespace WordPress\ByteStream;

use WordPress\ByteStream\ReadStream\ByteReadStream;
use WordPress\ByteStream\WriteStream\ByteWriteStream;

interface BytePipe extends ByteReadStream, ByteWriteStream {

}
