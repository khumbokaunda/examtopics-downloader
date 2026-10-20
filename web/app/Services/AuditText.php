<?php

declare(strict_types=1);

namespace App\Services;

/**
 * The activity history in plain English: one sentence per entry, with an icon and a tone.
 * Every action the code records has a sentence here (AuditTest fails otherwise); an unknown
 * one reads as its name rather than as something it is not.
 */
final class AuditText
{
    /**
     * @param array<string,mixed> $row an audit_log row
     * @param array<int,string> $names display names of current accounts by id
     * @return array{text:string,icon:string,tone:string}
     */
    public static function describe(array $row, array $names = []): array
    {
        $d = json_decode((string) ($row['details'] ?? ''), true);
        $d = is_array($d) ? $d : [];
        $who = self::who($row, $names);
        $exam = static fn(): string => strtoupper((string) ($d['exam'] ?? '')) . (isset($d['provider']) ? ' (' . $d['provider'] . ')' : '');
        $named = static fn(string $key): string => (string) ($d[$key] ?? '?');
        [$text, $icon, $tone] = match ((string) $row['action']) {
            'auth.signed_in' => [$who . ' signed in', 'box-arrow-in-right', 'neutral'],
            'auth.sign_in_failed' => ['Failed sign-in as ' . $named('identifier') . (isset($d['reason']) ? ' (' . $d['reason'] . ')' : ''), 'shield-exclamation', 'danger'],
            'auth.sign_in_refused' => [$who . ' typed the right password but could not sign in: the account is ' . $named('status'), 'shield-lock', 'warning'],
            'auth.account_locked' => ['The account ' . $named('username') . ' was locked for ' . (int) ($d['minutes'] ?? 0) . ' minutes after '
                . (int) ($d['attempts'] ?? 0) . ' wrong passwords' . (isset($d['ip']) ? ' from ' . $d['ip'] : ''), 'lock', 'danger'],
            'security.network_blocked' => ['Sign-ins from ' . $named('ip') . ' were refused for ' . (int) ($d['minutes'] ?? 0) . ' minutes after '
                . (int) ($d['failures'] ?? 0) . ' failed attempts', 'slash-circle', 'danger'],
            'security.account_unlocked' => [$who . ' unlocked the account ' . $named('username'), 'unlock', 'warning'],
            'security.network_unblocked' => [$who . ' allowed sign-ins from ' . $named('ip') . ' again', 'unlock', 'warning'],
            'security.flag_cleared' => [$who . ' marked the sign-in of ' . $named('username') . ' from ' . $named('ip') . ' as theirs', 'check2', 'neutral'],
            'security.flag_restored' => [$who . ' flagged the sign-in of ' . $named('username') . ' from ' . $named('ip') . ' again', 'exclamation-triangle', 'warning'],
            'auth.two_step_failed' => ['A wrong two-step code was typed for ' . (string) ($row['subject_id'] ?? '?') . ', after the right password', 'phone', 'danger'],
            'auth.recovery_code_used' => [$who . ' signed in with a recovery code (' . (int) ($d['left'] ?? 0) . ' left)', 'key', 'warning'],
            'security.two_step_enabled' => [$who . ' turned on two-step sign-in', 'phone', 'success'],
            'security.two_step_disabled' => [($d['by'] ?? '') === 'self' ? $who . ' turned off their two-step sign-in'
                : $who . ' turned off two-step sign-in for ' . $named('username'), 'phone', 'warning'],
            'security.recovery_codes_made' => [$who . ' made new recovery codes', 'key', 'neutral'],
            'security.confirmed' => [$who . ' confirmed it was them, with their ' . $named('with'), 'shield-check', 'neutral'],
            'security.confirm_failed' => [$who . ' failed to confirm it was them', 'shield-exclamation', 'warning'],
            'auth.signed_out' => [$who . ' signed out', 'box-arrow-right', 'neutral'],
            'auth.signed_up' => [$who . ' created an account by signing up', 'person-plus', 'primary'],
            'auth.email_verified' => [$who . ' verified their email address', 'envelope-check', 'success'],
            'auth.reset_requested' => ['A password reset code was sent to ' . $who, 'key', 'primary'],
            'auth.password_reset' => [$who . ' set a new password with an emailed code', 'key', 'warning'],
            'account.profile_changed' => [$who . ' changed their ' . self::fields($d['fields'] ?? []), 'person', 'neutral'],
            'account.password_changed' => [$who . ' changed their password', 'key', 'warning'],
            'account.email_verified' => [$who . ' confirmed the email address ' . $named('email'), 'envelope-check', 'success'],
            'user.created' => [$who . ' created the account ' . $named('username') . ' (' . self::role($d['role'] ?? '') . ')', 'person-plus', 'primary'],
            'user.updated' => [$who . ' changed ' . $named('username') . ': ' . self::changes($d['changes'] ?? []), 'person-gear', 'warning'],
            'user.deleted' => [$who . ' deleted the account ' . $named('username'), 'person-x', 'danger'],
            'user.approved' => [$who . ' approved the account ' . $named('username'), 'person-check', 'success'],
            'users.registration_changed' => [$who . ' set sign-up to "' . $named('mode') . '"', 'person-plus', 'warning'],
            'settings.time_changed' => [$who . ' changed the time and sign-in settings: ' . self::changes($d['changes'] ?? []), 'clock', 'warning'],
            'settings.branding_changed' => [$who . ' changed the branding: ' . self::fields($d['fields'] ?? []), 'brush', 'neutral'],
            'settings.logo_removed' => [$who . ' removed the logo', 'brush', 'neutral'],
            'settings.email_changed' => [$who . ' changed the email settings: ' . self::fields($d['fields'] ?? []), 'envelope', 'warning'],
            'settings.email_test_sent' => [$who . ' sent a test email to ' . $named('to'), 'envelope', 'neutral'],
            'settings.reconcile_run' => [$who . ' checked for stuck jobs', 'arrow-repeat', 'neutral'],
            'provider.added' => [$who . ' added the provider ' . $named('provider'), 'buildings', 'primary'],
            'provider.discover_queued' => [$who . ' started loading the list of providers', 'buildings', 'neutral'],
            'provider.refresh_queued' => [$who . ' started refreshing the exam list of ' . $named('provider'), 'arrow-repeat', 'neutral'],
            'provider.refreshed' => ['The exam list of ' . $named('provider') . ' was refreshed' . (isset($d['message']) ? ': ' . $d['message'] : ''), 'arrow-repeat', 'success'],
            'collect.queued' => [$who . ' started collecting ' . $exam(), 'cloud-arrow-down', 'neutral'],
            'collect.completed' => [$exam() . ' was collected' . (isset($d['message']) ? ': ' . $d['message'] : ''), 'cloud-check', 'success'],
            'job.failed' => ['A ' . str_replace('_', ' ', $named('type')) . ' job failed' . (isset($d['exam']) ? ' for ' . $exam() : (isset($d['provider']) ? ' for ' . $d['provider'] : ''))
                . (isset($d['message']) ? ': ' . $d['message'] : ''), 'x-circle', 'danger'],
            'job.cancelled' => [$who . ' cancelled a ' . str_replace('_', ' ', $named('type')) . ' job' . (isset($d['exam']) ? ' for ' . $exam() : ''), 'slash-circle', 'warning'],
            'exam.version_deleted' => [$who . ' deleted version ' . $named('version') . ' of ' . $exam(), 'trash3', 'danger'],
            'exam.images_queued' => [$who . ' started downloading the images of ' . $exam(), 'images', 'neutral'],
            'answers.saved' => [$who . ' ' . match ((string) ($d['kind'] ?? '')) {
                'corrected' => 'corrected the answer to question ' . (int) ($d['question'] ?? 0) . ' of ' . $exam() . ' to ' . $named('answer'),
                'checked' => 'marked the answer to question ' . (int) ($d['question'] ?? 0) . ' of ' . $exam() . ' as checked',
                default => 'added a note to question ' . (int) ($d['question'] ?? 0) . ' of ' . $exam(),
            }, 'pencil-square', 'primary'],
            'answers.removed' => [$who . ' removed the correction to question ' . (int) ($d['question'] ?? 0) . ' of ' . $exam(), 'pencil-square', 'neutral'],
            'document.build_queued' => [$who . ' built a ' . $named('format') . ' of ' . $exam() . (isset($d['variant']) ? ': ' . $d['variant'] : ''), 'file-earmark-text', 'neutral'],
            'document.deleted' => [$who . ' deleted a ' . $named('format') . ' of ' . $exam(), 'trash3', 'warning'],
            'request.created' => [$who . ' requested ' . $exam(), 'inbox', 'primary'],
            'request.cancelled' => [$who . ' cancelled their request for ' . $exam(), 'inbox', 'neutral'],
            'request.answered' => [$who . ' marked the request by ' . $named('requester') . ' for ' . $exam() . ' as ' . $named('status'), 'inbox', 'neutral'],
            'backup.created' => [$who . ' made the backup ' . $named('name'), 'archive', 'success'],
            'backup.downloaded' => [$who . ' downloaded the backup ' . $named('name'), 'download', 'warning'],
            'backup.uploaded' => [$who . ' uploaded the backup ' . $named('name'), 'upload', 'primary'],
            'backup.deleted' => [$who . ' deleted the backup ' . $named('name'), 'trash3', 'warning'],
            'backup.cancelled' => [$who . ' cancelled a backup being made', 'slash-circle', 'neutral'],
            'backup.restored' => [$who . ' restored the backup ' . $named('name'), 'arrow-counterclockwise', 'danger'],
            'activity.exported' => [$who . ' downloaded the activity history', 'download', 'warning'],
            'backup.restore_failed' => [$who . ' tried to restore the backup ' . $named('name') . ', and it failed', 'arrow-counterclockwise', 'danger'],
            default => [$who . ': ' . str_replace(['.', '_'], ' ', (string) $row['action']), 'dot', 'neutral'],
        };
        return ['text' => $text, 'icon' => $icon, 'tone' => $tone];
    }

