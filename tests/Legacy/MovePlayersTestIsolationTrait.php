<?php
/**
 * Reset globals that MovePlayers tests may set (full-suite order isolation).
 *
 * @package InterSoccer_Reports_Rosters
 */

namespace InterSoccer\ReportsRosters\Tests\Legacy;

trait MovePlayersTestIsolationTrait {

    protected function resetMovePlayersTestGlobals(): void {
        $GLOBALS['intersoccer_test_term_def_request_cache'] = [];

        global $intersoccer_test_wc_order_item_meta;
        global $intersoccer_test_wc_order_item_data;
        global $intersoccer_test_wc_get_product_callback;
        global $intersoccer_test_product_type_safe_callback;
        global $intersoccer_test_get_product_type_callback;
        global $intersoccer_test_post_status_map;
        global $intersoccer_test_wc_get_order_callback;
        global $intersoccer_test_wc_order_item_meta;
        global $intersoccer_test_order_notes;
        global $intersoccer_test_userdata_map;

        $intersoccer_test_wc_order_item_meta = [];
        $intersoccer_test_wc_order_item_data = [];
        $intersoccer_test_wc_get_product_callback = null;
        $intersoccer_test_product_type_safe_callback = null;
        $intersoccer_test_get_product_type_callback = null;
        $intersoccer_test_post_status_map = [];
        $intersoccer_test_wc_get_order_callback = null;
        $intersoccer_test_order_notes = [];
        $intersoccer_test_userdata_map = [];

        unset(
            $GLOBALS['intersoccer_test_get_userdata'],
            $GLOBALS['intersoccer_test_current_user'],
            $GLOBALS['intersoccer_test_current_user_caps'],
            $GLOBALS['intersoccer_test_live_order_status_map'],
            $GLOBALS['intersoccer_migrated_items'],
            $GLOBALS['intersoccer_rr_product_type_safe_cache'],
            $GLOBALS['intersoccer_test_json_success_throws'],
            $GLOBALS['intersoccer_test_terms_by_name'],
            $GLOBALS['intersoccer_test_terms_by_slug'],
            $GLOBALS['intersoccer_test_terms_by_id'],
            $GLOBALS['intersoccer_test_wpml_term_map'],
            $GLOBALS['intersoccer_test_apply_filters_callback'],
            $GLOBALS['intersoccer_test_roster_store'],
            $GLOBALS['intersoccer_test_roster_row_filter_map']
        );
        $GLOBALS['intersoccer_test_term_def_request_cache'] = [];
        if (function_exists('intersoccer_invalidate_term_def_cache')) {
            intersoccer_invalidate_term_def_cache();
        }
    }
}
