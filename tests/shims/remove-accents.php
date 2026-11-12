<?php
/**
 * Offline stand-in for WordPress remove_accents().
 *
 * Partial map only (a few Latin letters), not the WordPress accent table.
 * includes/migration-meta.php calls remove_accents() from
 * intersoccer_migration_normalize_meta_key() when
 * intersoccer_normalize_meta_key_for_lookup() is absent. That helper also
 * calls remove_accents() through intersoccer_normalize_comparison_string().
 * Other plugin files call remove_accents() directly. This stub does not
 * close those gaps.
 *
 * This file is imported after Patchwork.php. A definition inside
 * tests/bootstrap.php is visible to Patchwork as "defined too early", and
 * Brain Monkey Functions\when('remove_accents') then cannot replace it.
 */

if (!function_exists('remove_accents')) {
    function remove_accents($string) {
        $string = (string) $string;
        $map = [
            'à' => 'a', 'á' => 'a', 'â' => 'a', 'ä' => 'a', 'å' => 'a',
            'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
            'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
            'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'ö' => 'o',
            'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u',
            'ç' => 'c', 'ñ' => 'n',
            'À' => 'A', 'É' => 'E', 'È' => 'E',
        ];

        return strtr($string, $map);
    }
}
