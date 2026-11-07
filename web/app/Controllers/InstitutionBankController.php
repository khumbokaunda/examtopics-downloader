<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Http\HttpException;
use App\Core\Http\Response;
use App\Services\InstitutionAccess;
use App\Services\MediaStore;
use App\Services\QuestionBanks;
use RuntimeException;

/**
 * An institution's question banks: the banks, one bank with its topics and questions, the
 * question editor, a question with its versions, and the review queue. Answers are shown only
 * here, to staff who may see the bank; nothing on these pages is open to students.
 */
final class InstitutionBankController extends TenantController
{
    private const BANK_PERMISSIONS = ['question_banks.view', 'question_banks.create', 'question_banks.edit', 'question_banks.publish'];

    public function index(string $ref): Response
    {
        [$i, $m] = $this->tenant($ref, null);
        if (!$this->anyBankPermission($m)) {
            throw new HttpException(403, 'Your role in ' . $i['name'] . ' does not include question banks.');
        }
        $all = InstitutionAccess::can($m, 'question_banks.view') || InstitutionAccess::can($m, 'question_banks.edit') || InstitutionAccess::can($m, 'question_banks.publish');
        $archived = $this->request->query('status') === 'archived';
        $repo = $this->s->questionBanks()->repo();
        return $this->render('institutions/banks', $this->pageVars($i, $m, 'banks') + [
            'banks' => $repo->banks((int) $i['id'], $archived ? 'archived' : 'active', $all ? null : (int) $m['id']),
            'all' => $all, 'archived' => $archived,
            'canCreate' => InstitutionAccess::can($m, 'question_banks.create') || InstitutionAccess::can($m, 'question_banks.edit'),
            'inReview' => InstitutionAccess::can($m, 'question_banks.publish') ? $repo->countInReview((int) $i['id']) : null,
            'programmes' => $this->s->structure()->repo()->programmes((int) $i['id']),
        ]);
    }

    public function create(string $ref): Response
    {
        [$i, $m] = $this->tenant($ref, null);
        if (!InstitutionAccess::can($m, 'question_banks.create') && !InstitutionAccess::can($m, 'question_banks.edit')) {
            throw new HttpException(403, 'Your role in ' . $i['name'] . ' does not include creating question banks.');
        }
        try {
            $uuid = $this->s->questionBanks()->saveBank($i, $m, null, (string) $this->request->input('name', ''), (string) $this->request->input('subject', ''),
                (string) $this->request->input('description', ''), (string) $this->request->input('programme', ''));
            $this->flash('success', 'Question bank created. Add its topics, then its questions.');
            return $this->redirect('/institutions/' . $i['uuid'] . '/banks/' . $uuid);
        } catch (RuntimeException $e) {
            $this->flash('warning', $e->getMessage());
            return $this->redirect('/institutions/' . $i['uuid'] . '/banks');
        }
    }

    public function show(string $ref, string $bank): Response
    {
        [$i, $m, $b] = $this->bankFor($ref, $bank, 'see');
        $banks = $this->s->questionBanks();
        $iid = (int) $i['id'];
        $topics = $banks->repo()->topics($iid, (int) $b['id']);
        $filters = ['status' => (string) $this->request->query('status', ''), 'difficulty' => (string) $this->request->query('difficulty', ''),
            'type' => (string) $this->request->query('type', ''), 'q' => mb_substr((string) $this->request->query('q', ''), 0, 100), 'topic' => 0];
        $filters['status'] = isset(QuestionBanks::STATUSES[$filters['status']]) ? $filters['status'] : '';
        $filters['difficulty'] = isset(QuestionBanks::DIFFICULTIES[$filters['difficulty']]) ? $filters['difficulty'] : '';
        $filters['type'] = isset(QuestionBanks::TYPES[$filters['type']]) ? $filters['type'] : '';
        $topicUuid = (string) $this->request->query('topic', '');
        $topic = $topicUuid !== '' ? $banks->repo()->topic($iid, (int) $b['id'], $topicUuid) : null;
        $filters['topic'] = $topic !== null ? (int) $topic['id'] : 0;
        $manage = $banks->canManage($m, $b);
        return $this->render('institutions/bank', $this->pageVars($i, $m, 'banks') + [
            'bank' => $b, 'topics' => $topics, 'questions' => $banks->repo()->questions($iid, (int) $b['id'], $filters),
            'filters' => $filters + ['topic_uuid' => $topic['uuid'] ?? ''], 'canWrite' => $banks->canWrite($m, $b), 'manage' => $manage,
            'editors' => $banks->repo()->editors($iid, (int) $b['id']),
            'writers' => $manage ? array_values(array_filter($this->s->institutions()->repo()->members($iid), static fn(array $p): bool =>
                (int) $p['id'] !== (int) ($b['created_by'] ?? 0) && (InstitutionAccess::can($p, 'question_banks.create') || InstitutionAccess::can($p, 'question_banks.edit')))) : [],
            'programmes' => $manage ? $this->s->structure()->repo()->programmes($iid) : [],
        ]);
    }

