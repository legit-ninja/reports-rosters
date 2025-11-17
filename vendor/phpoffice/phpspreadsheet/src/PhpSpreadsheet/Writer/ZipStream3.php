<?php

namespace PhpOffice\PhpSpreadsheet\Writer;

use ZipStream\ZipStream;

class ZipStream3
{
    /**
     * @param resource $fileHandle
     */
    public static function newZipStream($fileHandle): ZipStream
    {
        // Check if ZipStream 3.x supports named parameters (PHP 8.0+)
        // If Archive class exists, we should use ZipStream2 instead
        if (class_exists('ZipStream\Option\Archive')) {
            // Fallback to ZipStream2 if Archive exists (ZipStream 2.x is installed)
            return ZipStream2::newZipStream($fileHandle);
        }
        
        // Try to use ZipStream 3.x with named parameters
        // If this fails, it means ZipStream 3.x doesn't support these parameters
        try {
            return new ZipStream(
                enableZip64: false,
                outputStream: $fileHandle,
                sendHttpHeaders: false,
                defaultEnableZeroHeader: false,
            );
        } catch (\Error $e) {
            // If named parameters fail, try with array-based options (older ZipStream 3.x)
            // This is a fallback for compatibility
            if (strpos($e->getMessage(), 'Unknown named parameter') !== false) {
                // Try alternative constructor for older ZipStream 3.x versions
                return new ZipStream(
                    outputStream: $fileHandle,
                    sendHttpHeaders: false
                );
            }
            throw $e;
        }
    }
}
