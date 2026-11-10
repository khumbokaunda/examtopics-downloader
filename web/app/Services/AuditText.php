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
            'exam.renamed' => [$who . ' renamed ' . $exam() . (($d['to'] ?? '') === '' ? ' back to the source\'s name' : ' to "' . $named('to') . '"')
                . (($d['edition'] ?? '') !== '' ? ', edition ' . $named('edition') : ''), 'pencil', 'neutral'],
            'exam.version_deleted' => [$who . ' deleted version ' . $named('version') . ' of ' . $exam(), 'trash3', 'danger'],
            'exam.editions_changed' => [$who . ' ' . match ((string) ($d['change'] ?? '')) {
                'separate' => 'gave ' . $exam() . ' a card of its own',
                'auto' => 'let ' . $exam() . ' be grouped with its editions by name again',
                'first' => 'made ' . $exam() . ' the edition shown first on its card',
                'join' => 'put ' . $exam() . ' on one card with ' . strtoupper((string) ($d['with'] ?? '')),
                default => 'changed how ' . $exam() . ' is grouped',
            }, 'collection', 'neutral'],
            'exam.progress_reset' => [$who . ' cleared their own progress in ' . $exam() . (isset($d['answers']) ? ' (' . (int) $d['answers'] . ' answer' . ((int) $d['answers'] === 1 ? '' : 's') . ')' : ''), 'arrow-counterclockwise', 'neutral'],
            'exam.images_queued' => [$who . ' started downloading the images of ' . $exam(), 'images', 'neutral'],
            'answers.saved' => [$who . ' ' . match ((string) ($d['kind'] ?? '')) {
                'corrected' => 'corrected the answer to question ' . (int) ($d['question'] ?? 0) . ' of ' . $exam() . ' to ' . $named('answer'),
                'checked' => 'marked the answer to question ' . (int) ($d['question'] ?? 0) . ' of ' . $exam() . ' as checked',
                default => 'added a note to question ' . (int) ($d['question'] ?? 0) . ' of ' . $exam(),
            }, 'pencil-square', 'primary'],
            'answers.removed' => [$who . ' removed the correction to question ' . (int) ($d['question'] ?? 0) . ' of ' . $exam(), 'pencil-square', 'neutral'],
            'questions.edited' => [$who . ' edited question ' . (int) ($d['question'] ?? 0) . ' of ' . $exam() . self::questionChanges($d), 'pencil', 'primary'],
            'answers.restored' => [$who . ' put back an earlier ' . (($d['kind'] ?? '') === 'question' ? 'edit of question ' : 'answer to question ')
                . (int) ($d['question'] ?? 0) . ' of ' . $exam(), 'arrow-counterclockwise', 'neutral'],
            'answers.carried' => [$who . ' carried ' . ((int) ($d['corrections'] ?? 0) === 1 ? 'a correction' : (int) ($d['corrections'] ?? 0) . ' corrections')
                . ' of ' . $exam() . ' to ' . implode(', ', array_map('strval', (array) ($d['editions'] ?? []))), 'collection', 'primary'],
            'questions.reported' => [$who . ' reported a problem with question ' . (int) ($d['question'] ?? 0) . ' of ' . $exam()
                . (isset($d['kind']) ? ': ' . strtolower(\App\Repositories\QuestionReportRepository::KINDS[(string) $d['kind']] ?? 'something else') : ''), 'flag', 'warning'],
            'questions.report_resolved' => [$who . ' dealt with ' . ((int) ($d['reports'] ?? 1) === 1 ? 'a report' : (int) $d['reports'] . ' reports')
                . ' on question ' . (int) ($d['question'] ?? 0) . ' of ' . $exam(), 'flag', 'neutral'],
            'discussion.posted' => [$who . ' posted in the discussion of question ' . (int) ($d['question'] ?? 0) . ' of ' . $exam()
                . (($d['answer'] ?? null) !== null ? ', voting ' . $d['answer'] : ''), 'people', 'neutral'],
            'discussion.deleted' => [$who . ' deleted ' . (($d['author'] ?? null) !== null ? $d['author'] . '\'s' : 'their') . ' comment on question '
                . (int) ($d['question'] ?? 0) . ' of ' . $exam(), 'people', 'neutral'],
            'questions.reset' => [$who . ' put question ' . (int) ($d['question'] ?? 0) . ' of ' . $exam() . ' back as collected', 'pencil', 'neutral'],
            'document.build_queued' => [$who . ' built a ' . $named('format') . ' of ' . $exam() . (isset($d['variant']) ? ': ' . $d['variant'] : ''), 'file-earmark-text', 'neutral'],
            'document.deleted' => [$who . ' deleted a ' . $named('format') . ' of ' . $exam(), 'trash3', 'warning'],
            'request.created' => [$who . ' requested ' . $exam(), 'inbox', 'primary'],
            'request.cancelled' => [$who . ' cancelled their request for ' . $exam(), 'inbox', 'neutral'],
            'request.answered' => [$who . ' marked the request by ' . $named('requester') . ' for ' . $exam() . ' as ' . $named('status'), 'inbox', 'neutral'],
            'institution.registered' => [$who . ' registered the institution ' . $named('institution'), 'building-add', 'primary'],
            'institution.updated' => [$who . ' changed the profile of ' . $named('institution') . ': ' . self::fields($d['fields'] ?? []), 'building-gear', 'neutral'],
            'institution.invitation_resent' => [$who . ' sent the invitation of ' . $named('email') . ' to ' . $named('institution') . ' again', 'envelope-arrow-up', 'neutral'],
            'institution.enrolment_code_created' => [$who . ' made the enrolment code ' . $named('code') . ' for ' . $named('institution')
                . (!empty($d['approval']) ? ' (each request approved)' : ' (joins at once)'), 'key', 'primary'],
            'institution.enrolment_code_revoked' => [$who . ' withdrew the enrolment code ' . $named('code') . ' of ' . $named('institution'), 'key', 'neutral'],
            'institution.enrolment_requested' => [$who . ' asked to join ' . $named('institution') . ' with the code ' . $named('code'), 'person-plus', 'neutral'],
            'institution.enrolment_approved' => [$who . ' approved the request of ' . $named('student') . ' to join ' . $named('institution'), 'person-check', 'success'],
            'institution.enrolment_declined' => [$who . ' declined the request of ' . $named('student') . ' to join ' . $named('institution'), 'person-x', 'warning'],
            'institution.joined_with_code' => [$who . ' joined ' . $named('institution') . ' as a student with the code ' . $named('code'), 'person-check', 'primary'],
            'institution.programme_details_saved' => [$who . ' changed the details of the programme ' . $named('programme') . ' in ' . $named('institution'), 'journal-text', 'neutral'],
            'institution.module_saved' => [$who . ' saved the module ' . $named('module') . ' of ' . $named('programme') . ' in ' . $named('institution'), 'list-ol', 'neutral'],
            'institution.module_retired' => [$who . ' retired the module ' . $named('module') . ' in ' . $named('institution'), 'list-ol', 'neutral'],
            'institution.module_restored' => [$who . ' offered the module ' . $named('module') . ' again in ' . $named('institution'), 'list-ol', 'neutral'],
            'institution.module_completion_recorded' => [$who . ' recorded that ' . $named('student') . ' completed ' . $named('module') . ' in ' . $named('institution'), 'check2-circle', 'neutral'],
            'institution.programme_completed' => [$who . ' recorded that ' . $named('student') . ' completed ' . $named('programme') . ' in ' . $named('institution')
                . (!empty($d['override']) ? ' (not every required module was completed)' : ''), 'mortarboard', 'success'],
            'institution.transcript_issued' => [$who . ' issued a transcript to ' . $named('student') . ' in ' . $named('institution') . (!empty($d['provisional']) ? ' (with provisional results)' : ''), 'file-earmark-text', 'primary'],
            'institution.transcript_withdrawn' => [$who . ' withdrew transcript ' . $named('number') . ' in ' . $named('institution'), 'file-earmark-x', 'warning'],
            'institution.guardian_saved' => [$who . ' saved the under-18 details of ' . $named('student') . ' in ' . $named('institution'), 'shield-check', 'neutral'],
            'institution.signatory_set' => [$who . (!empty($d['active']) ? ' authorised ' . $named('person') . ' (' . $named('position') . ') to sign credentials at '
                : ' stopped ' . $named('person') . ' signing credentials at ') . $named('institution'), 'pen', 'warning'],
            'institution.credential_template_saved' => [$who . ' saved the credential template ' . $named('template') . ' of ' . $named('institution'), 'award', 'neutral'],
            'institution.credentials_requested' => [$who . ' issued ' . (int) ($d['issued'] ?? 0) . ' and asked approval for ' . (int) ($d['pending'] ?? 0) . ' of ' . $named('template')
                . ' at ' . $named('institution'), 'award', 'primary'],
            'institution.credential_issued' => [$who . ' signed and issued ' . $named('number') . ' to ' . $named('holder') . ' at ' . $named('institution'), 'patch-check', 'success'],
            'institution.credential_declined' => [$who . ' declined ' . $named('number') . ' at ' . $named('institution'), 'x-circle', 'neutral'],
            'institution.credential_revoked' => [$who . ' revoked ' . $named('number') . ' at ' . $named('institution') . ': ' . $named('reason'), 'x-octagon', 'danger'],
            'institution.credential_corrected' => [$who . ' replaced ' . $named('number') . ' with ' . $named('replacement') . ' at ' . $named('institution') . ': ' . $named('reason'), 'arrow-repeat', 'warning'],
            'institution.logo_changed' => [$who . ' changed the logo of ' . $named('institution'), 'image', 'neutral'],
            'institution.awarding_body_set' => [$who . (($d['body'] ?? '') !== '' ? ' recorded ' . $named('institution') . ' as an awarding body for ' . $named('body')
                : ' recorded that ' . $named('institution') . ' may not award qualifications'), 'mortarboard', 'warning'],
            'institution.deleted' => [$who . ' deleted ' . $named('institution') . ': ' . $named('reason'), 'trash', 'danger'],
            'institution.restored' => [$who . ' restored ' . $named('institution'), 'arrow-counterclockwise', 'warning'],
            'institution.purged' => [$who . ' removed ' . $named('institution') . ' for good', 'trash', 'danger'],
            'platform.requirement_saved' => [$who . ' saved the institution ' . ((($d['kind'] ?? '') === 'document') ? 'document ' : 'field ') . $named('label'), 'list-check', 'neutral'],
            'platform.requirement_toggled' => [$who . (!empty($d['active']) ? ' switched on ' : ' switched off ') . $named('label') . ' for institutions', 'toggles', 'neutral'],
            'institution.submitted' => [$who . (($d['from'] ?? '') === 'draft' ? ' sent the application of ' : ' sent again the application of ') . $named('institution'), 'send', 'primary'],
            'institution.document_added' => [$who . ' added a document to the application of ' . $named('institution') . ': '
                . strtolower(\App\Services\Onboarding::DOCUMENTS[(string) ($d['kind'] ?? '')] ?? (string) ($d['kind'] ?? '?')), 'file-earmark-plus', 'neutral'],
            'institution.document_removed' => [$who . ' removed a document from the application of ' . $named('institution') . ': '
                . strtolower(\App\Services\Onboarding::DOCUMENTS[(string) ($d['kind'] ?? '')] ?? (string) ($d['kind'] ?? '?')), 'file-earmark-minus', 'neutral'],
            'institution.verification_changed' => [$who . ' set ' . strtolower(\App\Services\Onboarding::DIMENSIONS[(string) ($d['dimension'] ?? '')]['label'] ?? '?')
                . ' of ' . $named('institution') . ' to ' . strtolower(\App\Services\Onboarding::DIMENSIONS[(string) ($d['dimension'] ?? '')]['states'][(string) ($d['to'] ?? '')] ?? '?'),
                'shield-check', ($d['to'] ?? '') === 'verified' ? 'success' : 'neutral'],
            'institution.resubmitted' => [$who . ' sent ' . $named('institution') . ' for verification again', 'building-up', 'neutral'],
            'institution.status_changed' => [$who . ' ' . match ((string) ($d['to'] ?? '')) {
                'verified' => (($d['from'] ?? '') === 'suspended' ? 'reinstated ' : 'approved ') . $named('institution'),
                'rejected' => 'did not approve ' . $named('institution'),
                'under_review' => 'started reviewing ' . $named('institution'),
                'info_requested' => 'asked ' . $named('institution') . ' for more information',
                'suspended' => 'suspended ' . $named('institution'),
                default => 'changed the status of ' . $named('institution'),
            }, 'building-check', ($d['to'] ?? '') === 'verified' ? 'success' : 'warning'],
            'institution.member_invited' => [$who . ' invited ' . $named('email') . ' to ' . $named('institution') . ' as '
                . InstitutionAccess::roleName((string) ($d['role'] ?? '')), 'envelope-plus', 'primary'],
            'institution.invitation_revoked' => [$who . ' withdrew the invitation of ' . $named('email') . ' to ' . $named('institution'), 'envelope-x', 'neutral'],
            'institution.invitation_accepted' => [$who . ' joined ' . $named('institution') . ' as ' . InstitutionAccess::roleName((string) ($d['role'] ?? '')), 'person-check', 'success'],
            'institution.invitation_declined' => [$who . ' declined an invitation to ' . $named('institution'), 'envelope-x', 'neutral'],
            'institution.member_role_changed' => [$who . ' made ' . $named('username') . ' ' . InstitutionAccess::roleName((string) ($d['to'] ?? ''))
                . ' in ' . $named('institution') . (((array) ($d['permissions'] ?? [])) !== [] ? ', with ' . implode(', ', array_map('strval', (array) $d['permissions'])) : ''), 'person-gear', 'warning'],
            'institution.member_removed' => [$who . ' removed ' . $named('username') . ' from ' . $named('institution'), 'person-dash', 'warning'],
            'institution.department_added' => [$who . ' added the department ' . $named('name') . ' to ' . $named('institution'), 'diagram-3', 'neutral'],
            'institution.department_renamed' => [$who . ' renamed the department ' . $named('from') . ' to ' . $named('name') . ' in ' . $named('institution'), 'diagram-3', 'neutral'],
            'institution.department_removed' => [$who . ' removed the department ' . $named('name') . ' from ' . $named('institution'), 'diagram-3', 'warning'],
            'institution.programme_added' => [$who . ' added the programme ' . $named('name') . ' to ' . $named('institution'), 'journal-text', 'neutral'],
            'institution.programme_changed' => [$who . ' changed the programme ' . $named('name') . ' in ' . $named('institution'), 'journal-text', 'neutral'],
            'institution.programme_removed' => [$who . ' removed the programme ' . $named('name') . ' from ' . $named('institution'), 'journal-text', 'warning'],
            'institution.class_created' => [$who . ' created the class ' . $named('class') . ' in ' . $named('institution'), 'collection', 'primary'],
            'institution.class_changed' => [$who . ' changed the class ' . $named('class') . ' in ' . $named('institution'), 'collection', 'neutral'],
            'institution.class_archived' => [$who . ' archived the class ' . $named('class') . ' in ' . $named('institution'), 'archive', 'neutral'],
            'institution.class_restored' => [$who . ' restored the class ' . $named('class') . ' in ' . $named('institution'), 'collection', 'neutral'],
            'institution.students_enrolled' => [$who . ' put ' . ((int) ($d['count'] ?? 0) === 1 ? 'a student' : (int) ($d['count'] ?? 0) . ' students') . ' in ' . $named('class'), 'person-plus', 'primary'],
            'institution.student_unenrolled' => [$who . ' took ' . $named('student') . ' out of ' . $named('class'), 'person-dash', 'warning'],
            'institution.student_moved' => [$who . ' moved ' . $named('student') . ' from ' . $named('from') . ' to ' . $named('to'), 'arrow-left-right', 'neutral'],
            'institution.instructors_set' => [$who . ' set the instructors of ' . $named('class') . ': '
                . (((array) ($d['instructors'] ?? [])) !== [] ? implode(', ', array_map('strval', (array) $d['instructors'])) : 'none'), 'person-badge', 'neutral'],
            'institution.student_record_saved' => [$who . ' updated the student record of ' . $named('student') . ' in ' . $named('institution'), 'person-vcard', 'neutral'],
            'institution.students_imported' => [$who . ' imported students into ' . $named('institution') . ': ' . (int) ($d['enrolled'] ?? 0) . ' updated, '
                . (int) ($d['invited'] ?? 0) . ' invited, ' . (int) ($d['failed'] ?? 0) . ' not imported', 'filetype-csv', 'primary'],
            'institution.bank_created' => [$who . ' created the question bank ' . $named('bank') . ' in ' . $named('institution'), 'database-add', 'primary'],
            'institution.bank_changed' => [$who . ' changed the question bank ' . $named('bank'), 'database-gear', 'neutral'],
            'institution.bank_archived' => [$who . ' archived the question bank ' . $named('bank'), 'archive', 'neutral'],
            'institution.bank_restored' => [$who . ' restored the question bank ' . $named('bank'), 'database', 'neutral'],
            'institution.bank_editors_set' => [$who . ' set who may write in ' . $named('bank') . ': '
                . (((array) ($d['editors'] ?? [])) !== [] ? implode(', ', array_map('strval', (array) $d['editors'])) : 'only its creator'), 'people', 'neutral'],
            'institution.topic_added' => [$who . ' added the topic ' . $named('name') . ' to ' . $named('bank'), 'tag', 'neutral'],
            'institution.topic_renamed' => [$who . ' renamed the topic ' . $named('from') . ' to ' . $named('name') . ' in ' . $named('bank'), 'tag', 'neutral'],
            'institution.topic_removed' => [$who . ' removed the topic ' . $named('name') . ' from ' . $named('bank'), 'tag', 'warning'],
            'institution.question_created' => [$who . ' wrote a question in ' . $named('bank') . ': ' . $named('question'), 'plus-square', 'primary'],
            'institution.question_edited' => [$who . ' edited a question in ' . $named('bank') . ' (version ' . (int) ($d['version'] ?? 0) . '): ' . $named('question'), 'pencil-square', 'neutral'],
            'institution.question_submitted' => [$who . ' sent a question in ' . $named('bank') . ' for review: ' . $named('question'), 'send', 'neutral'],
            'institution.question_approved' => [$who . ' approved version ' . (int) ($d['version'] ?? 0) . ' of a question in ' . $named('bank') . ': ' . $named('question'), 'check2-circle', 'success'],
            'institution.question_published' => [$who . ' published version ' . (int) ($d['version'] ?? 0) . ' of a question in ' . $named('bank') . ': ' . $named('question'), 'patch-check', 'success'],
            'institution.question_returned' => [$who . ' sent a question in ' . $named('bank') . ' back for changes: ' . $named('question')
                . (isset($d['note']) ? ' ("' . $d['note'] . '")' : ''), 'arrow-return-left', 'warning'],
            'institution.question_archived' => [$who . ' archived a question in ' . $named('bank') . ': ' . $named('question'), 'archive', 'neutral'],
            'institution.question_restored' => [$who . ' restored a question in ' . $named('bank') . ': ' . $named('question'), 'arrow-counterclockwise', 'neutral'],
            'institution.course_created' => [$who . ' started the course ' . $named('course') . ' in ' . $named('institution'), 'journal-text', 'primary'],
            'institution.course_changed' => [$who . ' changed the course ' . $named('course') . ' in ' . $named('institution'), 'journal-text', 'neutral'],
            'institution.course_archived' => [$who . ' archived the course ' . $named('course') . ' in ' . $named('institution'), 'archive', 'neutral'],
            'institution.course_restored' => [$who . ' restored the course ' . $named('course') . ' in ' . $named('institution'), 'journal-text', 'neutral'],
            'institution.course_assessment_added' => [$who . ' put the assessment ' . $named('assessment') . ' in the course ' . $named('course') . ' in ' . $named('institution'), 'clipboard-plus', 'neutral'],
            'institution.course_assessment_removed' => [$who . ' took the assessment ' . $named('assessment') . ' out of its course in ' . $named('institution'), 'clipboard-minus', 'neutral'],
            'institution.course_notice_posted' => [$who . ' posted the notice ' . $named('title') . ' to the course ' . $named('course') . ' in ' . $named('institution'), 'megaphone', 'neutral'],
            'institution.course_notice_removed' => [$who . ' removed the notice ' . $named('title') . ' from the course ' . $named('course') . ' in ' . $named('institution'), 'megaphone', 'warning'],
            'institution.grade_item_added' => [$who . ' added ' . $named('item') . ' to the gradebook of ' . $named('course') . ' in ' . $named('institution'), 'journal-plus', 'neutral'],
            'institution.grade_item_changed' => [$who . ' changed ' . $named('item') . ' in the gradebook of ' . $named('course') . ' in ' . $named('institution'), 'journal-text', 'neutral'],
            'institution.grade_item_removed' => [$who . ' took ' . $named('item') . ' out of the gradebook of ' . $named('course') . ' in ' . $named('institution'), 'journal-minus', 'warning'],
            'institution.grade_marks_entered' => [$who . ' ' . (($d['how'] ?? '') === 'uploaded' ? 'uploaded' : 'entered') . ' ' . (int) ($d['count'] ?? 0) . ' marks for ' . $named('item')
                . ' in ' . $named('course') . ' in ' . $named('institution'), 'pencil-square', 'neutral'],
            'institution.grade_marks_released' => [$who . ' released the marks for ' . $named('item') . ' in ' . $named('course') . ' in ' . $named('institution'), 'send', 'primary'],
            'institution.grade_override_set' => [$who . ' set the grade of ' . $named('student') . ' in ' . $named('course') . ' to ' . $named('percent') . '%: ' . $named('reason'), 'pencil', 'warning'],
            'institution.grade_override_removed' => [$who . ' took away the grade set by hand for ' . $named('student') . ' in ' . $named('course') . ': ' . $named('reason'), 'pencil', 'warning'],
            'institution.grades_finalised' => [$who . ' made the grades of ' . $named('course') . ' final in ' . $named('institution'), 'lock', 'primary'],
            'institution.grades_reopened' => [$who . ' made the grades of ' . $named('course') . ' provisional again in ' . $named('institution'), 'unlock', 'warning'],
            'institution.material_added' => [$who . ' added ' . $named('title') . ' to the materials of ' . $named('course') . ' in ' . $named('institution'), 'folder-plus', 'neutral'],
            'institution.material_removed' => [$who . ' removed ' . $named('title') . ' from the materials of ' . $named('course') . ' in ' . $named('institution'), 'folder-minus', 'warning'],
            'institution.assignment_created' => [$who . ' set the assignment ' . $named('assignment') . ' in ' . $named('course') . ' in ' . $named('institution'), 'file-earmark-plus', 'primary'],
            'institution.assignment_changed' => [$who . ' changed the assignment ' . $named('assignment') . ' in ' . $named('course') . ' in ' . $named('institution'), 'file-earmark-text', 'neutral'],
            'institution.assignment_opened' => [$who . ' opened the assignment ' . $named('assignment') . ' to the learners of ' . $named('course'), 'send', 'primary'],
            'institution.assignment_closed' => [$who . ' closed the assignment ' . $named('assignment') . ' in ' . $named('course'), 'lock', 'neutral'],
            'institution.assignment_rubric_set' => [$who . ' set the rubric of ' . $named('assignment') . ' in ' . $named('course'), 'ui-checks-grid', 'neutral'],
            'institution.assignment_submitted' => [$who . ' handed in ' . $named('assignment') . ' in ' . $named('course') . (!empty($d['late']) ? ', late' : ''), 'file-earmark-arrow-up', 'neutral'],
            'institution.assignment_marked' => [$who . ' marked the work of ' . $named('student') . ' for ' . $named('assignment') . ' in ' . $named('course'), 'check2-square', 'neutral'],
            'institution.assignment_released' => [$who . ' released the marks for ' . $named('assignment') . ' in ' . $named('course'), 'megaphone', 'primary'],
            'sitting.marked' => [$who . ' marked ' . (int) ($d['answers'] ?? 0) . ' written answer' . ((int) ($d['answers'] ?? 0) === 1 ? '' : 's') . ' of ' . $named('student') . ' in ' . $named('assessment')
                . (!empty($d['complete']) ? ', completing the sitting' : ''), 'check2-square', 'neutral'],
            'institution.assessment_duplicated' => [$who . ' copied the assessment ' . $named('assessment') . ' as ' . $named('copy') . ' in ' . $named('institution'), 'copy', 'neutral'],
            'institution.questions_imported' => [$who . ' imported ' . (int) ($d['count'] ?? 0) . ' questions into ' . $named('bank') . ' in ' . $named('institution'), 'upload', 'primary'],
            'institution.assessment_created' => [$who . ' created the assessment ' . $named('assessment') . ' in ' . $named('institution'), 'clipboard-plus', 'primary'],
            'institution.assessment_changed' => [$who . ' changed the settings of the assessment ' . $named('assessment'), 'clipboard', 'neutral'],
            'institution.assessment_rescheduled' => [$who . ' changed when the assessment ' . $named('assessment') . ' opens and closes', 'calendar-event', 'neutral'],
            'institution.assessment_questions_added' => [$who . ' added ' . ((int) ($d['count'] ?? 0) === 1 ? 'a question' : (int) ($d['count'] ?? 0) . ' questions') . ' from '
                . $named('bank') . ' to the assessment ' . $named('assessment'), 'clipboard-plus', 'neutral'],
            'institution.assessment_blueprint_added' => [$who . ' had the assessment ' . $named('assessment') . ' draw ' . (int) ($d['count'] ?? 0) . ' questions from ' . $named('bank'), 'shuffle', 'neutral'],
            'institution.assessment_assigned' => [$who . ' assigned the assessment ' . $named('assessment') . ' to ' . (string) ($d['class'] ?? $d['student'] ?? '?'), 'person-check', 'primary'],
            'institution.assessment_unassigned' => [$who . ' took an assignment off the assessment ' . $named('assessment'), 'person-dash', 'neutral'],
            'institution.assessment_published' => [$who . ' published the assessment ' . $named('assessment'), 'clipboard-check', 'success'],
            'institution.assessment_unpublished' => [$who . ' took the assessment ' . $named('assessment') . ' back to a draft', 'clipboard-x', 'warning'],
            'institution.assessment_closed' => [$who . ' closed the assessment ' . $named('assessment'), 'lock', 'neutral'],
            'institution.assessment_reopened' => [$who . ' reopened the assessment ' . $named('assessment'), 'unlock', 'neutral'],
            'institution.assessment_archived' => [$who . ' archived the assessment ' . $named('assessment'), 'archive', 'neutral'],
            'institution.assessment_restored' => [$who . ' restored the assessment ' . $named('assessment') . ' as a draft', 'arrow-counterclockwise', 'neutral'],
            'sitting.started' => [$who . ' started attempt ' . (int) ($d['attempt'] ?? 1) . ' of the assessment ' . $named('assessment'), 'play-circle', 'neutral'],
            'sitting.submitted' => [$who . ' handed in the assessment ' . $named('assessment'), 'check2-square', 'success'],
            'sitting.auto_submitted' => ['The assessment ' . $named('assessment') . ' of ' . $named('student') . ' was handed in when its time ran out', 'stopwatch', 'neutral'],
            'institution.results_published' => [$who . ' published the results of ' . $named('assessment') . ' to ' . (int) ($d['candidates'] ?? 0) . ' candidates', 'megaphone', 'success'],
            'institution.results_unpublished' => [$who . ' took back the results of ' . $named('assessment'), 'eye-slash', 'warning'],
            'institution.results_exported' => [$who . ' downloaded the results of ' . $named('assessment'), 'download', 'neutral'],
            'certificate.issued' => [$who . ' earned the readiness certificate ' . $named('number') . ' for ' . $named('exam'), 'award', 'success'],
            'certificate.revoked' => [$who . ' revoked the certificate ' . $named('number') . ' of ' . $named('holder'), 'award', 'danger'],
            'institution.integrity_reviewed' => [$who . ' recorded an integrity conclusion for ' . $named('student') . ': '
                . strtolower(Integrity::OUTCOMES[(string) ($d['outcome'] ?? '')] ?? '?'), 'shield-check', 'neutral'],
            'institution.invigilators_set' => [$who . ' set the invigilators of ' . $named('assessment') . ': '
                . (((array) ($d['invigilators'] ?? [])) !== [] ? implode(', ', array_map('strval', (array) $d['invigilators'])) : 'none'), 'eye', 'neutral'],
            'institution.observation_recorded' => [$who . ' recorded an observation about ' . $named('student') . ': ' . strtolower(Invigilation::CATEGORIES[(string) ($d['category'] ?? '')] ?? '?'), 'eye', 'neutral'],
            'institution.message_sent' => [isset($d['student']) ? $who . ' sent a message to ' . $named('student') . ' in ' . $named('assessment')
                : $who . ' sent a message to everyone writing ' . $named('assessment') . ' (' . (int) ($d['count'] ?? 0) . ')', 'chat-left-text', 'neutral'],
            'institution.sitting_paused' => [$who . ' paused the sitting of ' . $named('student') . ' in ' . $named('assessment'), 'pause-circle', 'warning'],
            'institution.sitting_resumed' => [$who . ' resumed the sitting of ' . $named('student') . ' in ' . $named('assessment'), 'play-circle', 'neutral'],
            'institution.sitting_extended' => [$who . ' gave ' . $named('student') . ' ' . (int) ($d['minutes'] ?? 0) . ' extra minutes in ' . $named('assessment') . ': ' . $named('reason'), 'stopwatch', 'warning'],
            'institution.sitting_ended' => [$who . ' ended the sitting of ' . $named('student') . ' in ' . $named('assessment') . ': ' . $named('reason'), 'stop-circle', 'danger'],
            'sitting.terminated' => ['The sitting of ' . $named('student') . ' in ' . $named('assessment') . ' was ended by the institution', 'stop-circle', 'danger'],
            'institution.member_left' => [$who . ' left ' . $named('institution'), 'box-arrow-left', 'neutral'],
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

    /** @param array<string,mixed> $d what a question edit changed, as saved in its details */
    private static function questionChanges(array $d): string
    {
        $changed = array_values(array_filter(array_map('strval', (array) ($d['changed'] ?? []))));
        return $changed === [] ? '' : ' (' . implode(', ', $changed) . ')';
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
