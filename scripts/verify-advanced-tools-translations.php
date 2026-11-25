#!/usr/bin/env php
<?php
/**
 * Verify Advanced tools and Season rename admin strings stay untranslated
 * in the French and German catalogs. InterSoccer wp-admin is English.
 * Customer-facing translations are left alone.
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
    'fr_CH' => $root . '/languages/intersoccer-reports-rosters-fr_CH.po',
    'de_DE' => $root . '/languages/intersoccer-reports-rosters-de_DE.po',
    'de_CH' => $root . '/languages/intersoccer-reports-rosters-de_CH.po',
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


/**
 * Brand or product tokens that legitimately join a lower-case letter to an
 * upper-case letter. Hyphenated wraps such as "order-sync" are allowed
 * separately in the wrap check.
 */
function intersoccer_rr_camel_allowlist() {
    return [
        'InterSoccer' => true,
        'WooCommerce' => true,
        'WordPress' => true,
        'PHPUnit' => true,
        'JavaScript' => true,
        'MySQL' => true,
        'GitHub' => true,
    ];
}

/**
 * Mask allowlisted camelCase tokens so the join detector can scan the rest.
 */
function intersoccer_rr_mask_camel_allowlist($text) {
    foreach (array_keys(intersoccer_rr_camel_allowlist()) as $token) {
        $text = str_replace($token, str_repeat('X', strlen($token)), $text);
    }
    return $text;
}

/**
 * Scan one catalog for jammed msgstr joins and wrap lines that dropped a space.
 *
 * @return array<int,string>
 */
function intersoccer_rr_msgstr_spacing_issues($path) {
    $lines = file($path, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        fwrite(STDERR, "Cannot read $path\n");
        exit(1);
    }
    $issues = [];
    $msgid = null;
    $msgstr = null;
    $msgstr_lines = [];
    $mode = null;
    $obsolete = false;
    $flush = static function () use (&$issues, &$msgid, &$msgstr, &$msgstr_lines, &$mode, &$obsolete, $path) {
        if ($msgid === null || $obsolete || $msgid === '') {
            $msgid = null;
            $msgstr = null;
            $msgstr_lines = [];
            $mode = null;
            $obsolete = false;
            return;
        }
        $label = strlen($msgid) > 60 ? substr($msgid, 0, 57) . '...' : $msgid;
        $prev = null;
        foreach ($msgstr_lines as $inner) {
            if ($prev !== null && $prev !== '' && $inner !== '') {
                $prev_last = substr($prev, -1);
                $next_first = $inner[0];
                if (!ctype_space($prev_last) && !ctype_space($next_first) && $prev_last !== '-') {
                    $issues[] = sprintf(
                        '%s wrap-join in msgstr for "%s": %s + %s',
                        basename($path),
                        $label,
                        var_export(substr($prev, -24), true),
                        var_export(substr($inner, 0, 24), true)
                    );
                }
            }
            $prev = $inner;
        }
        $masked = intersoccer_rr_mask_camel_allowlist((string) $msgstr);
        if (preg_match_all('/[a-zàâäéèêëïîôùûüçœ][A-ZÀÂÄÉÈÊËÏÎÔÙÛÜÇŒ]/u', $masked, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $match) {
                $at = $match[1];
                $issues[] = sprintf(
                    '%s lowercase+uppercase join in msgstr for "%s": %s',
                    basename($path),
                    $label,
                    var_export(substr((string) $msgstr, max(0, $at - 20), 40), true)
                );
            }
        }
        if (preg_match_all('/[A-Za-zÀ-ÿ]\\.[A-ZÀ-Ÿ]/u', $masked, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $match) {
                $at = $match[1];
                $issues[] = sprintf(
                    '%s word.Word join in msgstr for "%s": %s',
                    basename($path),
                    $label,
                    var_export(substr((string) $msgstr, max(0, $at - 20), 40), true)
                );
            }
        }
        $msgid = null;
        $msgstr = null;
        $msgstr_lines = [];
        $mode = null;
        $obsolete = false;
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
        if (strpos($line, 'msgid ') === 0) {
            if ($msgid !== null && $mode === 'msgstr') {
                $flush();
            }
            $msgid = intersoccer_rr_po_unquote(substr($line, 6));
            $mode = 'msgid';
            $msgstr = null;
            $msgstr_lines = [];
            continue;
        }
        if (strpos($line, 'msgstr ') === 0) {
            $inner = intersoccer_rr_po_unquote(substr($line, 7));
            $msgstr = $inner;
            $msgstr_lines = [$inner];
            $mode = 'msgstr';
            continue;
        }
        if (isset($line[0]) && $line[0] === '"' && $mode === 'msgid') {
            $msgid .= intersoccer_rr_po_unquote($line);
        } elseif (isset($line[0]) && $line[0] === '"' && $mode === 'msgstr') {
            $inner = intersoccer_rr_po_unquote($line);
            $msgstr .= $inner;
            $msgstr_lines[] = $inner;
        }
    }
    $flush();
    return $issues;
}


