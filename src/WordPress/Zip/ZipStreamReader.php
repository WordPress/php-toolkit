<?php

namespace WordPress\Zip;

use WordPress\ByteReader\WP_Byte_Reader;

/**
 * 
 */
class ZipStreamReader {

	const SIGNATURE_FILE                  = 0x04034b50;
	const SIGNATURE_CENTRAL_DIRECTORY     = 0x02014b50;
	const SIGNATURE_CENTRAL_DIRECTORY_END = 0x06054b50;
	const COMPRESSION_DEFLATE             = 8;

	private $state = ZipStreamReader::STATE_SCAN;
	private $header = null;
	private $file_body_chunk = null;
	private $paused_incomplete_input = false;
	private $error_message;
	private $inflate_handle;
	private $last_record_at = null;
	private $byte_reader;
	private $byte_buffer = '';
	private $file_bytes_consumed_so_far = 0;
	private $file_entry_body_bytes_parsed_so_far = 0;

	const STATE_SCAN = 'scan';
	const STATE_FILE_ENTRY = 'file-entry';
	const STATE_CENTRAL_DIRECTORY_ENTRY = 'central-directory-entry';
	const STATE_CENTRAL_DIRECTORY_ENTRY_EXTRA = 'central-directory-entry-extra';
	const STATE_END_CENTRAL_DIRECTORY_ENTRY = 'end-central-directory-entry';
	const STATE_END_CENTRAL_DIRECTORY_ENTRY_EXTRA = 'end-central-directory-entry-extra';
	const STATE_COMPLETE = 'complete';
	const STATE_ERROR = 'error';

	public function __construct(WP_Byte_Reader $byte_reader) {
		$this->byte_reader = $byte_reader;
	}

	public function is_paused_at_incomplete_input(): bool {
		return $this->paused_incomplete_input;		
	}

	public function is_finished(): bool
	{
		return self::STATE_COMPLETE === $this->state || self::STATE_ERROR === $this->state;
	}

    public function get_state()
    {
        return $this->state;        
    }

    public function get_header()
    {
        return $this->header;
    }

    public function get_file_path()
    {
        if(!$this->header) {
            return null;
        }

        return $this->header['path'];        
    }

    public function get_file_body_chunk()
    {
        return $this->file_body_chunk;        
    }

	public function count_remaining_file_body_bytes() {
		return $this->header['compressedSize'] - $this->file_entry_body_bytes_parsed_so_far;
	}

    public function get_last_error(): ?string
    {
        return $this->error_message;        
    }

	public function next()
	{
        do {
            if(self::STATE_SCAN === $this->state) {
                if(false === $this->scan()) {
                    return false;
                }
            }

            switch ($this->state) {
                case self::STATE_ERROR:
                case self::STATE_COMPLETE:
                    return false;

                case self::STATE_FILE_ENTRY:
                    if (false === $this->read_file_entry()) {
                        return false;
                    }
                    break;

                case self::STATE_CENTRAL_DIRECTORY_ENTRY:
                    if (false === $this->read_central_directory_entry()) {
                        return false;
                    }
                    break;

                case self::STATE_END_CENTRAL_DIRECTORY_ENTRY:
                    if (false === $this->read_end_central_directory_entry()) {
                        return false;
                    }
                    break;

                default:
                    return false;
            }
        } while (self::STATE_SCAN === $this->state);

		return true;
	}

	public function seek_to_record($record_offset) {
		$this->after_record();
		if( false === $this->byte_reader->seek($record_offset) ) {
			return false;
		}
		$this->byte_buffer = '';
		$this->file_bytes_consumed_so_far = $record_offset;
		return true;
	}

	public function tell() {
		return $this->last_record_at;
	}

	private function after_record() {
		$this->state = self::STATE_SCAN;
		$this->header = null;
		// @TODO: Does the inflate_handle need an fclose() or so call?
		$this->inflate_handle = null;
		$this->file_body_chunk = null;
		$this->file_entry_body_bytes_parsed_so_far = 0;
	}

