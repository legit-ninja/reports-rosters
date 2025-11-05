<?php
/**
 * ExcelExporter Test
 */

namespace InterSoccer\ReportsRosters\Tests\Export;

use InterSoccer\ReportsRosters\Tests\TestCase;
use InterSoccer\ReportsRosters\Export\ExcelExporter;

class ExcelExporterTest extends TestCase {
    private $exporter;
    
    protected function setUp(): void {
        parent::setUp();
        $this->exporter = new ExcelExporter();
    }
    
    public function test_exporter_initialization() {
        $this->assertInstanceOf(ExcelExporter::class, $this->exporter);
    }
    
    public function test_get_file_extension() {
        $this->assertEquals('xlsx', $this->exporter->get_file_extension());
    }
    
    public function test_get_mime_type() {
        $this->assertStringContainsString('spreadsheet', $this->exporter->get_mime_type());
    }
    
    public function test_export_data() {
        $data = [
            ['Name' => 'John Doe', 'Age' => 10],
            ['Name' => 'Jane Smith', 'Age' => 12]
        ];
        
        $result = $this->exporter->export($data, ['filename' => 'test']);
        
        $this->assertIsString($result);
    }
}

