<?php
/**
 * Patch PhpSpreadsheet ZipStream compatibility
 * 
 * This script patches PhpSpreadsheet's ZipStream3.php to handle
 * compatibility issues with different ZipStream versions.
 * 
 * Run this after composer install/update to ensure compatibility.
 */

$zipStream3File = __DIR__ . '/../vendor/phpoffice/phpspreadsheet/src/PhpSpreadsheet/Writer/ZipStream3.php';
$zipStream0File = __DIR__ . '/../vendor/phpoffice/phpspreadsheet/src/PhpSpreadsheet/Writer/ZipStream0.php';

if (!file_exists($zipStream3File)) {
    echo "ZipStream3.php not found. Skipping patch.\n";
    exit(0);
}

// Patch ZipStream3.php
$zipStream3Content = file_get_contents($zipStream3File);

// Check if already patched
if (strpos($zipStream3Content, '// Check if ZipStream 3.x supports named parameters') !== false) {
    echo "ZipStream3.php already patched. Skipping.\n";
} else {
    // Remove the problematic Archive import and add fallback logic
    $patchedContent = '<?php

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
        // If so, use ZipStream2 instead
        if (class_exists(\'ZipStream\Option\Archive\')) {
            return ZipStream2::newZipStream($fileHandle);
        }
        
        // Try to use ZipStream 3.x with named parameters
        // If this fails, it means ZipStream 3.x doesn\'t support these parameters
        try {
            return new ZipStream(
                enableZip64: false,
                outputStream: $fileHandle,
                sendHttpHeaders: false,
                defaultEnableZeroHeader: false,
            );
        } catch (\Error $e) {
            // If named parameters fail, try with minimal parameters (older ZipStream 3.x)
            if (strpos($e->getMessage(), \'Unknown named parameter\') !== false) {
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
';
    
    file_put_contents($zipStream3File, $patchedContent);
    echo "Patched ZipStream3.php\n";
}

// Patch ZipStream0.php
if (file_exists($zipStream0File)) {
    $zipStream0Content = file_get_contents($zipStream0File);
    
    // Check if already patched
    if (strpos($zipStream0Content, '// Check if ZipStream 2.x is installed') !== false) {
        echo "ZipStream0.php already patched. Skipping.\n";
    } else {
        // Remove the problematic Archive import
        $patchedContent = '<?php

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
        if (class_exists(\'ZipStream\Option\Archive\')) {
            return ZipStream2::newZipStream($fileHandle);
        }
        
        // ZipStream 3.x is installed - use ZipStream3
        // ZipStream3 will handle fallback to ZipStream2 if needed
        return ZipStream3::newZipStream($fileHandle);
    }
}
';
        
        file_put_contents($zipStream0File, $patchedContent);
        echo "Patched ZipStream0.php\n";
    }
}

echo "PhpSpreadsheet ZipStream compatibility patch applied successfully.\n";

