<?php
/**
 * Compiled de_CH / fr_CH roster-move .mo catalogs.
 *
 * @package InterSoccer_Reports_Rosters
 */

namespace InterSoccer\ReportsRosters\Tests\Unit;

use InterSoccer\ReportsRosters\Tests\TestCase;

/**
 * Minimal GNU gettext MO reader for PHPUnit (test-only).
 */
final class RosterMoveMoCatalog {

    /** @var array<string, string> */
    private $entries = [];

    /** @var array<string, string> */
    private $headers = [];

    public static function fromFile(string $path): self {
        $catalog = new self();
        $data = file_get_contents($path);
        if ($data === false || strlen($data) < 20) {
            throw new \RuntimeException('Invalid MO file: ' . $path);
        }

        $magic = substr($data, 0, 4);
        if ($magic === "\xde\x12\x04\x95") {
            $unpack = 'V';
        } elseif ($magic === "\x95\x04\x12\xde") {
            $unpack = 'N';
        } else {
            throw new \RuntimeException('Unsupported MO endian: ' . $path);
        }

        $header = unpack($unpack . 'revision/Vcount/Voffset_orig/Voffset_trans/Vsize_hash/Voffset_hash', substr($data, 4, 24));
        $count = (int) $header['count'];
        $offset_orig = (int) $header['offset_orig'];
        $offset_trans = (int) $header['offset_trans'];

        $orig_table = substr($data, $offset_orig, 8 * $count);
        $trans_table = substr($data, $offset_trans, 8 * $count);

        for ($i = 0; $i < $count; $i++) {
            $orig_meta = unpack($unpack . 'length/Voffset', substr($orig_table, $i * 8, 8));
            $trans_meta = unpack($unpack . 'length/Voffset', substr($trans_table, $i * 8, 8));
            $orig = substr($data, (int) $orig_meta['offset'], (int) $orig_meta['length']);
            $trans = substr($data, (int) $trans_meta['offset'], (int) $trans_meta['length']);
            if ($orig === '') {
                foreach (explode("\n", $trans) as $line) {
                    if (strpos($line, ':') === false) {
                        continue;
                    }
                    [$key, $value] = explode(':', $line, 2);
                    $catalog->headers[trim($key)] = trim($value);
                }
                continue;
            }
            $catalog->entries[$orig] = $trans;
        }

        return $catalog;
    }

    public function translate(string $msgid): ?string {
        if (isset($this->entries[$msgid])) {
            return $this->entries[$msgid];
        }

        return null;
    }

    /**
     * @return string[]|null
     */
    public function translatePlural(string $singular, string $plural, int $n): ?array {
        $key = $singular . "\0" . $plural;
        if (!isset($this->entries[$key])) {
            return null;
        }
        $parts = explode("\0", $this->entries[$key]);
        $index = self::pluralIndex($this->headers['Plural-Forms'] ?? '', $n);

        return $parts;
    }

    public function pluralForm(string $singular, string $plural, int $n): ?string {
        $parts = $this->translatePlural($singular, $plural, $n);
        if ($parts === null) {
            return null;
        }
        $index = self::pluralIndex($this->headers['Plural-Forms'] ?? '', $n);

        return $parts[$index] ?? ($parts[0] ?? null);
    }

    private static function pluralIndex(string $header, int $n): int {
        if (preg_match('/nplurals\s*=\s*(\d+)/i', $header, $m)) {
            $nplurals = (int) $m[1];
        } else {
            $nplurals = 2;
        }
        if (preg_match('/plural\s*=\s*([^;]+)/i', $header, $m)) {
            $expr = trim($m[1]);
            if ($expr === 'n != 1') {
                return $n === 1 ? 0 : 1;
            }
            if ($expr === 'n > 1') {
                return $n > 1 ? 1 : 0;
            }
        }

        return $n === 1 ? 0 : min(1, $nplurals - 1);
    }
}

class RosterMoveTranslationsMoTest extends TestCase {

    private const NBSP = "\u{00A0}";