	private function read_central_directory_entry()
	{
		if ($this->header && ! empty($this->header['path'])) {
			$this->after_record();
			return;
		}

		if (!$this->header) {
			$data = $this->consume_bytes(42);
			if ($data === false) {
				$this->paused_incomplete_input = true;
				return false;
			}
			$this->header = unpack(
				'vversionCreated/vversionNeeded/vgeneralPurpose/vcompressionMethod/vlastModifiedTime/vlastModifiedDate/Vcrc/VcompressedSize/VuncompressedSize/vpathLength/vextraLength/vfileCommentLength/vdiskNumber/vinternalAttributes/VexternalAttributes/VfirstByteAt',
				$data
			);
		}

		if($this->header) {
			$this->header['path'] = $this->sanitize_path($this->consume_bytes($this->header['pathLength']));
			$this->header['extra'] = $this->consume_bytes($this->header['extraLength']);
			$this->header['fileComment'] = $this->consume_bytes($this->header['fileCommentLength']);
			if(!$this->header['path']) {
				$this->set_error('Empty path in central directory entry');
			}
		}
	}

	private function read_end_central_directory_entry()
	{
		if ($this->header && ( !empty($this->header['comment']) || 0 === $this->header['commentLength'] )) {
			$this->after_record();
			return;
		}

		if(!$this->header) {
			$data = $this->consume_bytes(18);
			if ($data === false) {
				$this->paused_incomplete_input = true;
				return false;
			}
			$this->header = unpack(
				'vdiskNumber/vcentralDirectoryStartDisk/vnumberCentralDirectoryRecordsOnThisDisk/vnumberCentralDirectoryRecords/VcentralDirectorySize/VcentralDirectoryOffset/vcommentLength',
				$data
			);
		}

		if($this->header && empty($this->header['comment']) && $this->header['commentLength'] > 0) {
			$comment = $this->consume_bytes($this->header['commentLength']);
			if(false === $comment) {
				$this->paused_incomplete_input = true;
				return false;
			}
			$this->header['comment'] = $comment;
		}		
	}

	private function scan() {
		$this->last_record_at = $this->file_bytes_consumed_so_far;
		$signature = $this->consume_bytes(4);
		if ($signature === false || 0 === strlen($signature)) {
			$this->paused_incomplete_input = true;
			return false;
		}
		$signature = unpack('V', $signature)[1];
		switch($signature) {
			case self::SIGNATURE_FILE:
				$this->state = self::STATE_FILE_ENTRY;
				break;
			case self::SIGNATURE_CENTRAL_DIRECTORY:
				$this->state = self::STATE_CENTRAL_DIRECTORY_ENTRY;
				break;
			case self::SIGNATURE_CENTRAL_DIRECTORY_END:
				$this->state = self::STATE_END_CENTRAL_DIRECTORY_ENTRY;
				break;
			default:
				$this->set_error('Invalid signature ' . $signature);
				return false;
		}
	}

	/**
	 * Reads a file entry from a zip file.
	 *
	 * The file entry is structured as follows:
	 *
	 * ```
	 * Offset    Bytes    Description
	 *   0        4    Local file header signature = 0x04034b50 (PK♥♦ or "PK\3\4")
	 *   4        2    Version needed to extract (minimum)
	 *   6        2    General purpose bit flag
	 *   8        2    Compression method; e.g. none = 0, DEFLATE = 8 (or "\0x08\0x00")
	 *   10        2    File last modification time
	 *   12        2    File last modification date
	 *   14        4    CRC-32 of uncompressed data
	 *   18        4    Compressed size (or 0xffffffff for ZIP64)
	 *   22        4    Uncompressed size (or 0xffffffff for ZIP64)
	 *   26        2    File name length (n)
	 *   28        2    Extra field length (m)
	 *   30        n    File name
	 *   30+n    m    Extra field
	 * ```
	 *
	 * @param resource $stream
	 */
	private function read_file_entry()
	{
		if(false === $this->read_file_entry_header()) {
			return false;
		}
		if(false === $this->read_file_entry_body_chunk()) {
			return false;
		}
	}

