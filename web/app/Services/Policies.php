<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * The platform's policies and notices, each with a version, and the record of who accepted which
 * version when. A notice explains; accepting it records that the person saw this version. It is
 * not a substitute for the lawful basis of processing, which for an institution's students is
 * the institution's responsibility (the data processing agreement says so).
 *
 * Changing a policy's text means giving it a new version: earlier acceptances stay recorded
 * against the version that was accepted.
 */
final class Policies
{
    public const POLICIES = [
        'institution_terms' => ['title' => 'Institutional terms of service', 'version' => '2026-11', 'points' => [
            'ExamLib provides the platform: accounts, question banks, assessments, sittings, results and verification. It keeps the service running, secure and backed up.',
            'The institution is responsible for what it does on the platform: who it enrols, what it assesses, the results it publishes and the certificates it issues.',
            'The representative who applies confirms they are authorised to act for the institution. ExamLib may ask for evidence and may refuse or suspend an institution that cannot show it.',
            'Approval confirms that ExamLib checked the dimensions shown as verified, and nothing more. It is not accreditation.',
            'Suspension stops the institution from working on the platform. It does not erase its examination records or the certificates it issued.',
        ]],
        'data_processing' => ['title' => 'Data processing agreement', 'version' => '2026-11', 'points' => [
            'For its students and staff, the institution decides why and how their information is processed; ExamLib processes it on the institution\'s behalf.',
            'The institution identifies the lawful basis for that processing, including any guardian involvement for students under 18, and tells its students what is collected.',
            'ExamLib keeps each institution\'s records apart from every other institution\'s, and its own staff do not read them except to keep the service working or when the law requires.',
            'A student\'s own ExamLib account stays theirs. Leaving an institution ends the institution\'s access to new activity; it does not delete the student\'s account.',
            'Records are kept while the institution uses the platform and as long as afterwards as examination and certificate records need to be verifiable.',
        ]],
        'exam_usage' => ['title' => 'Examination usage policy', 'version' => '2026-11', 'points' => [
            'Assessments are delivered under the conditions the institution sets: timing, attempts, monitoring and what candidates may see afterwards.',
            'Candidates are told the monitoring level before they start. ExamLib never records keystrokes, clipboard contents, camera or microphone.',
            'Integrity indicators are prompts for a person to review, never decisions. Conclusions are the institution\'s.',
        ]],
        'content_ownership' => ['title' => 'Question ownership and intellectual property', 'version' => '2026-11', 'points' => [
            'Questions an institution writes or imports remain the institution\'s. ExamLib does not share them with other institutions or use them elsewhere.',
            'The institution confirms it has the right to use what it uploads, and does not upload questions copied from protected examinations.',
            'Certification-library content belongs to its respective owners and is offered to members for study only.',
        ]],
        'certificate_rules' => ['title' => 'Certificate issuance rules', 'version' => '2026-11', 'points' => [
            'An institution issues certificates in its own name, only for achievements it assessed, and only through people it has authorised to approve them.',
            'Certificates show the institution\'s verification as it is: a registered institution is never presented as accredited unless accreditation was verified.',
            'An institution must not issue anything presented as a formal qualification unless it is authorised to award that qualification.',
            'Issued certificates are never silently changed. Corrections and revocations are recorded and stay visible to anyone verifying.',
        ]],
        'acceptable_use' => ['title' => 'Acceptable use policy', 'version' => '2026-11', 'points' => [
            'Do not use the platform to deceive candidates, employers or the public, or to impersonate another organisation.',
            'Do not try to reach another institution\'s records, test the platform\'s security without permission, or overload it.',
            'Report suspected misuse to ExamLib. Accounts and institutions that misuse the platform can be suspended.',
        ]],
        'membership_notice' => ['title' => 'Joining an institution', 'version' => '2026-11', 'points' => [
            'The institution sees your name, email address, the student record it keeps about you (such as your student number, programme and classes), and what you do in its assessments.',
            'It does not see your personal study in the library, your other institutions or anything you do elsewhere on ExamLib.',
            'You keep your ExamLib account if you leave or are withdrawn. Your results and certificates stay part of the institution\'s records.',
        ]],
        'exam_participation' => ['title' => 'Taking an assessment', 'version' => '2026-11', 'points' => [
            'Your answers, the times you saved them and your result are kept by the institution that set the assessment.',
            'The institution decides when results are published and what you may see afterwards.',
        ]],
        'exam_monitoring' => ['title' => 'Assessment monitoring', 'version' => '2026-11', 'points' => [
            'The monitoring level is shown before you start. Standard and enhanced monitoring record that the page was hidden, lost focus or went offline, and (enhanced) that copy, paste or leaving full screen happened.',
            'Keystrokes, clipboard contents, your camera and your microphone are never recorded.',
            'What is recorded helps the institution review a sitting; a person, not the system, reaches any conclusion.',
        ]],
        'credential_verification' => ['title' => 'Public certificate verification', 'version' => '2026-11', 'points' => [
            'Anyone with a certificate\'s verification link can see the holder\'s name, the achievement, the issuer, the dates and whether it still stands.',
            'They do not see your answers, scores beyond what the certificate states, contact details or monitoring records.',
        ]],
    ];

    /** What an institution's representative accepts when sending its application. */
    public const INSTITUTION = ['institution_terms', 'data_processing', 'exam_usage', 'content_ownership', 'certificate_rules', 'acceptable_use'];

    public function __construct(private Database $db)
    {
    }

    /** @return array{title:string,version:string,points:list<string>}|null */
    public static function get(string $key): ?array
    {
        return self::POLICIES[$key] ?? null;
    }

    /** Record that the person accepted the current version of each policy. @param list<string> $keys */
    public function accept(?int $userId, array $keys, string $context, ?int $contextId, ?string $ip = null): void
    {
        $now = Database::now();
        foreach ($keys as $k) {
            $p = self::POLICIES[$k] ?? null;
            if ($p !== null) {
                $this->db->run('INSERT INTO policy_acceptances (user_id, policy, version, context, context_id, ip, accepted_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
                    [$userId, $k, $p['version'], $context, $contextId, $ip, $now]);
            }
        }
    }

    /** Whether the current version of every policy was accepted in this context. @param list<string> $keys */
    public function acceptedAll(array $keys, string $context, int $contextId): bool
    {
        foreach ($keys as $k) {
            if ($this->db->value('SELECT 1 FROM policy_acceptances WHERE policy = ? AND version = ? AND context = ? AND context_id = ? LIMIT 1',
                [$k, self::POLICIES[$k]['version'], $context, $contextId]) === null) {
                return false;
            }
        }
        return true;
    }

    /** @return list<array<string,mixed>> acceptances in a context, newest first, with who accepted */
    public function acceptances(string $context, int $contextId): array
    {
        return $this->db->all('SELECT a.*, u.username, u.display_name FROM policy_acceptances a LEFT JOIN users u ON u.id = a.user_id
                               WHERE a.context = ? AND a.context_id = ? ORDER BY a.id DESC', [$context, $contextId]);
    }
}
