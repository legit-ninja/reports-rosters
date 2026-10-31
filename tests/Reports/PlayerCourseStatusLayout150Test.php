<?php
/**
 * Player course status admin layout (#150): filters, table scroll, empty state, CSS scope.
 */

namespace InterSoccer\ReportsRosters\Tests\Reports;

use DOMDocument;
use DOMXPath;
use InterSoccer\ReportsRosters\Tests\TestCase;

class PlayerCourseStatusLayout150Test extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		require_once __DIR__ . '/player-course-status-wc-status-stub.php';
		require_once dirname(__DIR__, 2) . '/includes/player-course-status-admin.php';
	}

	public function test_filter_markup_has_nine_wrapped_label_control_pairs(): void {
		$html = intersoccer_player_course_status_admin_filters_html(
			[
				'year' => 2026,
				'season_type' => '',
				'girls_mode' => 'all',
				'booked' => 'all',
				'booking_status' => '',
				'search' => '',
				'venue' => '',
				'course_day' => '',
				'age_group' => '',
			],
			[
				'venues' => ['zurich' => 'Zurich'],
				'course_days' => ['sunday' => 'Sunday'],
				'age_groups' => ['u8' => 'Under 8'],
				'seasons' => ['Winter'],
			]
		);

		$this->assertSame(9, substr_count($html, 'class="intersoccer-pcs-filter-field"'));

		$dom = new DOMDocument();
		$this->assertTrue(@$dom->loadHTML('<?xml encoding="UTF-8"?><div>' . $html . '</div>'));
		$xpath = new DOMXPath($dom);

		$fields = $xpath->query('//span[contains(concat(" ", normalize-space(@class), " "), " intersoccer-pcs-filter-field ")]');
		$this->assertNotFalse($fields);
		$this->assertSame(9, $fields->length);

		for ($i = 0; $i < $fields->length; $i++) {
			$field = $fields->item($i);
			$labels = $xpath->query('.//label', $field);
			$this->assertSame(1, $labels->length, 'field ' . $i . ' must have one label');
			$controls = $xpath->query('.//select | .//input', $field);
			$this->assertSame(1, $controls->length, 'field ' . $i . ' must have one control');
			/** @var \DOMElement $label */
			$label = $labels->item(0);
			/** @var \DOMElement $control */
			$control = $controls->item(0);
			$this->assertSame($label->getAttribute('for'), $control->getAttribute('id'));
		}

		$this->assertSame(0, $xpath->query('//label[not(ancestor::span[contains(@class,"intersoccer-pcs-filter-field")])]')->length);
		$this->assertSame(0, $xpath->query('//select[not(ancestor::span[contains(@class,"intersoccer-pcs-filter-field")])]')->length);
		$this->assertSame(0, $xpath->query('//input[not(ancestor::span[contains(@class,"intersoccer-pcs-filter-field")])]')->length);
	}

	public function test_results_and_unmatched_tables_are_inside_scroll_wrappers(): void {
		$row = [
			'player' => ['name' => 'Ada', 'tier' => 'uuid'],
			'parent' => ['id' => 1, 'email' => 'ada@example.test'],
			'booked' => false,
			'booking_status' => 'not_booked',
			'bookings_label' => '',
		];
		$results = intersoccer_player_course_status_admin_results_table_html([$row]);
		$this->assertTableInsideScrollWrapper($results);

		$unmatched = intersoccer_player_course_status_admin_unmatched_html([[
			'player_name' => 'Bob',
			'customer_id' => 2,
			'label' => 'Course',
			'order_status' => 'processing',
		]]);
		$this->assertStringContainsString('Unmatched bookings (not filtered)', $unmatched);
		$this->assertStringContainsString('This list ignores the filters above.', $unmatched);
		$this->assertTableInsideScrollWrapper($unmatched);
	}

	public function test_empty_notice_and_unmatched_section_when_no_result_rows(): void {
		$empty = intersoccer_player_course_status_admin_empty_html();
		$this->assertStringContainsString('class="notice notice-info inline intersoccer-pcs-empty"', $empty);
		$this->assertStringContainsString('No players matched the selected filters.', $empty);

		$unmatched = intersoccer_player_course_status_admin_unmatched_html([[
			'player_name' => 'Bob',
			'customer_id' => 2,
			'label' => 'Course',
			'order_status' => 'processing',
		]]);
		$this->assertStringContainsString('Unmatched bookings (not filtered)', $unmatched);

		$this->assertSame('', intersoccer_player_course_status_admin_results_table_html([]));
	}

	public function test_styles_css_keeps_global_widefat_and_scopes_pcs_layout_rules(): void {
		$css = file_get_contents(dirname(__DIR__, 2) . '/css/styles.css');
		$this->assertIsString($css);

		$this->assertMatchesRegularExpression(
			'/^\s*\.widefat\s*\{\s*\n\s*width:\s*100%;\s*\n\s*border-collapse:\s*collapse;\s*\n\s*\}/m',
			$css
		);
		$this->assertMatchesRegularExpression(
			'/^\s*\.widefat th, \.widefat td\s*\{[^}]*padding:\s*var\(--intersoccer-space-sm, 8px\);[^}]*border:\s*1px solid var\(--intersoccer-border-subtle, #ddd\);/ms',
			$css
		);
		$this->assertMatchesRegularExpression(
			'/^\s*\.widefat th\s*\{[^}]*background:\s*var\(--intersoccer-bg-header, #f9f9f9\);/ms',
			$css
		);

		$this->assertStringNotContainsString('.intersoccer-player-course-status .widefat', $css);
		$this->assertStringContainsString('.intersoccer-reports-rosters-campaign-analytics .widefat {', $css);
		$this->assertStringContainsString('.intersoccer-player-course-status .intersoccer-pcs-table-scroll {', $css);
		$this->assertStringContainsString('overflow-x: auto;', $css);
		$this->assertMatchesRegularExpression(
			'/\.intersoccer-player-course-status \.intersoccer-pcs-filters-form \.intersoccer-pcs-filter-field select,\s*\n\.intersoccer-player-course-status \.intersoccer-pcs-filters-form \.intersoccer-pcs-filter-field input\[type="text"\] \{[^}]*max-width:\s*100%;/s',
			$css
		);
	}

	private function assertTableInsideScrollWrapper(string $html): void {
		$dom = new DOMDocument();
		$this->assertTrue(@$dom->loadHTML('<?xml encoding="UTF-8"?><div>' . $html . '</div>'));
		$xpath = new DOMXPath($dom);
		$tables = $xpath->query('//table[contains(concat(" ", normalize-space(@class), " "), " widefat ")]');
		$this->assertNotFalse($tables);
		$this->assertSame(1, $tables->length);
		/** @var \DOMElement $table */
		$table = $tables->item(0);
		$parent = $table->parentNode;
		$this->assertInstanceOf(\DOMElement::class, $parent);
		$this->assertStringContainsString('intersoccer-pcs-table-scroll', $parent->getAttribute('class'));
	}
}