/**
 * Fragments and function words used to detect lowercase+lowercase joins
 * (for example "unesaison", "ausgewähltist") that wrap and camel checks miss.
 *
 * @return array{forbidden:array<int,string>,glue:array<int,string>,partners:array<int,string>,de_suffix_allow:array<string,true>}
 */
function intersoccer_rr_join_lexicon() {
    return [
        'forbidden' => [
            'unesaison',
            'uneréparation',
            'sontconcernés',
            'saisondans',
            're-signeces',
            'programmerenommées',
            "oud'autres",
            'laréparation',
            'heuresde',
            'lignesinchangées',
            'rénommation',
            'werdenberücksichtigt',
            'ausgewähltist',
            'ausder',
            'aktualisiertdiese',
            'sitzungszeitenoder',
            'programmsaisonsentstanden',
            'zeilenunverändert',
        ],
        'glue' => [
            'une', 'sont', 'la', 'les', 'ces', 'dans', 'ou', 'de',
            'werden', 'ist', 'aus', 'oder', 'diese', 'der', 'den', 'das',
        ],
        'partners' => [
            'saison', 'réparation', 'concernés', 'programme', 'renommées',
            'autres', 'heures', 'lignes', 'inchangées', 'berücksichtigt',
            'ausgewählt', 'aktualisiert', 'sitzungszeiten', 'programmsaisons',
            'entstanden', 'zeilen', 'unverändert', 'liste', 'saison',
        ],
        'suffix_de_allow' => [
            'commande' => true,
            'sauvegarde' => true,
            'période' => true,
            'periode' => true,
            'upgrade' => true,
            'methode' => true,
            'méthode' => true,
        ],
    ];
}

/**
 * @param string $path Catalog path.
 * @return array<int,string>
 */
function intersoccer_rr_msgstr_word_join_issues($path) {
    $parsed = intersoccer_rr_parse_po($path);
    $lex = intersoccer_rr_join_lexicon();
    $issues = [];
    foreach ($parsed['entries'] as $msgid => $entry) {
        $msgstr = (string) $entry['msgstr'];
        if ($msgstr === '') {
            continue;
        }
        $label = strlen($msgid) > 60 ? substr($msgid, 0, 57) . '...' : $msgid;
        $lower = function_exists('mb_strtolower') ? mb_strtolower($msgstr, 'UTF-8') : strtolower($msgstr);
        foreach ($lex['forbidden'] as $bad) {
            if ($bad !== '' && (function_exists('mb_strpos') ? mb_strpos($lower, $bad) !== false : strpos($lower, $bad) !== false)) {
                $issues[] = sprintf(
                    '%s glued-word %s in msgstr for "%s"',
                    basename($path),
                    $bad,
                    $label
                );
            }
        }
        if (preg_match('/\bOuvri(?!r)\b/u', $msgstr)) {
            $issues[] = sprintf('%s typo "Ouvri" in msgstr for "%s"', basename($path), $label);
        }
        if (!preg_match_all("/[A-Za-zÀ-ÿ][A-Za-zÀ-ÿ'’-]*/u", $msgstr, $matches)) {
            continue;
        }
        foreach ($matches[0] as $token) {
            $tl = function_exists('mb_strtolower') ? mb_strtolower($token, 'UTF-8') : strtolower($token);
            $tl = str_replace('’', "'", $tl);
            foreach ($lex['glue'] as $glue) {
                foreach ($lex['partners'] as $partner) {
                    if ($tl === $glue . $partner || $tl === $partner . $glue) {
                        $issues[] = sprintf(
                            '%s glued token "%s" (%s+%s) in msgstr for "%s"',
                            basename($path),
                            $token,
                            $glue,
                            $partner,
                            $label
                        );
                    }
                }
            }
            // "oud'autres"
            if (preg_match("/^ouda['']autres$/u", $tl) || $tl === "oud'autres") {
                $issues[] = sprintf('%s glued token "%s" in msgstr for "%s"', basename($path), $token, $label);
            }
        }
        // Optional hunspell dictionaries (system or INTERSOCCER_RR_DICT_DIR).
        $dict_dir = getenv('INTERSOCCER_RR_DICT_DIR') ?: '';
        unset($dict_dir);
    }
    return $issues;
}


/**
 * @return string Absolute msgfmt path, or empty if not found.
 */
