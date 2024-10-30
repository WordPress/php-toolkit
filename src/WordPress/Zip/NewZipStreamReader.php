<?php

namespace WordPress\Zip;

/**
 * Improves on ZipStreamReader – it keeps track of its own parsing state
 * without relying on $fp objects, which enables pausing and resuming.
 * 
 * @TODO: Replace ZipStreamReader with this class once the consumers of
 *        ZipStreamReader have been updated to use the new interface.
 */
class NewZipStreamReader {

	const SIGNATURE_FILE                  = 0x04034b50;
	const SIGNATURE_CENTRAL_DIRECTORY     = 0x02014b50;
	const SIGNATURE_CENTRAL_DIRECTORY_END = 0x06054b50;
	const COMPRESSION_DEFLATE             = 8;

	private $file_path;
	private $zip_file_bytes_parsed_so_far = 0;
	private $file_entry_body_bytes_parsed_so_far = 0;
	private $state = NewZipStreamReader::STATE_SCAN;
	private $header = null;
	private $file_body_chunk = null;
	private $paused_incomplete_input = false;
	private $error_message;

	private $inflate_handle;
	private $fp;

	const STATE_SCAN = 'scan';
	const STATE_FILE_ENTRY = 'file-entry';
	const STATE_CENTRAL_DIRECTORY_ENTRY = 'central-directory-entry';
	const STATE_CENTRAL_DIRECTORY_ENTRY_EXTRA = 'central-directory-entry-extra';
	const STATE_END_CENTRAL_DIRECTORY_ENTRY = 'end-central-directory-entry';
	const STATE_END_CENTRAL_DIRECTORY_ENTRY_EXTRA = 'end-central-directory-entry-extra';
	const STATE_COMPLETE = 'complete';
	const STATE_ERROR = 'error';

	public function pause() {
		return [
			'file_path' => $this->file_path,
			'zip_file_bytes_parsed_so_far' => $this->zip_file_bytes_parsed_so_far,
			'file_entry_body_bytes_parsed_so_far' => $this->file_entry_body_bytes_parsed_so_far,
			'state' => $this->state,
			'header' => $this->header,
			'file_body_chunk' => $this->file_body_chunk,
			'paused_incomplete_input' => $this->paused_incomplete_input,
		];
	}

	public function resume($paused) {
		$this->file_path = $paused['file_path'];
		$this->zip_file_bytes_parsed_so_far = 0;
		$this->state = $paused['state'];
		$this->header = $paused['header'];
		$this->file_body_chunk = $paused['file_body_chunk'];
		$this->paused_incomplete_input = $paused['paused_incomplete_input'];

		$this->fp = fopen($this->file_path, 'rb');
		if($paused['file_entry_body_bytes_parsed_so_far'] > 0) {
			$this->inflate_handle = inflate_init(ZLIB_ENCODING_RAW);
			$file_starts_at = $paused['zip_file_bytes_parsed_so_far'] - $paused['file_entry_body_bytes_parsed_so_far'];
			$this->zip_file_bytes_parsed_so_far = $file_starts_at;
			fseek($this->fp, $file_starts_at);
			while(true) {
				$missing_bytes = $paused['file_entry_body_bytes_parsed_so_far'] - $this->file_entry_body_bytes_parsed_so_far;
				$missing_bytes = max(0, min(4096, $missing_bytes));
				if($missing_bytes === 0) {
					break;
				}
				$this->read_file_entry_body_chunk($missing_bytes);
			}
		} else {
			$this->zip_file_bytes_parsed_so_far = $paused['zip_file_bytes_parsed_so_far'];
			fseek($this->fp, $this->zip_file_bytes_parsed_so_far);
		}
	}

	public function __construct($file_path) {
		$this->file_path = $file_path;
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

	private function read_central_directory_entry()
	{
		if ($this->header && !empty($this->header['path'])) {
			$this->header = null;
			$this->state = self::STATE_SCAN;
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
			$n = $this->header['pathLength'] + $this->header['extraLength'] + $this->header['fileCommentLength'];
			$this->header['path'] = $this->consume_bytes($this->header['pathLength']);
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
			$this->header = null;
			$this->state = self::STATE_SCAN;
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
            $this->header['path'] = $this->consume_bytes($this->header['pathLength']);
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
			$this->header = null;
			$this->inflate_handle = null;
			$this->file_entry_body_bytes_parsed_so_far = 0;
			$this->state = self::STATE_SCAN;
			return;
		}

		$chunk_size = min($max_bytes_to_read, $file_body_bytes_left);
		$compressed_bytes = $this->consume_bytes($chunk_size);
		$this->file_entry_body_bytes_parsed_so_far += strlen($compressed_bytes);

		if ($this->header['compressionMethod'] === self::COMPRESSION_DEFLATE) {
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

	private function consume_bytes($n) {
		if(0 === $n) {
			return '';
		}
		if(null === $this->fp) {
			$this->fp = fopen($this->file_path, 'rb');
		}

		$this->zip_file_bytes_parsed_so_far += $n;
		$bytes_read = fread($this->fp, $n);
		if(false === $bytes_read || '' === $bytes_read) {
            fclose($this->fp);
            $this->state = self::STATE_COMPLETE;
			return false;
		}
		return $bytes_read;
	}

}
