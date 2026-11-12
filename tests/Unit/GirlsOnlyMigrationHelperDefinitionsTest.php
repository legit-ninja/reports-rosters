<?php
/**
 * Girls-only migration helpers must have one definition each (#179).
 *
 * Declaration counts come from a token scan of includes/, so a later
 * function_exists() copy cannot hide a second body.
 */

namespace InterSoccer\ReportsRosters\Tests\Unit;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class GirlsOnlyMigrationHelperDefinitionsTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        require_once dirname(__DIR__, 2) . '/includes/utils.php';
    }

    public function test_each_helper_is_declared_once_in_includes(): void {
        $this->assertSame(
            1,
            $this->declarationCount('intersoccer_migration_activity_type_for_girls_only_tristate')
        );
        $this->assertSame(
            1,
            $this->declarationCount('intersoccer_migration_activity_type_with_girls_only_flag')
        );
    }

    /**
     * @dataProvider tristateProvider
     */
    public function test_tristate_helper(string $activity_type, string $girls_only, string $expected): void {
        $this->assertSame(
            $expected,
            intersoccer_migration_activity_type_for_girls_only_tristate($activity_type, $girls_only)
        );
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public function tristateProvider(): array {
        return [
            'yes adds girls-only label' => ['Camp', 'yes', 'Camp, Girls Only'],
            'course yes adds girls-only label' => ['Course', 'yes', 'Course, Girls Only'],
            'no leaves canonical base' => ['Camp', 'no', 'Camp'],
            'oui is not the yes token' => ['Camp', 'Oui', 'Camp'],
            'ja is not the yes token' => ['Camp', 'Ja', 'Camp'],
            'empty leaves canonical base' => ['Camp', '', 'Camp'],
            'existing suffix is rebuilt' => ["Course, Girls' Only", 'yes', 'Course, Girls Only'],
            'label that is only girls-only falls back to course' => ['Girls Only', 'yes', 'Course, Girls Only'],
            'empty activity with yes stays empty' => ['', 'yes', ''],
        ];
    }

    /**
     * @dataProvider flagProvider
     * @param int|bool $girls_only
     */
    public function test_flag_helper(string $activity_type, $girls_only, string $expected): void {
        $this->assertSame(
            $expected,
            intersoccer_migration_activity_type_with_girls_only_flag($activity_type, $girls_only)
        );
    }

    /**
     * The flag argument is an int. true/false coerce to 1/0. null is not a
     * supported argument; callers cast it, and (int) null is 0.
     *
     * @return array<string, array{0: string, 1: int|bool, 2: string}>
     */
    public function flagProvider(): array {
        return [
            'true adds girls-only label' => ['Camp', true, 'Camp, Girls Only'],
            'flag 1 adds girls-only label' => ['Camp', 1, 'Camp, Girls Only'],
            'course flag 1 adds girls-only label' => ['Course', 1, 'Course, Girls Only'],
            'false leaves canonical base' => ['Camp', false, 'Camp'],
            'flag 0 leaves canonical base' => ['Camp', 0, 'Camp'],
            'null cast to int is not girls-only' => ['Camp', (int) null, 'Camp'],
            'bare girls-only flag stays girls only' => ['Girls Only', 1, 'Girls Only'],
            'bare girls-only with false stays girls only' => ['Girls Only', false, 'Girls Only'],
            'empty activity with true stays empty' => ['', true, ''],
            'empty activity with false stays empty' => ['', false, ''],
            'empty activity with null cast stays empty' => ['', (int) null, ''],
        ];
    }

    private function declarationCount(string $functionName): int {
        $root = dirname(__DIR__, 2) . '/includes';
        $count = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $path = $file->getPathname();
            if (substr($path, -4) !== '.php') {
                continue;
            }
            $source = file_get_contents($path);
            if ($source === false || strpos($source, $functionName) === false) {
                continue;
            }

            $expectName = false;
            foreach (token_get_all($source) as $token) {
                if (!is_array($token)) {
                    if ($expectName && $token !== '&') {
                        $expectName = false;
                    }
                    continue;
                }
                if ($token[0] === T_FUNCTION) {
                    $expectName = true;
                    continue;
                }
                if (!$expectName) {
                    continue;
                }
                if ($token[0] === T_WHITESPACE || $token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }
                if ($token[0] === T_STRING && $token[1] === $functionName) {
                    $count++;
                }
                $expectName = false;
            }
        }

        return $count;
    }
}