    /** Every action describe() knows, for the test that keeps them in step with the code. */
    public static function known(string $action): bool
    {
        return self::describe(['action' => $action, 'actor_kind' => 'system'])['icon'] !== 'dot';
    }

    /**
     * @param array<string,mixed> $row
     * @param array<int,string> $names
     */
    public static function who(array $row, array $names = []): string
    {
        return match ((string) ($row['actor_kind'] ?? '')) {
            'user' => $names[(int) ($row['user_id'] ?? 0)] ?? ((string) ($row['actor'] ?? '') !== '' ? (string) $row['actor'] : 'Someone'),
            'visitor' => 'Someone',
            default => 'The system',
        };
    }

    private static function role(mixed $role): string
    {
        return Access::ROLES[(string) $role]['name'] ?? (string) $role;
    }

    /** @param mixed $fields a list of field names */
    private static function fields(mixed $fields): string
    {
        $list = array_map(static fn($f) => str_replace('_', ' ', (string) $f), is_array($fields) ? $fields : []);
        return $list === [] ? 'nothing' : implode(', ', $list);
    }

    /** @param mixed $changes field => [before, after], or a note */
    private static function changes(mixed $changes): string
    {
        $parts = [];
        foreach (is_array($changes) ? $changes : [] as $field => $c) {
            $label = str_replace('_', ' ', (string) $field);
            if (is_array($c) && array_key_exists(0, $c) && array_key_exists(1, $c) && !is_array($c[0]) && !is_array($c[1])) {
                $parts[] = $label . ' ' . self::value($c[0]) . ' to ' . self::value($c[1]);
            } elseif (is_array($c) && (isset($c['added']) || isset($c['removed']))) {
                $bits = [];
                if (($c['added'] ?? []) !== []) {
                    $bits[] = 'added ' . implode(', ', (array) $c['added']);
                }
                if (($c['removed'] ?? []) !== []) {
                    $bits[] = 'removed ' . implode(', ', (array) $c['removed']);
                }
                $parts[] = $label . ' (' . implode('; ', $bits) . ')';
            } else {
                $parts[] = $label . (is_string($c) && $c !== '' ? ' ' . $c : '');
            }
        }
        return $parts === [] ? 'no change' : implode('; ', $parts);
    }

    private static function value(mixed $v): string
    {
        return match (true) {
            $v === null || $v === '' => '(none)',
            is_bool($v) => $v ? 'on' : 'off',
            default => '"' . mb_substr((string) $v, 0, 80) . '"',
        };
    }
}
