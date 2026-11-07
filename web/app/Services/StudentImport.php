<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\InstitutionRepository;
use App\Repositories\StructureRepository;
use App\Repositories\UserRepository;
use RuntimeException;

/**
 * Adding many students at once from a spreadsheet saved as CSV. One row per student: their
 * email address, and optionally a name (for the preview), a student number, the classes to put
 * them in and a programme.
 *
 * Nothing is written until the preview has been looked at and confirmed, and no row is ever
 * skipped silently: every row comes back with what will happen to it or why it cannot be taken,
 * and after importing, a CSV of every row and its outcome can be downloaded.
 *
 * - Someone already a student of the institution has their record updated and is put in the
 *   classes.
 * - Anyone else (an account that does not belong yet, or an address with no account) is invited
 *   as a student; the number, programme and classes are applied when they accept.
 */
final class StudentImport
{
    public const COLUMNS = ['email', 'name', 'student_number', 'classes', 'programme'];
    public const MAX_ROWS = 2000;
    public const MAX_BYTES = 1048576;

    public function __construct(
        private StructureRepository $structure,
        private InstitutionRepository $institutions,
        private UserRepository $users,
    ) {
    }

    public static function template(): string
    {
        return implode(',', self::COLUMNS) . "\r\n"
            . "chikondi.banda@example.com,Chikondi Banda,BICT-2026-001,CCNA 1,Networking diploma\r\n"
            . "thoko.phiri@example.com,Thoko Phiri,BICT-2026-002,CCNA 1; Linux basics,\r\n";
    }

