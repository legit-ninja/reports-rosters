<?php
/**
 * Scoped suppression of order-item event renormalize during roster moves.
 *
 * @package InterSoccer\ReportsRosters
 */

defined('ABSPATH') or die('Restricted access');

if (!class_exists('InterSoccer_Roster_Move_Item_Renormalize_Suppress')) {
    /**
     * Reference-counted per order line item; always cleared via pop() in a finally block.
     */
    final class InterSoccer_Roster_Move_Item_Renormalize_Suppress {
        /** @var array<int,int> */
        private static $depth_by_item = [];

        public static function push(int $item_id): void {
            if ($item_id <= 0) {
                return;
            }
            self::$depth_by_item[$item_id] = (self::$depth_by_item[$item_id] ?? 0) + 1;
        }

        public static function pop(int $item_id): void {
            if ($item_id <= 0 || !isset(self::$depth_by_item[$item_id])) {
                return;
            }
            self::$depth_by_item[$item_id]--;
            if (self::$depth_by_item[$item_id] <= 0) {
                unset(self::$depth_by_item[$item_id]);
            }
        }

        public static function is_active(int $item_id): bool {
            return $item_id > 0 && (self::$depth_by_item[$item_id] ?? 0) > 0;
        }

        /**
         * @internal PHPUnit only
         */
        public static function reset_for_tests(): void {
            self::$depth_by_item = [];
        }
    }
}

if (!function_exists('intersoccer_roster_move_item_renormalize_is_suppressed')) {
    function intersoccer_roster_move_item_renormalize_is_suppressed(int $item_id): bool {
        return InterSoccer_Roster_Move_Item_Renormalize_Suppress::is_active($item_id);
    }
}
