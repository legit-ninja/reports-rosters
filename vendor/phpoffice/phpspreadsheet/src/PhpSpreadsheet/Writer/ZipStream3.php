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
        // Check if ZipStream 2.x is installed (has Archive class)
        // If so, use ZipStream2 instead - this is the preferred path
        if (class_exists('ZipStream\Option\Archive')) {
            return ZipStream2::newZipStream($fileHandle);
        }
        
        // ZipStream 3.x is installed but doesn't support named parameters
        // The best solution is to ensure ZipStream 2.x is installed
        // For now, throw a helpful error message
        throw new \RuntimeException(
            'ZipStream 3.x is installed but is incompatible with PhpSpreadsheet. ' .
            'Please run "composer install --no-dev" to install ZipStream 2.x which is compatible.'
        );
    }
}