    public function update(string $ref, string $bank): Response
    {
        [$i, $m, $b] = $this->bankFor($ref, $bank, 'manage');
        return $this->attempt($i, $b, fn() => $this->s->questionBanks()->saveBank($i, $m, $b, (string) $this->request->input('name', ''),
            (string) $this->request->input('subject', ''), (string) $this->request->input('description', ''), (string) $this->request->input('programme', '')), 'Bank saved.');
    }

    public function archive(string $ref, string $bank): Response
    {
        [$i, , $b] = $this->bankFor($ref, $bank, 'manage');
        $archive = $b['status'] === 'active';
        return $this->attempt($i, $b, fn() => $this->s->questionBanks()->setArchived($i, $b, $archive),
            $archive ? 'Bank archived. Its questions are kept; it takes no new ones.' : 'Bank restored.');
    }

    public function editors(string $ref, string $bank): Response
    {
        [$i, , $b] = $this->bankFor($ref, $bank, 'manage');
        $people = [];
        foreach ((array) ($this->request->post['editors'] ?? []) as $uuid) {
            $people[] = (is_string($uuid) ? $this->s->institutions()->repo()->member((int) $i['id'], $uuid) : null) ?? throw HttpException::notFound('Member');
        }
        return $this->attempt($i, $b, fn() => $this->s->questionBanks()->setEditors($i, $b, $people), 'Saved who may write in this bank.');
    }

    public function addTopic(string $ref, string $bank): Response
    {
        [$i, , $b] = $this->bankFor($ref, $bank, 'manage');
        return $this->attempt($i, $b, fn() => $this->s->questionBanks()->addTopic($i, $b, (string) $this->request->input('name', ''),
            (string) $this->request->input('parent', '')), 'Topic added.');
    }

    public function renameTopic(string $ref, string $bank, string $topic): Response
    {
        [$i, , $b] = $this->bankFor($ref, $bank, 'manage');
        $t = $this->s->questionBanks()->repo()->topic((int) $i['id'], (int) $b['id'], $topic) ?? throw HttpException::notFound('Topic');
        return $this->attempt($i, $b, fn() => $this->s->questionBanks()->renameTopic($i, $b, $t, (string) $this->request->input('name', '')), 'Topic renamed.');
    }

    public function deleteTopic(string $ref, string $bank, string $topic): Response
    {
        [$i, , $b] = $this->bankFor($ref, $bank, 'manage');
        $t = $this->s->questionBanks()->repo()->topic((int) $i['id'], (int) $b['id'], $topic) ?? throw HttpException::notFound('Topic');
        return $this->attempt($i, $b, fn() => $this->s->questionBanks()->deleteTopic($i, $b, $t), 'Topic removed.');
    }

    public function newQuestion(string $ref, string $bank): Response
    {
        [$i, $m, $b] = $this->bankFor($ref, $bank, 'write');
        return $this->editor($i, $m, $b, null, ['type' => 'single', 'text' => '', 'choices' => [], 'marks' => '1.00', 'partial_credit' => false,
            'difficulty' => 'medium', 'topic_id' => null, 'explanation' => null, 'reference' => null, 'images' => []], []);
    }

    public function createQuestion(string $ref, string $bank): Response
    {
        [$i, $m, $b] = $this->bankFor($ref, $bank, 'write');
        return $this->saveFromForm($i, $m, $b, null);
    }

    public function question(string $ref, string $bank, string $question): Response
    {
        [$i, $m, $b] = $this->bankFor($ref, $bank, 'see');
        $banks = $this->s->questionBanks();
        $q = $banks->repo()->question((int) $i['id'], (int) $b['id'], $question) ?? throw HttpException::notFound('Question');
        return $this->render('institutions/question', $this->pageVars($i, $m, 'banks') + [
            'bank' => $b, 'question' => $q, 'versions' => $banks->repo()->versions((int) $i['id'], (int) $q['id']),
            'canWrite' => $banks->canWrite($m, $b), 'publisher' => InstitutionAccess::can($m, 'question_banks.publish'),
            'shown' => null,
        ]);
    }

