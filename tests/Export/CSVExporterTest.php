<?php
/**
 * CSVExporter Test
 */

namespace InterSoccer\ReportsRosters\Tests\Export;

use InterSoccer\ReportsRosters\Tests\TestCase;
use InterSoccer\ReportsRosters\Export\CSVExporter;

class CSVExporterTest extends TestCase {
    private $exporter;
    
    protected function setUp(): void {
        parent::setUp();
        $this->exporter = new CSVExporter();
    }
    
    public function test_exporter_initialization() {
        $this->assertInstanceOf(CSVExporter::class, $this->exporter);
    }
    
    public function test_get_file_extension() {
        $this->assertEquals('csv', $this->exporter->get_file_extension());
    }
    
    public function test_export_to_csv() {
        $data = [
            ['Name' => 'John', 'Age' => '10'],
            ['Name' => 'Jane', 'Age' => '12']
        ];
        
        $result = $this->exporter->export($data);
        
        $this->assertIsString($result);
        $this->assertStringContainsString('John', file_get_contents($result));
    }
}

