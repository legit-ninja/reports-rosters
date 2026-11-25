#!/usr/bin/env php
<?php
/**
 * Verify Advanced tools and Season rename page strings resolve in fr_FR and de_DE.
 *
 * Usage: php scripts/verify-advanced-tools-translations.php
 */

$root = dirname(__DIR__);
$files = [
    $root . '/includes/advanced-tools-admin.php',
    $root . '/includes/season-rename-reconcile-admin.php',
];
$catalogs = [
    'fr_FR' => $root . '/languages/intersoccer-reports-rosters-fr_FR.po',
    'de_DE' => $root . '/languages/intersoccer-reports-rosters-de_DE.po',
];

/**
 * @return array<int,string>
 */
function intersoccer_rr_extract_page_strings(array $files) {
    $pattern = '/(?:__|_e|esc_html__|esc_html_e|esc_attr__|esc_attr_e|_x|_ex)\(\s*(["\'])((?:\\\\.|(?!\1).)*)\1/s';
    $found = [];
    foreach ($files as $file) {
        $src = file_get_contents($file);
        if ($src === false) {
            fwrite(STDERR, "Cannot read $file\n");
            exit(1);
        }
        if (preg_match_all($pattern, $src, $matches)) {
            foreach ($matches[2] as $raw) {
                $quote = $raw; // already inner
                $string = stripcslashes($raw);
                if ($string !== '') {
                    $found[$string] = true;
                }
            }
        }
    }
    return array_keys($found);
}

/**
 * Parse a PO file into msgid => array{msgstr, fuzzy}.
 *
 * @return array{entries: array<string,array{msgstr:string,fuzzy:bool}>, fuzzy_count:int}
 */
function intersoccer_rr_parse_po($path) {
    $lines = file($path, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        fwrite(STDERR, "Cannot read $path\n");
        exit(1);
    }
    $entries = [];
    $fuzzy_count = 0;
    $fuzzy = false;
    $msgid = null;
    $msgstr = null;
    $mode = null;
    $obsolete = false;
    $flush = static function () use (&$entries, &$msgid, &$msgstr, &$fuzzy, &$obsolete, &$mode, &$fuzzy_count) {
        if ($msgid === null || $obsolete) {
            $msgid = null;
            $msgstr = null;
            $fuzzy = false;
            $obsolete = false;
            $mode = null;
            return;
        }
        if ($msgid !== '') {
            $entries[$msgid] = ['msgstr' => (string) $msgstr, 'fuzzy' => $fuzzy];
        }
        if ($fuzzy) {
            $fuzzy_count++;
        }
        $msgid = null;
        $msgstr = null;
        $fuzzy = false;
        $obsolete = false;
        $mode = null;
    };
    foreach ($lines as $line) {
        if (strpos($line, '#~') === 0) {
            $obsolete = true;
            continue;
        }
        if ($line === '' || $line === "\r") {
            $flush();
            continue;
        }
        if (strpos($line, '#,') === 0 && strpos($line, 'fuzzy') !== false) {
            $fuzzy = true;
            continue;
        }
        if (strpos($line, 'msgid ') === 0) {
            if ($msgid !== null && $mode === 'msgstr') {
                $flush();
            }
            $msgid = intersoccer_rr_po_unquote(substr($line, 6));
            $mode = 'msgid';
            continue;
        }
        if (strpos($line, 'msgstr ') === 0) {
            $msgstr = intersoccer_rr_po_unquote(substr($line, 7));
            $mode = 'msgstr';
            continue;
        }
        if (isset($line[0]) && $line[0] === '"' && $mode === 'msgid') {
            $msgid .= intersoccer_rr_po_unquote($line);
        } elseif (isset($line[0]) && $line[0] === '"' && $mode === 'msgstr') {
            $msgstr .= intersoccer_rr_po_unquote($line);
        }
    }
    $flush();
    return ['entries' => $entries, 'fuzzy_count' => $fuzzy_count];
}

function intersoccer_rr_po_unquote($line) {
    $line = trim($line);
    if ($line === '' || $line[0] !== '"') {
        return '';
    }
    $line = substr($line, 1);
    if (substr($line, -1) === '"') {
        $line = substr($line, 0, -1);
    }
    return stripcslashes($line);
}

$strings = intersoccer_rr_extract_page_strings($files);
sort($strings);
$failures = 0;
echo "Page strings extracted: " . count($strings) . "\n";
foreach ($catalogs as $locale => $path) {
    $parsed = intersoccer_rr_parse_po($path);
    $missing = [];
    $empty = [];
    foreach ($strings as $string) {
        if (!isset($parsed['entries'][$string])) {
            $missing[] = $string;
            continue;
        }
        if (trim($parsed['entries'][$string]['msgstr']) === '') {
            $empty[] = $string;
        }
    }
    echo sprintf(
        "%s: fuzzy=%d, missing=%d, empty-msgstr=%d\n",
        $locale,
        $parsed['fuzzy_count'],
        count($missing),
        count($empty)
    );
    foreach ($missing as $string) {
        echo "  MISSING msgid: {$string}\n";
        $failures++;
    }
    foreach ($empty as $string) {
        echo "  EMPTY msgstr: {$string}\n";
        $failures++;
    }
}
if ($failures > 0) {
    fwrite(STDERR, "Failed: {$failures} page string(s) do not resolve.\n");
    exit(1);
}
echo "All Advanced tools and Season rename page strings resolve in fr_FR and de_DE.\n";
exit(0);