function intersoccer_rr_find_msgfmt() {
    $env = getenv('MSGFMT');
    if (is_string($env) && $env !== '' && is_executable($env)) {
        return $env;
    }
    $paths = [];
    $which = trim((string) shell_exec('command -v msgfmt 2>/dev/null'));
    if ($which !== '') {
        $paths[] = $which;
    }
    $prefix = getenv('GETTEXT_PREFIX');
    if (is_string($prefix) && $prefix !== '') {
        $paths[] = rtrim($prefix, '/') . '/bin/msgfmt';
    }
    foreach ($paths as $path) {
        if (is_executable($path)) {
            return $path;
        }
    }
    return '';
}

$strings = intersoccer_rr_extract_page_strings($files);
sort($strings);
$failures = 0;
echo "Page strings extracted: " . count($strings) . "\n";
foreach ($catalogs as $locale => $path) {
    $parsed = intersoccer_rr_parse_po($path);
    $translated = [];
    $fuzzy_admin = [];
    foreach ($strings as $string) {
        if (!isset($parsed['entries'][$string])) {
            continue;
        }
        $entry = $parsed['entries'][$string];
        if (trim((string) $entry['msgstr']) !== '') {
            $translated[] = $string;
        }
        if (!empty($entry['fuzzy'])) {
            $fuzzy_admin[] = $string;
        }
    }
    echo sprintf(
        "%s: admin-translated=%d, admin-fuzzy=%d, catalog-fuzzy=%d\n",
        $locale,
        count($translated),
        count($fuzzy_admin),
        $parsed['fuzzy_count']
    );
    foreach ($translated as $string) {
        echo "  TRANSLATED admin msgid: {$string}\n";
        $failures++;
    }
    foreach ($fuzzy_admin as $string) {
        echo "  FUZZY admin msgid: {$string}\n";
        $failures++;
    }
}
$spacing_catalogs = [
    $root . '/languages/intersoccer-reports-rosters-fr_FR.po',
    $root . '/languages/intersoccer-reports-rosters-fr_CH.po',
    $root . '/languages/intersoccer-reports-rosters-de_DE.po',
    $root . '/languages/intersoccer-reports-rosters-de_CH.po',
];
$spacing_issues = [];
foreach ($spacing_catalogs as $path) {
    $spacing_issues = array_merge($spacing_issues, intersoccer_rr_msgstr_spacing_issues($path));
}
echo "msgstr spacing issues: " . count($spacing_issues) . "\n";
foreach ($spacing_issues as $issue) {
    echo "  SPACING: {$issue}\n";
    $failures++;
}
$join_issues = [];
foreach ($spacing_catalogs as $path) {
    $join_issues = array_merge($join_issues, intersoccer_rr_msgstr_word_join_issues($path));
}
echo "msgstr glued-word issues: " . count($join_issues) . "\n";
foreach ($join_issues as $issue) {
    echo "  JOIN: {$issue}\n";
    $failures++;
}

$page_set = array_fill_keys($strings, true);
$fuzzy_catalogs = $spacing_catalogs;
$outside_fuzzy = [];
foreach ($fuzzy_catalogs as $path) {
    $parsed = intersoccer_rr_parse_po($path);
    foreach ($parsed['entries'] as $msgid => $entry) {
        if (empty($entry['fuzzy'])) {
            continue;
        }
        if (!isset($page_set[$msgid])) {
            $outside_fuzzy[] = sprintf('%s fuzzy msgid outside Advanced tools / Season rename: %s', basename($path), $msgid);
        }
    }
}
echo "fuzzy entries outside Advanced tools / Season rename pages: " . count($outside_fuzzy) . "\n";
foreach ($outside_fuzzy as $issue) {
    echo "  FUZZY: {$issue}\n";
    $failures++;
}

$msgfmt = intersoccer_rr_find_msgfmt();
if ($msgfmt === '') {
    fwrite(STDERR, "msgfmt not found; cannot run msgfmt -c on catalogs.\n");
    $failures++;
} else {
    $po_files = glob($root . '/languages/*.po');
    sort($po_files);
    echo "msgfmt -c catalogs: " . count($po_files) . " (using {$msgfmt})\n";
    foreach ($po_files as $po) {
        $output = [];
        $code = 0;
        exec(
            escapeshellarg($msgfmt) . ' -c -o /dev/null ' . escapeshellarg($po) . ' 2>&1',
            $output,
            $code
        );
        if ($code !== 0) {
            $failures++;
            echo "  MSGFMT: " . basename($po) . " failed (exit {$code})\n";
            foreach ($output as $line) {
                echo "    {$line}\n";
            }
        }
    }
}

if ($failures > 0) {
    fwrite(STDERR, "Failed: {$failures} translation check(s) did not pass.\n");
    exit(1);
}
echo "No Advanced tools or Season rename admin strings are translated in FR/DE catalogs.\n";
echo "All FR/DE msgstr spacing and glued-word checks passed.\n";
echo "No fuzzy entries exist outside Advanced tools / Season rename pages.\n";
echo "msgfmt -c passed on every catalog.\n";
exit(0);