    public function version(string $ref, string $bank, string $question, string $number): Response
    {
        [$i, $m, $b] = $this->bankFor($ref, $bank, 'see');
        $banks = $this->s->questionBanks();
        $q = $banks->repo()->question((int) $i['id'], (int) $b['id'], $question) ?? throw HttpException::notFound('Question');
        $v = ctype_digit($number) ? $banks->repo()->version((int) $i['id'], (int) $q['id'], (int) $number) : null;
        if ($v === null) {
            throw HttpException::notFound('Version');
        }
        return $this->render('institutions/question', $this->pageVars($i, $m, 'banks') + [
            'bank' => $b, 'question' => $q, 'versions' => $banks->repo()->versions((int) $i['id'], (int) $q['id']),
            'canWrite' => $banks->canWrite($m, $b), 'publisher' => InstitutionAccess::can($m, 'question_banks.publish'), 'shown' => $v,
        ]);
    }

    public function editQuestion(string $ref, string $bank, string $question): Response
    {
        [$i, $m, $b] = $this->bankFor($ref, $bank, 'write');
        $q = $this->s->questionBanks()->repo()->question((int) $i['id'], (int) $b['id'], $question) ?? throw HttpException::notFound('Question');
        return $this->editor($i, $m, $b, $q, [
            'type' => $q['type'], 'text' => $q['text'], 'choices' => (array) json_decode((string) $q['choices'], true), 'marks' => (string) $q['marks'],
            'partial_credit' => (bool) $q['partial_credit'], 'difficulty' => $q['difficulty'], 'topic_id' => $q['topic_id'] !== null ? (int) $q['topic_id'] : null,
            'explanation' => $q['explanation'], 'reference' => $q['reference'], 'images' => (array) (json_decode((string) ($q['images'] ?? ''), true) ?: []),
        ], []);
    }

    public function saveQuestion(string $ref, string $bank, string $question): Response
    {
        [$i, $m, $b] = $this->bankFor($ref, $bank, 'write');
        $q = $this->s->questionBanks()->repo()->question((int) $i['id'], (int) $b['id'], $question) ?? throw HttpException::notFound('Question');
        return $this->saveFromForm($i, $m, $b, $q);
    }

    public function status(string $ref, string $bank, string $question): Response
    {
        [$i, $m, $b] = $this->bankFor($ref, $bank, 'see');
        $banks = $this->s->questionBanks();
        $q = $banks->repo()->question((int) $i['id'], (int) $b['id'], $question) ?? throw HttpException::notFound('Question');
        try {
            $to = $banks->transition($i, $m, $b, $q, (string) $this->request->input('action', ''), (string) $this->request->input('note', ''));
            $this->flash('success', 'The question is now ' . strtolower(QuestionBanks::STATUSES[$to]) . '.' . ($to === 'published' ? ' Assessments use version ' . (int) $q['latest_version'] . '.' : ''));
        } catch (RuntimeException $e) {
            $this->flash('warning', $e->getMessage());
        }
        return $this->redirect('/institutions/' . $i['uuid'] . '/banks/' . $b['uuid'] . '/questions/' . $q['uuid']);
    }

    public function review(string $ref): Response
    {
        [$i, $m] = $this->tenant($ref, 'question_banks.publish');
        return $this->render('institutions/review', $this->pageVars($i, $m, 'banks') + ['rows' => $this->s->questionBanks()->repo()->inReview((int) $i['id'])]);
    }

