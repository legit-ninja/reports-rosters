<?php

namespace InterSoccer\ReportsRosters\Tests\Core;

use InterSoccer\ReportsRosters\Core\Activator;
use InterSoccer\ReportsRosters\Core\Database;
use InterSoccer\ReportsRosters\Core\Logger;
use InterSoccer\ReportsRosters\Tests\TestCase;
use Mockery;

class ActivatorVersionPreserveTest extends TestCase {

    public function test_reactivation_does_not_lower_db_version(): void {
        $logger = Mockery::mock(Logger::class);
        $logger->shouldReceive('debug')->andReturnNull();
        $logger->shouldReceive('info')->andReturnNull();

        $database = Mockery::mock(Database::class);
        $activator = new Activator($database, $logger);

        $defaults = new \ReflectionProperty(Activator::class, 'default_options');
        $defaults->setAccessible(true);
        $option_defaults = $defaults->getValue($activator);
        $GLOBALS['intersoccer_test_option_store'] = [];
        foreach (array_keys($option_defaults) as $key) {
            $GLOBALS['intersoccer_test_option_store'][$key] = $key === 'intersoccer_db_version'
                ? '2.4.0'
                : ($key === 'intersoccer_plugin_version' ? '2.10.0' : '1');
        }

        $method = new \ReflectionMethod(Activator::class, 'set_default_options');
        $method->setAccessible(true);
        $this->assertTrue($method->invoke($activator));

        $this->assertSame('2.4.0', $GLOBALS['intersoccer_test_option_store']['intersoccer_db_version']);
        $this->assertTrue(
            version_compare($GLOBALS['intersoccer_test_option_store']['intersoccer_plugin_version'], '2.10.0', '>='),
            'Plugin version must not downgrade on reactivation'
        );
        unset($GLOBALS['intersoccer_test_option_store']);
    }
}
