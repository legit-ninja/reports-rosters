<?php
/**
 * Reset globals that MovePlayers tests may set (full-suite order isolation).
 *
 * @package InterSoccer_Reports_Rosters
 */

namespace InterSoccer\ReportsRosters\Tests\Legacy;

trait MovePlayersTestIsolationTrait {

    protected function resetMovePlayersTestGlobals(): void {
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
            $GLOBALS['intersoccer_test_json_success_throws']
        );
    }
}
