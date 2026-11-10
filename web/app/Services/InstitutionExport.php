<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use RuntimeException;
use ZipArchive;

/**
 * Everything an institution holds, in one ZIP an institution can take away: a CSV for every
 * table with its rows (found by the institution_id column, so a new table is included without
 * changing this), the people its rows name, its activity history, and its uploaded files.
 *
 * Secrets are left out: invitation and verification tokens. The rest of a credential (its
 * signature and key) is kept, because it is what makes the credential checkable.
 */
final class InstitutionExport
{
    /** Columns never exported, whatever table they are in. */
    public const SECRET_COLUMNS = ['token', 'token_sha256', 'password_hash', 'secret'];

    public function __construct(private Database $db, private AuditLog $audit, private string $storage)
    {
    }

    /** @return list<string> the tables with institution rows */
    public function tables(): array
    {
        return array_map('strval', array_column($this->db->all("SELECT TABLE_NAME FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'institution_id' ORDER BY TABLE_NAME"), 'TABLE_NAME'));
    }

    /**
     * Build the ZIP in a temporary file. The caller sends it and deletes it.
     *
     * @param array<string,mixed> $institution @param array<string,mixed> $actor
     * @return array{path:string,name:string,tables:int,rows:int,files:int}
     */
    public function build(array $institution, array $actor): array
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('This server cannot make ZIP files (the PHP zip extension is missing).');
        }
        $id = (int) $institution['id'];
        $path = (string) tempnam(sys_get_temp_dir(), 'inst-export-');
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('The export file could not be made.');
        }
        $rowCount = 0;
        $userIds = [];
        $tables = $this->tables();
        foreach ($tables as $table) {
            if (preg_match('/^[a-z0-9_]+$/', $table) !== 1) {
                continue;
            }
            $rows = $this->db->all('SELECT * FROM `' . $table . '` WHERE institution_id = ? ORDER BY 1', [$id]);
            $rows = array_map(static fn(array $r): array => array_diff_key($r, array_flip(self::SECRET_COLUMNS)), $rows);
            foreach ($rows as $r) {
                foreach (['user_id', 'invited_by', 'created_by', 'uploaded_by', 'marked_by', 'issued_by', 'changed_by', 'decided_by', 'set_by', 'author_id'] as $c) {
                    if (isset($r[$c]) && $r[$c] !== null) {
                        $userIds[(int) $r[$c]] = true;
                    }
                }
            }
            $zip->addFromString('data/' . $table . '.csv', self::csv($rows));
            $rowCount += count($rows);
        }
        $zip->addFromString('data/institution.csv', self::csv([array_diff_key((array) $this->db->one('SELECT * FROM institutions WHERE id = ?', [$id]), ['deleted_at' => 1])]));
        $people = $userIds === [] ? [] : $this->db->all('SELECT id, username, display_name, email FROM users WHERE id IN (' . implode(',', array_map('intval', array_keys($userIds))) . ') ORDER BY id');
        $zip->addFromString('data/people.csv', self::csv($people));
        $activity = array_map(static fn(array $r): array => ['at' => $r['created_at'], 'who' => (string) ($r['actor'] ?? ''), 'action' => $r['action'],
            'what' => AuditText::describe($r)['text']], $this->audit->forSubject('institution', (string) $institution['uuid'], 100000));
        $zip->addFromString('data/activity.csv', self::csv($activity));

        $files = 0;
        $dir = $this->storage . '/institution_documents/' . $institution['uuid'];
        if (is_dir($dir)) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if ($f->isFile()) {
                    $zip->addFile($f->getPathname(), 'files/' . ltrim(str_replace('\\', '/', substr($f->getPathname(), strlen($dir))), '/'));
                    $files++;
                }
            }
        }
        $zip->addFromString('README.txt', "Everything " . $institution['name'] . " holds, exported " . gmdate('Y-m-d H:i') . " UTC.\n\n"
            . "data/      one CSV per table. Ids in one file match ids in another (class_id is a row in institution_classes).\n"
            . "           people.csv names the accounts the rows refer to. activity.csv is the history in words.\n"
            . "files/     uploaded documents, materials and handed-in work, by their stored names.\n\n"
            . "Times are UTC. Invitation and verification tokens are left out.\n");
        if (!$zip->close()) {
            throw new RuntimeException('The export file could not be written.');
        }
        $this->audit->record('institution.exported', ['institution' => $institution['name'], 'rows' => $rowCount, 'files' => $files], 'institution', (string) $institution['uuid']);
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower((string) ($institution['short_name'] ?: $institution['name']))), '-');
        return ['path' => $path, 'name' => ($slug !== '' ? $slug : 'institution') . '-export-' . gmdate('Ymd-His') . '.zip', 'tables' => count($tables) + 3, 'rows' => $rowCount, 'files' => $files];
    }

    /** @param list<array<string,mixed>> $rows */
    public static function csv(array $rows): string
    {
        $out = fopen('php://temp', 'r+');
        if ($rows !== []) {
            fputcsv($out, array_keys($rows[0]), ',', '"', '');
            foreach ($rows as $r) {
                // Text that starts like a formula gets an apostrophe, so a spreadsheet shows it as text. Numbers are left alone.
                fputcsv($out, array_map(static fn($v): string => is_string($v) && !is_numeric($v) && preg_match('/^[=+\-@\t\r]/', $v) === 1 ? "'" . $v : (string) $v, $r), ',', '"', '');
            }
        }
        rewind($out);
        return (string) stream_get_contents($out);
    }
}
