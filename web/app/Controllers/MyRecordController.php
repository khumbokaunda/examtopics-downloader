<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Http\HttpException;
use App\Core\Http\Response;
use App\Core\View;
use App\Services\InstitutionAccess;

/**
 * A student's own academic records, across every institution they are a student of: final
 * (published) results only, the modules they completed, and the transcripts issued to them.
 */
final class MyRecordController extends Controller
{
    public function index(): Response
    {
        $user = $this->auth->user();
        $records = [];
        foreach ($this->app->db()->all("SELECT i.*, m.id AS membership_id FROM institution_memberships m JOIN institutions i ON i.id = m.institution_id
                                        WHERE m.user_id = ? AND m.role = 'student' ORDER BY i.name", [(int) $user['id']]) as $row) {
            $membership = (array) $this->app->db()->one('SELECT m.*, u.username, u.display_name FROM institution_memberships m JOIN users u ON u.id = m.user_id WHERE m.id = ?', [(int) $row['membership_id']]);
            $records[] = ['record' => $this->s->records()->record($row, $membership, false), 'active' => $membership['status'] === 'active',
                'transcripts' => array_values(array_filter($this->s->records()->transcripts((int) $row['id'], (int) $row['membership_id']), static fn(array $t): bool => $t['status'] === 'issued'))];
        }
        return $this->render('records/mine', ['title' => 'My academic record', 'nav' => 'my-record', 'records' => $records]);
    }

    public function transcript(string $institution, string $transcript): Response
    {
        $user = $this->auth->user();
        $i = $this->s->institutions()->repo()->findByUuid($institution) ?? throw HttpException::notFound('Transcript');
        $m = $this->s->institutions()->repo()->membership((int) $i['id'], (int) $user['id']);
        $t = $this->s->records()->transcript((int) $i['id'], $transcript);
        if ($m === null || $m['role'] !== InstitutionAccess::STUDENT || $t === null || (int) $t['membership_id'] !== (int) $m['id'] || $t['status'] !== 'issued') {
            throw HttpException::notFound('Transcript');
        }
        return self::pdf($this, $i, $t);
    }

    /** @param array<string,mixed> $i @param array<string,mixed> $t */
    public static function pdf(Controller $c, array $i, array $t): Response
    {
        $html = (new View($c->app, $c->session, null))->render('records/transcript_pdf', ['transcript' => $t, 'record' => $t['record'], 'institution' => $i], null);
        return new Response(\App\Services\Results::pdf($html, $c->app->paths()->storage() . '/tmp/pdf'), 200, ['Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="transcript-' . preg_replace('/[^A-Za-z0-9-]/', '', (string) $t['number']) . '.pdf"']);
    }
}
