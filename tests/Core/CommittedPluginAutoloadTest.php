<?php
/**
 * Committed Composer autoload must list every plugin class under classes/.
 */

namespace InterSoccer\ReportsRosters\Tests\Core;

use InterSoccer\ReportsRosters\Services\RosterRefundService;
use InterSoccer\ReportsRosters\Tests\TestCase;

class CommittedPluginAutoloadTest extends TestCase {

    public function test_every_inter_soccer_class_under_classes_is_in_committed_classmap(): void {
        $repo_root = dirname(__DIR__, 2);
        $classmap_file = $repo_root . '/vendor/composer/autoload_classmap.php';

        $this->assertFileExists($classmap_file);
        $classmap = require $classmap_file;
        $this->assertIsArray($classmap);

        $expected = $this->discoverInterSoccerDeclaredClasses($repo_root . '/classes');
        $missing = [];
        foreach ($expected as $class) {
            if (!isset($classmap[$class])) {
                $missing[] = $class;
            }
        }

        $this->assertSame([], $missing, 'Committed classmap is missing plugin classes: ' . implode(', ', $missing));
    }

    public function test_roster_refund_service_loads_from_committed_autoloader(): void {
        require dirname(__DIR__, 2) . '/vendor/autoload.php';
        $this->assertTrue(class_exists(RosterRefundService::class));
    }

    /**
     * @return string[]
     */
    private function discoverInterSoccerDeclaredClasses(string $classes_dir): array {
        $found = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($classes_dir, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $content = file_get_contents($file->getPathname());
            if ($content === false || !preg_match('/namespace\s+([^;]+);/', $content, $namespace_match)) {
                continue;
            }
            $namespace = trim($namespace_match[1]);
            if (!str_starts_with($namespace, 'InterSoccer\\ReportsRosters\\')) {
                continue;
            }
            if (!preg_match_all(
                '/^\s*(?:abstract\s+|final\s+)?(?:class|interface|trait)\s+(\w+)\b/m',
                $content,
                $name_matches
            )) {
                continue;
            }
            foreach ($name_matches[1] as $short_name) {
                $found[] = $namespace . '\\' . $short_name;
            }
        }

        $found = array_values(array_unique($found));
        sort($found);
        return $found;
    }
}