    public function test_de_ch_mo_roster_move_strings(): void {
        $base = dirname(__DIR__, 2) . '/languages/intersoccer-reports-rosters-de_CH.mo';
        $catalog = RosterMoveMoCatalog::fromFile($base);

        $move_anyway = $catalog->translate('Move anyway');
        $this->assertNotNull($move_anyway);
        $this->assertSame('Trotzdem verschieben', $move_anyway);
        $this->assertNotSame('Move anyway', $move_anyway);

        $success = $catalog->pluralForm('Moved %1$s player to %2$s.', 'Moved %1$s players to %2$s.', 2);
        $this->assertSame('%1$s Teilnehmer nach %2$s verschoben.', $success);

        $target_missing = $catalog->translate('The destination roster no longer exists or was deleted.');
        $this->assertSame(
            'Die neue Gruppe existiert nicht mehr oder wurde gelöscht.',
            $target_missing
        );

        $all_failed = $catalog->translate('No players were moved.');
        $this->assertSame('Es wurden keine Teilnehmer verschoben.', $all_failed);

        $error_generic = $catalog->translate('An error occurred. Please try again.');
        $this->assertSame(
            'Ein Fehler ist aufgetreten. Bitte versuchen Sie es erneut.',
            $error_generic
        );

        $this->assertSame('Anwenden', $catalog->translate('Apply'));
        $this->assertSame(
            'Bitte wählen Sie eine neue Gruppe aus.',
            $catalog->translate('Please select a destination roster.')
        );
        $this->assertSame('Gruppe ohne Titel', $catalog->translate('Untitled roster'));

        $ended = $catalog->translate('This course has ended.');
        $this->assertNull($ended);
    }

    public function test_fr_ch_mo_roster_move_strings_and_nbsp_colons(): void {
        $base = dirname(__DIR__, 2) . '/languages/intersoccer-reports-rosters-fr_CH.mo';
        $catalog = RosterMoveMoCatalog::fromFile($base);

        $move_anyway = $catalog->translate('Move anyway');
        $this->assertSame('Déplacer quand même', $move_anyway);

        $success_plural = $catalog->pluralForm('Moved %1$s player to %2$s.', 'Moved %1$s players to %2$s.', 2);
        $this->assertSame('%1$s participants déplacés vers %2$s.', $success_plural);

        $success_singular = $catalog->pluralForm('Moved %1$s player to %2$s.', 'Moved %1$s players to %2$s.', 1);
        $this->assertSame('%1$s participant déplacé vers %2$s.', $success_singular);

        $full_plural = $catalog->pluralForm(
            'This roster is full: %1$d of %2$d place taken.',
            'This roster is full: %1$d of %2$d places taken.',
            24
        );
        $this->assertNotNull($full_plural);
        $this->assertStringContainsString('complet' . self::NBSP . ':', $full_plural);
        $this->assertStringNotContainsString('complet :', $full_plural);

        $failed_line = $catalog->translate('%1$s: %2$s');
        $this->assertNotNull($failed_line);
        $this->assertStringContainsString('%1$s' . self::NBSP . ': %2$s', $failed_line);

        $partial = $catalog->pluralForm(
            '%1$s of %2$s players moved. These couldn\'t be moved:',
            '%1$s of %2$s players moved. These couldn\'t be moved:',
            1
        );
        $this->assertStringContainsString(self::NBSP . ':', (string) $partial);

        $target_missing = $catalog->translate('The destination roster no longer exists or was deleted.');
        $this->assertSame(
            'Le groupe de destination n\'existe plus ou a été supprimé.',
            $target_missing
        );

        $all_failed = $catalog->translate('No players were moved.');
        $this->assertSame('Aucun participant n\'a été déplacé.', $all_failed);

        $error_generic = $catalog->translate('An error occurred. Please try again.');
        $this->assertSame(
            'Une erreur s\'est produite. Veuillez réessayer.',
            $error_generic
        );

        $this->assertSame('Appliquer', $catalog->translate('Apply'));
        $this->assertSame(
            'Veuillez sélectionner un groupe de destination.',
            $catalog->translate('Please select a destination roster.')
        );
        $this->assertSame('Groupe sans titre', $catalog->translate('Untitled roster'));

        $ended = $catalog->translate('This course has ended.');
        $this->assertNull($ended);
    }
}
