<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Ids;
use RuntimeException;

/**
 * What platform administrators ask of institutions: document slots (each one upload, required or
 * optional, for some or all kinds of institution), extra profile fields, and which optional
 * built-in profile fields are shown.
 */
final class InstitutionRequirements
{
    public const KINDS = ['document' => 'Document', 'field' => 'Profile field'];
    public const FIELD_TYPES = ['text' => 'Short text', 'textarea' => 'Long text', 'number' => 'Number', 'date' => 'Date', 'url' => 'Web address',
        'email' => 'Email', 'select' => 'Choice'];
    /** The built-in fields that are always shown, because the platform needs them. */
    public const CORE = ['name', 'type', 'address', 'region', 'district', 'email', 'phone', 'intended_use', 'contact_name', 'contact_position', 'contact_phone'];

    /** Which profile columns each optional built-in field covers. */
    public const BUILTIN_FIELDS = ['short_name' => ['short_name'], 'year_established' => ['year_established'], 'services' => ['services'], 'website' => ['website'],
        'registration' => ['registration_authority', 'registration_number'], 'accreditation' => ['accreditation_authority', 'accreditation_reference', 'accreditation_expires'],
        'registration_info' => ['registration_info']];

    /** The starting list (as migration 041 seeds it): kind, key, label, required, applies to. */
    public const DEFAULTS = [
        ['document', 'registration_certificate', 'Registration certificate', 1, 'university,college,tevet,exam_provider'],
        ['document', 'accreditation_certificate', 'Accreditation certificate or letter', 0, null],
        ['document', 'authority_letter', 'Letter authorising the representative', 0, null],
        ['document', 'identification', 'Representative\'s identification', 0, null],
        ['builtin', 'short_name', 'Trading name', 0, null],
        ['builtin', 'year_established', 'Year established', 0, null],
        ['builtin', 'services', 'Training offered', 0, null],
        ['builtin', 'website', 'Website', 0, null],
        ['builtin', 'registration', 'Registration authority and number', 0, null],
        ['builtin', 'accreditation', 'Accreditation details', 0, null],
        ['builtin', 'registration_info', 'Other notes', 0, null],
    ];

    private bool $ready = false;

    public function __construct(private Database $db, private AuditLog $audit)
    {
    }

    /** Put the starting list in place when there is none at all (a new database). */
    private function ensure(): void
    {
        if ($this->ready) {
            return;
        }
        $this->ready = true;
        if ($this->db->value('SELECT 1 FROM institution_requirements LIMIT 1') !== null) {
            return;
        }
        $now = Database::now();
        foreach (self::DEFAULTS as $n => [$kind, $key, $label, $required, $applies]) {
            $this->db->run('INSERT INTO institution_requirements (uuid, kind, field_key, label, required, applies_to, position, created_at, updated_at)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)', [Ids::uuid(), $kind, $key, $label, $required, $applies, $n + 1, $now, $now]);
        }
    }

    /** @return list<array<string,mixed>> */
    public function all(string $kind): array
    {
        $this->ensure();
        return $this->db->all('SELECT * FROM institution_requirements WHERE kind = ? ORDER BY position, id', [$kind]);
    }

    /** @return array<string,mixed>|null */
    public function find(string $uuid): ?array
    {
        $this->ensure();
        return Ids::isUuid($uuid) ? $this->db->one('SELECT * FROM institution_requirements WHERE uuid = ?', [$uuid]) : null;
    }

    /** Active documents or fields that apply to this kind of institution ('' for every kind). @return list<array<string,mixed>> */
    public function for(string $kind, string $type): array
    {
        $this->ensure();
        return array_values(array_filter($this->db->all('SELECT * FROM institution_requirements WHERE kind = ? AND active = 1 ORDER BY position, id', [$kind]),
            static fn(array $r): bool => $type === '' || $r['applies_to'] === null || in_array($type, explode(',', (string) $r['applies_to']), true)));
    }

    /** @return array<string,bool> which optional built-in fields are shown, by key */
    public function shown(): array
    {
        $out = [];
        foreach ($this->all('builtin') as $r) {
            $out[(string) $r['field_key']] = (int) $r['active'] === 1;
        }
        return $out;
    }

    /** @return list<string> profile columns the administrator has hidden, so a save keeps what they held */
    public function hiddenColumns(): array
    {
        $out = [];
        foreach ($this->shown() as $key => $on) {
            if (!$on) {
                array_push($out, ...(self::BUILTIN_FIELDS[$key] ?? []));
            }
        }
        return $out;
    }

    /**
     * What the profile form needs: which built-in fields show, the extra fields, and their values
     * by uuid (posted ones when re-showing a form, else the institution's stored answers).
     *
     * @param array<string,mixed>|null $posted @return array{shown:array<string,bool>,custom:list<array<string,mixed>>,values:array<string,string>}
     */
    public function form(?int $iid = null, ?array $posted = null): array
    {
        $custom = $this->for('field', '');
        $stored = $iid !== null ? $this->values($iid) : [];
        $values = [];
        foreach ($custom as $f) {
            $values[(string) $f['uuid']] = $posted !== null ? (string) (is_string($posted[$f['uuid']] ?? null) ? $posted[$f['uuid']] : '') : ($stored[(int) $f['id']] ?? '');
        }
        return ['shown' => $this->shown(), 'custom' => $custom, 'values' => $values];
    }