	private function read_file_entry_header() {
		if (null === $this->header) {
            $data = $this->consume_bytes(26);
            if ($data === false) {
                $this->paused_incomplete_input = true;
                return false;
            }
            $this->header = unpack(
                'vversionNeeded/vgeneralPurpose/vcompressionMethod/vlastModifiedTime/vlastModifiedDate/Vcrc/VcompressedSize/VuncompressedSize/vpathLength/vextraLength',
                $data
            );
            $this->file_entry_body_bytes_parsed_so_far = 0;
		}

		if($this->header && empty($this->header['path'])) {
            $this->header['path'] = $this->sanitize_path($this->consume_bytes($this->header['pathLength']));
            $this->header['extra'] = $this->consume_bytes($this->header['extraLength']);
            if($this->header['compressionMethod'] === self::COMPRESSION_DEFLATE) {
                $this->inflate_handle = inflate_init(ZLIB_ENCODING_RAW);
            }
		}
	}

	private function read_file_entry_body_chunk($max_bytes_to_read=4096) {
        $this->file_body_chunk = null;

		$file_body_bytes_left = $this->header['compressedSize'] - $this->file_entry_body_bytes_parsed_so_far;
        if($file_body_bytes_left === 0) {
			$this->after_record();
			return;
		}

		$chunk_size = min($max_bytes_to_read, $file_body_bytes_left);
		$compressed_bytes = $this->consume_bytes($chunk_size);
		$this->file_entry_body_bytes_parsed_so_far += strlen($compressed_bytes);

		if ($this->header['compressionMethod'] === self::COMPRESSION_DEFLATE) {
			if(!$this->inflate_handle) {
				$this->inflate_handle = inflate_init(ZLIB_ENCODING_RAW);
			}
			$uncompressed_bytes = inflate_add($this->inflate_handle, $compressed_bytes, ZLIB_PARTIAL_FLUSH);
			if ( $uncompressed_bytes === false || inflate_get_status( $this->inflate_handle ) === false ) {
				$this->set_error('Failed to inflate');
				return false;
			}
		} else {
			$uncompressed_bytes = $compressed_bytes;
		}

		$this->file_body_chunk = $uncompressed_bytes;
	}

	private function set_error($message) {
		$this->state = self::STATE_ERROR;
		$this->error_message = $message;
        $this->paused_incomplete_input = false;
	}

	/**
	 * Normalizes the parsed path to prevent directory traversal,
	 * a.k.a zip slip attacks.
	 *
	 * In ZIP, paths are arbitrary byte sequences. Nothing prevents
	 * a ZIP file from containing a path such as /etc/passwd or
	 * ../../../../etc/passwd.
	 *
	 * This function normalizes paths found in the ZIP file.
	 * 
	 * @TODO: Scrutinize the implementation of this function. Consider
	 *        unicode characters in the path, including ones that are
	 *        just embelishments of the following character. Consider
	 *        the impact of **all** seemingly "invalid" byte sequences,
	 *        e.g. spaces, ASCII control characters, etc. What will the
	 *        OS do when it receives a path containing .{null byte}./etc/passwd?
	 */
	private function sanitize_path($path) {
		// Replace multiple slashes with a single slash.
		$path = preg_replace('#/+#', '/', $path);
		// Remove all the leading ../ segments.
		$path = preg_replace('#^(\.\./)+#', '', $path);
		// Remove all the /./ and /../ segments.
		$path = preg_replace('#/\.\.?/#', '/', $path);
		return $path;
	}

	private function consume_bytes($n) {
		if(0 === $n) {
			return '';
		}

		if (strlen($this->byte_buffer) < $n) {
			if (!$this->byte_reader->next_bytes()) {
				if ($this->byte_reader->is_finished()) {
					$this->state = self::STATE_COMPLETE;
				} else {
					$this->paused_incomplete_input = true;
				}
				return false;
			}
			$this->byte_buffer .= $this->byte_reader->get_bytes();
		}

		$bytes = substr($this->byte_buffer, 0, $n);
		$this->byte_buffer = substr($this->byte_buffer, $n);
		$this->file_bytes_consumed_so_far += $n;
		return $bytes;
	}

}