    /**
     * The rows of a CSV file, keyed by column name, with their line numbers.
     *
     * @return list<array{line:int,email:string,name:string,student_number:string,classes:string,programme:string}>
     */
    public static function parse(string $csv): array
    {
        if (strlen($csv) > self::MAX_BYTES) {
            throw new RuntimeException('The file is larger than 1 MB. Split it into smaller files.');
        }
        $csv = str_starts_with($csv, "\xEF\xBB\xBF") ? substr($csv, 3) : $csv;
        if (!mb_check_encoding($csv, 'UTF-8')) {
            $csv = mb_convert_encoding($csv, 'UTF-8', 'Windows-1252');
        }
        $lines = preg_split('/\r\n|\r|\n/', $csv) ?: [];
        $first = (string) ($lines[0] ?? '');
        $delimiter = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $csv);
        rewind($stream);
        $header = fgetcsv($stream, 0, $delimiter, '"', '');
        if (!is_array($header)) {
            throw new RuntimeException('The file is empty.');
        }
        $map = [];
        foreach ($header as $pos => $h) {
            $key = str_replace([' ', '-'], '_', strtolower(trim((string) $h)));
            $key = match ($key) { 'e_mail', 'email_address' => 'email', 'class' => 'classes', 'number', 'student_no' => 'student_number', default => $key };
            if (in_array($key, self::COLUMNS, true)) {
                $map[$key] = $pos;
            }
        }
        if (!isset($map['email'])) {
            throw new RuntimeException('The first row must name the columns, with one called "email". Download the template to see the layout.');
        }
        $rows = [];
        $line = 1;
        while (($cells = fgetcsv($stream, 0, $delimiter, '"', '')) !== false) {
            $line++;
            if ($cells === [null] || implode('', array_map('strval', $cells)) === '') {
                continue;
            }
            if (count($rows) >= self::MAX_ROWS) {
                throw new RuntimeException('The file has more than ' . self::MAX_ROWS . ' students. Split it into smaller files.');
            }
            $row = ['line' => $line];
            foreach (self::COLUMNS as $c) {
                $row[$c] = isset($map[$c]) ? trim((string) ($cells[$map[$c]] ?? '')) : '';
            }
            $rows[] = $row;
        }
        fclose($stream);
        if ($rows === []) {
            throw new RuntimeException('The file names its columns but has no students under them.');
        }
        return $rows;
    }

    /**
     * What will happen to each row: "enrol" (already a student here), "invite", or an error.
     *
     * @param array<string,mixed> $i
     * @param list<array<string,mixed>> $rows from parse()
     * @return list<array<string,mixed>> each row with action, error, its classes and programme resolved
     */
    public function check(array $i, array $rows): array
    {
        $iid = (int) $i['id'];
        $emails = [];
        $numbers = [];
        $out = [];
        foreach ($rows as $row) {
            $row['email'] = UserRepository::normalizeEmail((string) $row['email']);
            $row += ['action' => null, 'error' => null, 'class_uuids' => [], 'class_labels' => [], 'programme_uuid' => null, 'membership_id' => null];
            $errors = [];
            if (!UserRepository::validEmail($row['email'])) {
                $errors[] = 'The email address is missing or not valid.';
            } elseif (isset($emails[$row['email']])) {
                $errors[] = 'The same email address is on line ' . $emails[$row['email']] . '.';
            } else {
                $emails[$row['email']] = $row['line'];
            }
            if (mb_strlen((string) $row['student_number']) > 40) {
                $errors[] = 'The student number is longer than 40 characters.';
            } elseif ($row['student_number'] !== '') {
                if (isset($numbers[$row['student_number']])) {
                    $errors[] = 'The student number ' . $row['student_number'] . ' is also on line ' . $numbers[$row['student_number']] . '.';
                } else {
                    $numbers[$row['student_number']] = $row['line'];
                }
            }
            foreach (preg_split('/\s*[;|]\s*/', (string) $row['classes'], -1, PREG_SPLIT_NO_EMPTY) ?: [] as $name) {
                $c = $this->structure->classByName($iid, $name);
                if ($c === null) {
                    $errors[] = 'There is no single open class called "' . $name . '".';
                } else {
                    $row['class_uuids'][] = (string) $c['uuid'];
                    $row['class_labels'][] = InstitutionStructure::label((string) $c['name'], (string) $c['intake']);
                }
            }
            if ($row['programme'] !== '') {
                $p = $this->structure->programmeByName($iid, (string) $row['programme']);
                if ($p === null) {
                    $errors[] = 'There is no programme called "' . $row['programme'] . '".';
                } else {
                    $row['programme_uuid'] = (string) $p['uuid'];
                }
            }
            $account = UserRepository::validEmail($row['email']) ? $this->users->findByEmail($row['email']) : null;
            $m = $account !== null ? $this->institutions->membership($iid, (int) $account['id']) : null;
            if ($m !== null && $m['status'] === 'active') {
                if ($m['role'] !== InstitutionAccess::STUDENT) {
                    $errors[] = 'This person already belongs here as ' . InstitutionAccess::roleName((string) $m['role']) . ', not as a student.';
                } else {
                    $row['action'] = 'enrol';
                    $row['membership_id'] = (int) $m['id'];
                }
            } else {
                $row['action'] = 'invite';
            }
            if ($row['student_number'] !== '' && $this->structure->studentNumberTaken($iid, (string) $row['student_number'], (int) ($row['membership_id'] ?? 0))) {
                $errors[] = 'Another student already has the number ' . $row['student_number'] . '.';
            }
            if ($errors !== []) {
                $row['action'] = null;
                $row['error'] = implode(' ', $errors);
            }
            $out[] = $row;
        }
        return $out;
    }

    /**
     * Import the rows that passed check(), checking each again first (things may have changed
     * since the preview).
     *
     * @param array<string,mixed> $i @param array<string,mixed> $actor membership of the person importing
     * @param array<string,mixed> $user @param list<array<string,mixed>> $previewed
     * @return array{rows:list<array<string,mixed>>,enrolled:int,invited:int,failed:int}
     */
    public function apply(array $i, array $actor, array $user, array $previewed, Institutions $institutions, InstitutionStructure $structure): array
    {
        $rows = $this->check($i, array_map(static fn(array $r): array => array_intersect_key($r, array_flip(['line', ...self::COLUMNS])), $previewed));
        $counts = ['enrolled' => 0, 'invited' => 0, 'failed' => 0];
        foreach ($rows as $k => $row) {
            if ($row['action'] === null) {
                $rows[$k]['outcome'] = 'Not imported';
                $rows[$k]['detail'] = $row['error'];
                $counts['failed']++;
                continue;
            }
            try {
                if ($row['action'] === 'enrol') {
                    $structure->applyInvitationDetails((int) $i['id'], (int) $row['membership_id'],
                        ['student_number' => $row['student_number'], 'programme' => $row['programme_uuid'], 'classes' => $row['class_uuids']], (int) $user['id']);
                    $rows[$k]['outcome'] = 'Already a student: record updated';
                    $rows[$k]['detail'] = $row['class_labels'] !== [] ? 'In ' . implode(', ', $row['class_labels']) : '';
                    $counts['enrolled']++;
                } else {
                    $sent = $institutions->invite($i, $actor, $user, (string) $row['email'], InstitutionAccess::STUDENT,
                        ['student_number' => $row['student_number'], 'programme' => $row['programme_uuid'], 'classes' => $row['class_uuids']]);
                    $rows[$k]['outcome'] = $sent['emailed'] ? 'Invited by email' : 'Invited (email is not set up: send the link)';
                    $rows[$k]['detail'] = $sent['emailed'] ? '' : $sent['link'];
                    $counts['invited']++;
                }
            } catch (RuntimeException $e) {
                $rows[$k]['outcome'] = 'Not imported';
                $rows[$k]['detail'] = $e->getMessage();
                $counts['failed']++;
            }
        }
        $structure->record('institution.students_imported', $i, $counts);
        return ['rows' => $rows] + $counts;
    }

    /** The outcome of every row, as a CSV to download. @param list<array<string,mixed>> $rows */
    public static function resultsCsv(array $rows): string
    {
        $out = fopen('php://temp', 'r+');
        fputcsv($out, ['line', 'email', 'name', 'student_number', 'outcome', 'detail'], ',', '"', '');
        foreach ($rows as $r) {
            fputcsv($out, array_map(static function (mixed $v): string {
                $v = (string) $v;
                return preg_match('/^[=+\-@\t\r]/', $v) === 1 ? "'" . $v : $v; // no formulas when opened in a spreadsheet
            }, [$r['line'], $r['email'], $r['name'], $r['student_number'], $r['outcome'] ?? '', $r['detail'] ?? '']), ',', '"', '');
        }
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);
        return $csv;
    }
}
