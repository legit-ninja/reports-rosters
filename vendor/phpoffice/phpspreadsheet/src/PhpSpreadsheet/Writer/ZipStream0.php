<?php

namespace PhpOffice\PhpSpreadsheet\Writer;

use ZipStream\ZipStream;

class ZipStream0
{
    /**
     * @param resource $fileHandle
     */
    public static function newZipStream($fileHandle): ZipStream
    {
        // Check if ZipStream 2.x is installed (has Archive class)
        // Use fully qualified class name to avoid import errors
        if (class_exists('ZipStream\Option\Archive')) {
            return ZipStream2::newZipStream($fileHandle);
        }
        
        // ZipStream 3.x is installed - use ZipStream3
        // ZipStream3 will handle fallback to ZipStream2 if needed
        return ZipStream3::newZipStream($fileHandle);
    }
}
