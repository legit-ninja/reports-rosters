<?php

namespace InterSoccer\ReportsRosters\Tests\Unit;

use PHPUnit\Framework\TestCase;

class ResolveRosterGirlsOnlyProductAttributeTest extends TestCase {
    private const PARENT_ID = 4200;
    private const VARIATION_ID = 4201;

    protected function setUp(): void {
        parent::setUp();
        if (!defined('ABSPATH')) {
            define('ABSPATH', dirname(__DIR__, 2) . '/');
        }
        require_once dirname(__DIR__, 2) . '/includes/utils.php';
    }

    protected function tearDown(): void {
        unset($GLOBALS['intersoccer_test_wc_get_product_callback']);
        parent::tearDown();
    }

    /**
     * @param array<int, object> $products
     */
    private function stub_wc_products(array $products): void {
        $GLOBALS['intersoccer_test_wc_get_product_callback'] = static function ($product_id) use ($products) {
            return $products[(int) $product_id] ?? null;
        };
    }

    /**
     * @param string $pa_girls_only
     */
    private function make_product_stub($pa_girls_only, int $parent_id = 0): object {
        return new class($pa_girls_only, $parent_id) {
            /** @var string */
            private $pa_girls_only;
            /** @var int */
            private $parent_id;

            public function __construct($pa_girls_only, int $parent_id) {
                $this->pa_girls_only = (string) $pa_girls_only;
                $this->parent_id = $parent_id;
            }

            public function get_attribute($name) {
                if ($name === 'pa_girls-only' || $name === 'girls-only') {
                    return $this->pa_girls_only;
                }
                return '';
            }

            public function get_attributes() {
                return [];
            }

            public function get_parent_id() {
                return $this->parent_id;
            }
        };
    }

    public function test_variation_without_attribute_falls_back_to_parent_yes(): void {
        $this->stub_wc_products([
            self::VARIATION_ID => $this->make_product_stub('', self::PARENT_ID),
            self::PARENT_ID => $this->make_product_stub('yes'),
        ]);
        $flag = intersoccer_resolve_roster_girls_only_flag([
            'product_id' => self::PARENT_ID,
            'variation_id' => self::VARIATION_ID,
        ]);
        $this->assertSame(1, $flag);
    }

    public function test_variation_yes_wins_when_parent_empty(): void {
        $this->stub_wc_products([
            self::VARIATION_ID => $this->make_product_stub('yes', self::PARENT_ID),
            self::PARENT_ID => $this->make_product_stub(''),
        ]);
        $flag = intersoccer_resolve_roster_girls_only_flag([
            'product_id' => self::PARENT_ID,
            'variation_id' => self::VARIATION_ID,
        ]);
        $this->assertSame(1, $flag);
    }

    public function test_variation_non_yes_blocks_parent_yes(): void {
        $this->stub_wc_products([
            self::VARIATION_ID => $this->make_product_stub('no', self::PARENT_ID),
            self::PARENT_ID => $this->make_product_stub('yes'),
        ]);
        $flag = intersoccer_resolve_roster_girls_only_flag([
            'product_id' => self::PARENT_ID,
            'variation_id' => self::VARIATION_ID,
        ]);
        $this->assertSame(0, $flag);
    }

    public function test_neither_variation_nor_parent_has_attribute(): void {
        $this->stub_wc_products([
            self::VARIATION_ID => $this->make_product_stub('', self::PARENT_ID),
            self::PARENT_ID => $this->make_product_stub(''),
        ]);
        $flag = intersoccer_resolve_roster_girls_only_flag([
            'product_id' => self::PARENT_ID,
            'variation_id' => self::VARIATION_ID,
        ]);
        $this->assertSame(0, $flag);
    }
}