    /** @param array<string,mixed> $i @param array<string,mixed> $m @param array<string,mixed> $b @param array<string,mixed>|null $q */
    private function saveFromForm(array $i, array $m, array $b, ?array $q): Response
    {
        $banks = $this->s->questionBanks();
        $post = $this->request->post;
        [$v, $errors] = $banks->validate((int) $i['id'], (int) $b['id'], [
            'type' => $post['type'] ?? '', 'text' => $post['text'] ?? '', 'choice_text' => is_array($post['choice_text'] ?? null) ? $post['choice_text'] : [],
            'correct' => is_array($post['correct'] ?? null) ? $post['correct'] : [], 'tf_answer' => $post['tf_answer'] ?? '', 'marks' => $post['marks'] ?? '',
            'partial_credit' => $post['partial_credit'] ?? '', 'difficulty' => $post['difficulty'] ?? '', 'topic' => $post['topic'] ?? '',
            'explanation' => $post['explanation'] ?? '', 'reference' => $post['reference'] ?? '',
        ]);
        // Pictures: those kept from the version being edited, then a newly uploaded one.
        $had = $q !== null ? (array) (json_decode((string) ($q['images'] ?? ''), true) ?: []) : [];
        $keep = array_map('strval', is_array($post['keep_images'] ?? null) ? $post['keep_images'] : []);
        $v['images'] = array_values(array_filter($had, static fn(string $u): bool => in_array($u, $keep, true)));
        $upload = $this->request->file('image');
        if ($upload !== null && $upload['error'] !== UPLOAD_ERR_NO_FILE) {
            $stored = $upload['error'] === UPLOAD_ERR_OK ? $this->s->media()->storeUpload($upload['tmp_name'], (int) $this->auth->user()['id'])
                : 'The picture did not arrive, or is larger than the server accepts.';
            if (is_string($stored)) {
                $errors[] = $stored;
            } elseif (count($v['images']) >= QuestionBanks::MAX_IMAGES) {
                $errors[] = 'A question can have up to ' . QuestionBanks::MAX_IMAGES . ' pictures.';
            } elseif (!in_array($stored['url'], $v['images'], true)) {
                $v['images'][] = $stored['url'];
            }
        }
        if ($errors !== []) {
            return $this->editor($i, $m, $b, $q, $v, $errors, 422);
        }
        try {
            $done = $banks->saveQuestion($i, $m, $b, $q, $v);
        } catch (RuntimeException $e) {
            return $this->editor($i, $m, $b, $q, $v, [$e->getMessage()], 422);
        }
        $this->flash($done['changed'] ? 'success' : 'info', !$done['changed'] ? 'Nothing was changed.'
            : ($q === null ? 'Question saved as a draft. Submit it for review when it is ready.'
                : 'Saved as version ' . $done['version'] . ', a draft.' . ($q['published_version'] !== null ? ' Assessments keep using version ' . (int) $q['published_version'] . ' until this one is published.' : '')));
        if (($post['then'] ?? '') === 'another' && $q === null) {
            return $this->redirect('/institutions/' . $i['uuid'] . '/banks/' . $b['uuid'] . '/questions/new');
        }
        return $this->redirect('/institutions/' . $i['uuid'] . '/banks/' . $b['uuid'] . '/questions/' . $done['uuid']);
    }

    /**
     * @param array<string,mixed> $i @param array<string,mixed> $m @param array<string,mixed> $b @param array<string,mixed>|null $q
     * @param array<string,mixed> $v @param list<string> $errors
     */
    private function editor(array $i, array $m, array $b, ?array $q, array $v, array $errors, int $status = 200): Response
    {
        return $this->render('institutions/question_edit', $this->pageVars($i, $m, 'banks') + [
            'bank' => $b, 'question' => $q, 'form' => $v, 'errors' => $errors,
            'topics' => $this->s->questionBanks()->repo()->topics((int) $i['id'], (int) $b['id']),
        ], $status);
    }

    /**
     * The institution, membership and bank, for someone who may see it ('see'), write in it
     * ('write') or manage it ('manage').
     *
     * @return array{0:array<string,mixed>,1:array<string,mixed>,2:array<string,mixed>}
     */
    private function bankFor(string $ref, string $bank, string $need): array
    {
        [$i, $m] = $this->tenant($ref, null);
        $banks = $this->s->questionBanks();
        $b = $banks->repo()->bank((int) $i['id'], $bank) ?? throw HttpException::notFound('Question bank');
        if (!$banks->canSee($m, $b)) {
            throw HttpException::notFound('Question bank');
        }
        $ok = match ($need) {
            'write' => $banks->canWrite($m, $b),
            'manage' => $banks->canManage($m, $b),
            default => true,
        };
        if (!$ok) {
            throw new HttpException(403, $need === 'manage' ? 'Only the bank\'s creator, or someone who can edit every bank, can change it.'
                : 'You can read this bank but not write in it. Its creator can add you.');
        }
        return [$i, $m, $b];
    }

    /** @param array<string,mixed> $m */
    private function anyBankPermission(array $m): bool
    {
        foreach (self::BANK_PERMISSIONS as $p) {
            if (InstitutionAccess::can($m, $p)) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string,mixed> $i @param array<string,mixed> $b */
    private function attempt(array $i, array $b, \Closure $fn, string $ok): Response
    {
        try {
            $fn();
            $this->flash('success', $ok);
        } catch (RuntimeException $e) {
            $this->flash('warning', $e->getMessage());
        }
        return $this->redirect('/institutions/' . $i['uuid'] . '/banks/' . $b['uuid']);
    }

    /** The address a picture is shown from. */
    public static function imageSrc(string $url): string
    {
        return MediaStore::isUpload($url) ? url('/media/' . substr($url, strlen(MediaStore::UPLOAD_PREFIX))) : '';
    }
}
