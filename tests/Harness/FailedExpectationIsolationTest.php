<?php

namespace InterSoccer\ReportsRosters\Tests\Harness;

use InterSoccer\ReportsRosters\Tests\TestCase;

class FailedExpectationIsolationTest extends TestCase {
    public function test_unmet_expectation_still_fails_and_the_next_test_is_isolated(): void {
        $root = dirname(__DIR__, 2);
        $dir = sys_get_temp_dir() . '/rr-iso-' . getmypid();
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            $this->fail('Could not create an isolated fixture directory');
        }
        $fixturePhp = $dir . '/FailedExpectationIsolationFixtureTest.php';

        $testCase = $root . '/tests/TestCase.php';
        file_put_contents($fixturePhp, <<<PHP
<?php
namespace InterSoccer\\ReportsRosters\\Tests\\Harness;
use InterSoccer\\ReportsRosters\\Tests\\TestCase;
use Brain\\Monkey\\Functions;
require_once '{$testCase}';

class FailedExpectationIsolationFixtureTest extends TestCase {
    public function test_unmet_expectation_fails(): void {
        Functions\\expect('sanitize_text_field')->once()->andReturn('leaked');
        \$this->assertTrue(true);
    }

    public function test_next_expectation_is_independent(): void {
        Functions\\expect('sanitize_text_field')->once()->andReturn('fresh');
        \$this->assertSame('fresh', sanitize_text_field('x'));
    }
}
PHP
        );

        $plain = $this->runFixture($root, $fixturePhp, false);
        $configured = $this->runFixture($root, $fixturePhp, true);
        @unlink($fixturePhp);
        @rmdir($dir);

        $this->assertIsolatedFailure($plain['exit'], $plain['text']);
        $this->assertStringContainsString('phpunit.xml', $configured['text']);
        $this->assertIsolatedFailure($configured['exit'], $configured['text']);
    }

    private function runFixture(string $root, string $fixturePhp, bool $useSuiteConfiguration): array {
        $command = escapeshellarg(PHP_BINARY)
            . ' ' . escapeshellarg($root . '/vendor/bin/phpunit');
        if ($useSuiteConfiguration) {
            $command .= ' --configuration ' . escapeshellarg($root . '/phpunit.xml');
        } else {
            $command .= ' --no-configuration'
                . ' --bootstrap ' . escapeshellarg($root . '/tests/bootstrap.php');
        }
        $command .= ' ' . escapeshellarg($fixturePhp);

        $output = [];
        $exit = 0;
        exec($command . ' 2>&1', $output, $exit);

        return ['exit' => $exit, 'text' => implode("\n", $output)];
    }

    private function assertIsolatedFailure(int $exit, string $text): void {
        $this->assertNotSame(0, $exit, $text);
        $this->assertStringContainsString('test_unmet_expectation_fails', $text);
        $this->assertStringContainsString('should be called', $text);
        $this->assertStringNotContainsString('test_next_expectation_is_independent', $this->failureSection($text));
    }

    private function failureSection(string $text): string {
        $marker = 'There was ';
        $at = strpos($text, $marker);
        if ($at === false) {
            $marker = 'There were ';
            $at = strpos($text, $marker);
        }
        if ($at === false) {
            return '';
        }

        return substr($text, $at);
    }
}
