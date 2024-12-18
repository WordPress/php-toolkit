<?php

use PHPUnit\Framework\TestCase;
use WordPress\Zip\ZipStreamWriter;

class ZipStreamWriterTest extends TestCase {

    private $tempDir = '';
    private $tempSourceFile = '';
    private $tempZipPath = '';

	/**
	 * @before
	 */
	public function before() {
        // Create a temporary directory and file for testing
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'zip_test';
        if (!file_exists($this->tempDir)) {
            mkdir($this->tempDir);
        }
        $this->tempSourceFile = tempnam($this->tempDir, 'testfile');
        file_put_contents($this->tempSourceFile, 'Hello'); // Create a file with some content
    }

	/**
	 * @after
	 */
	public function after() {
        // Cleanup temporary files and directory
        if (file_exists($this->tempSourceFile)) {
            unlink($this->tempSourceFile);
        }
        if (file_exists($this->tempZipPath)) {
            unlink($this->tempZipPath);
        }
        if (is_dir($this->tempDir)) {
            rmdir($this->tempDir);
        }
    }

    /**
     * @dataProvider shouldDeflateProvider
     */
    public function testWriteFileFromPath($should_deflate) {
        $this->tempZipPath = tempnam($this->tempDir, 'testzip');
        $fp = fopen($this->tempZipPath, 'wb');

        $zipWriter = new ZipStreamWriter($fp);
        $sourcePathOnDisk = $this->tempSourceFile;
        $targetPathInZip = 'file';

        // Test the function
        $zipWriter->writeFileFromPath($targetPathInZip, $sourcePathOnDisk, $should_deflate);
        $zipWriter->finish();

        fclose($fp);

        // Check that the ZIP file was created and is not empty
        $this->assertFileExists($this->tempZipPath);
        $this->assertGreaterThan(0, filesize($this->tempZipPath));

        // Open the ZIP file and verify its contents
        $zip = new \ZipArchive();
        $zip->open($this->tempZipPath);
        $this->assertTrue($zip->locateName($targetPathInZip) !== false, "The file was not found in the ZIP");
        $fileContent = $zip->getFromName($targetPathInZip);
        $this->assertEquals(file_get_contents($sourcePathOnDisk), $fileContent, "The file content does not match");
        $zip->close();
    }

    /**
     * @dataProvider shouldDeflateProvider
     */
    public function testWriteFileFromString($should_deflate)
    {
        $this->tempZipPath = tempnam($this->tempDir, 'testzip');
        $fp = fopen($this->tempZipPath, 'wb');

        $zipWriter = new ZipStreamWriter($fp);
        $sourceContent = 'Hello';
        $targetPathInZip = 'file';

        // Test the function
        $zipWriter->writeFileFromString($targetPathInZip, $sourceContent, $should_deflate);
        $zipWriter->finish();

        fclose($fp);

        // Check that the ZIP file was created and is not empty
        $this->assertFileExists($this->tempZipPath);
        $this->assertGreaterThan(0, filesize($this->tempZipPath));

        // Open the ZIP file and verify its contents
        $zip = new \ZipArchive();
        $zip->open($this->tempZipPath);
        $this->assertTrue($zip->locateName($targetPathInZip) !== false, "The file was not found in the ZIP");
        $fileContent = $zip->getFromName($targetPathInZip);
        $this->assertEquals($sourceContent, $fileContent, "The file content does not match");
        $zip->close();
    }

    static public function shouldDeflateProvider() {
        return [
            [true],
            [false],
        ];
    }

}