    /**
     * Add or change a document slot or profile field.
     *
     * @param array<string,mixed>|null $r @param array<string,mixed> $in @param array<string,mixed> $user
     */
    public function save(?array $r, string $kind, array $in, array $user): void
    {
        if (!isset(self::KINDS[$kind])) {
            throw new RuntimeException('Choose a document or a field.');
        }
        $label = mb_substr(trim((string) ($in['label'] ?? '')), 0, 120);
        if ($label === '') {
            throw new RuntimeException('Give it a name.');
        }
        $help = mb_substr(trim((string) ($in['help'] ?? '')), 0, 255);
        $type = $kind === 'field' ? (string) ($in['field_type'] ?? 'text') : 'text';
        if (!isset(self::FIELD_TYPES[$type])) {
            throw new RuntimeException('Choose a field type.');
        }
        $options = $type === 'select' ? trim(implode("\n", array_filter(array_map('trim', preg_split('/\R/', (string) ($in['options'] ?? '')) ?: [])))) : '';
        if ($type === 'select' && $options === '') {
            throw new RuntimeException('List the choices, one per line.');
        }
        $applies = array_values(array_intersect((array) ($in['applies_to'] ?? []), array_keys(Institutions::TYPES)));
        $vals = [$label, $help !== '' ? $help : null, $type, $options !== '' ? mb_substr($options, 0, 1000) : null, !empty($in['required']) ? 1 : 0,
            $applies === [] || count($applies) === count(Institutions::TYPES) ? null : implode(',', $applies)];
        $now = Database::now();
        if ($r === null) {
            $pos = (int) $this->db->value('SELECT COALESCE(MAX(position), 0) + 1 FROM institution_requirements WHERE kind = ?', [$kind]);
            $this->db->run('INSERT INTO institution_requirements (uuid, kind, label, help, field_type, options, required, applies_to, position, created_at, updated_at)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [Ids::uuid(), $kind, ...$vals, $pos, $now, $now]);
        } else {
            $this->db->run('UPDATE institution_requirements SET label = ?, help = ?, field_type = ?, options = ?, required = ?, applies_to = ?, updated_at = ? WHERE id = ?',
                [...$vals, $now, (int) $r['id']]);
        }
        $this->audit->record('platform.requirement_saved', ['kind' => $kind, 'label' => $label, 'required' => !empty($in['required'])]);
    }

    /** Switch a requirement or built-in field on or off. @param array<string,mixed> $r */
    public function toggle(array $r): void
    {
        $this->db->run('UPDATE institution_requirements SET active = 1 - active, updated_at = ? WHERE id = ?', [Database::now(), (int) $r['id']]);
        $this->audit->record('platform.requirement_toggled', ['kind' => $r['kind'], 'label' => $r['label'], 'active' => (int) $r['active'] === 0]);
    }

    /** Move a requirement up or down its list. @param array<string,mixed> $r */
    public function move(array $r, int $by): void
    {
        $list = $this->all((string) $r['kind']);
        $ids = array_map(static fn(array $x): int => (int) $x['id'], $list);
        $at = array_search((int) $r['id'], $ids, true);
        $to = $at === false ? false : $at + $by;
        if ($at === false || $to < 0 || $to >= count($ids)) {
            return;
        }
        [$ids[$at], $ids[$to]] = [$ids[$to], $ids[$at]];
        foreach ($ids as $pos => $id) {
            $this->db->run('UPDATE institution_requirements SET position = ? WHERE id = ?', [$pos + 1, $id]);
        }
    }

    // Values -------------------------------------------------------------------------------

    /** @return array<int,string> an institution's field answers by requirement id */
    public function values(int $iid): array
    {
        $out = [];
        foreach ($this->db->all('SELECT requirement_id, value FROM institution_field_values WHERE institution_id = ?', [$iid]) as $v) {
            $out[(int) $v['requirement_id']] = (string) $v['value'];
        }
        return $out;
    }

    /**
     * Check posted answers to the fields for this kind of institution.
     *
     * @param array<string,mixed> $posted by requirement uuid @return array{0:array<int,string>,1:array<string,string>} answers by id, errors by uuid
     */
    public function check(string $type, array $posted): array
    {
        $values = [];
        $errors = [];
        foreach ($this->for('field', $type) as $f) {
            $v = trim(str_replace(["\r\n", "\r"], "\n", (string) ($posted[$f['uuid']] ?? '')));
            $v = $f['field_type'] === 'textarea' ? mb_substr($v, 0, 2000) : mb_substr((string) preg_replace('/\s+/u', ' ', $v), 0, 255);
            $bad = match (true) {
                $v === '' => (int) $f['required'] === 1 ? 'is needed.' : null,
                $f['field_type'] === 'number' => is_numeric($v) ? null : 'must be a number.',
                $f['field_type'] === 'date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1 ? null : 'must be a date.',
                $f['field_type'] === 'url' => preg_match('#^https?://\S+\.\S+$#i', $v) === 1 ? null : 'must start with https://.',
                $f['field_type'] === 'email' => filter_var($v, FILTER_VALIDATE_EMAIL) !== false ? null : 'must be an email address.',
                $f['field_type'] === 'select' => in_array($v, explode("\n", (string) $f['options']), true) ? null : 'must be one of the choices.',
                default => null,
            };
            if ($bad !== null) {
                $errors[(string) $f['uuid']] = $f['label'] . ' ' . $bad;
            }
            $values[(int) $f['id']] = $v;
        }
        return [$values, $errors];
    }

    /** @param array<int,string> $values from check() */
    public function store(int $iid, array $values): void
    {
        $now = Database::now();
        foreach ($values as $id => $v) {
            if ($v === '') {
                $this->db->run('DELETE FROM institution_field_values WHERE institution_id = ? AND requirement_id = ?', [$iid, $id]);
            } else {
                $this->db->run('INSERT INTO institution_field_values (institution_id, requirement_id, value, updated_at) VALUES (?, ?, ?, ?)
                                ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = VALUES(updated_at)', [$iid, $id, $v, $now]);
            }
        }
    }
}
