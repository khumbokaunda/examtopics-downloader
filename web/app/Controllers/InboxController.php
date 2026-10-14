<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Http\HttpException;
use App\Core\Http\Response;
use App\Services\Notifier;

/** The inbox: everyone's own notifications. */
final class InboxController extends Controller
{
    private const PER_PAGE = 20;

    public function index(): Response
    {
        $userId = (int) $this->auth->user()['id'];
        $kind = (string) $this->request->query('kind', '');
        $kind = isset(Notifier::KINDS[$kind]) ? $kind : '';
        $notifier = $this->s->notifier();
        $total = $notifier->total($userId, $kind);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($pages, max(1, (int) $this->request->query('page', '1')));
        return $this->render('inbox/index', [
            'title' => 'Inbox',
            'nav' => 'inbox',
            'items' => $notifier->page($userId, $kind, $page, self::PER_PAGE),
            'kind' => $kind,
            'page' => $page,
            'pages' => $pages,
            'total' => $total,
            'unread' => $notifier->unreadCount($userId),
        ]);
    }

    /** Open a notification: mark it read and go to its page (only ever a path inside the app). */
    public function open(string $id): Response
    {
        $userId = (int) $this->auth->user()['id'];
        $n = ctype_digit($id) ? $this->s->notifier()->find($userId, (int) $id) : null;
        if ($n === null) {
            throw HttpException::notFound('Notification');
        }
        $this->s->notifier()->markRead($userId, (int) $n['id']);
        $link = (string) $n['link'];
        $path = (string) parse_url($link, PHP_URL_PATH);
        $query = [];
        parse_str((string) parse_url($link, PHP_URL_QUERY), $query);
        if (preg_match('#^/(?!/)[A-Za-z0-9/_.\-]*$#', $path) !== 1) {
            return $this->redirect('/inbox');
        }
        return $this->redirect($path, array_filter($query, 'is_string'));
    }

    public function readAll(): Response
    {
        $this->s->notifier()->markRead((int) $this->auth->user()['id']);
        $this->flash('success', 'Everything is marked as read.');
        return $this->redirect('/inbox');
    }
}
