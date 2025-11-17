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
        
        // ZipStream 3.x is installed but doesn't support named parameters
        // The best solution is to ensure ZipStream 2.x is installed
        // For now, throw a helpful error message directing user to install ZipStream 2.x
        throw new \RuntimeException(
            'ZipStream 3.x is installed but is incompatible with PhpSpreadsheet. ' .
            'Please run "composer install --no-dev" to install ZipStream 2.x which is compatible.'
        );
    }
}
