<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

/** A person's own notes and bookmarks on questions (question_notes). Nobody else sees them. */
final class QuestionNoteRepository
{
    public function __construct(private Database $db)
    {
    }

    /** @return array<string,array{note:?string,bookmarked:bool}> the person's notes and bookmarks in an exam, by question key */
    public function forExam(int $userId, int $examId): array
    {
        $out = [];
        foreach ($this->db->all('SELECT question_key, note, bookmarked FROM question_notes WHERE user_id = ? AND exam_id = ?', [$userId, $examId]) as $row) {
            $out[(string) $row['question_key']] = ['note' => $row['note'] === null ? null : (string) $row['note'], 'bookmarked' => (bool) $row['bookmarked']];
        }
        return $out;
    }

    /** Write a note; an empty one is removed. */
    public function setNote(int $userId, int $examId, string $key, string $note): void
    {
        $note = trim($note);
        $this->db->run(
            'INSERT INTO question_notes (user_id, exam_id, question_key, note, bookmarked, updated_at) VALUES (?, ?, ?, ?, 0, ?)
             ON DUPLICATE KEY UPDATE note = VALUES(note), updated_at = VALUES(updated_at)',
            [$userId, $examId, $key, $note === '' ? null : $note, Database::now()]
        );
        $this->tidy($userId, $examId, $key);
    }

    /** Bookmark a question, or take the bookmark off. @return bool whether it is bookmarked now */
    public function toggleBookmark(int $userId, int $examId, string $key): bool
    {
        $this->db->run(
            'INSERT INTO question_notes (user_id, exam_id, question_key, note, bookmarked, updated_at) VALUES (?, ?, ?, NULL, 1, ?)
             ON DUPLICATE KEY UPDATE bookmarked = 1 - bookmarked, updated_at = VALUES(updated_at)',
            [$userId, $examId, $key, Database::now()]
        );
        $now = (bool) $this->db->value('SELECT bookmarked FROM question_notes WHERE user_id = ? AND exam_id = ? AND question_key = ?', [$userId, $examId, $key]);
        $this->tidy($userId, $examId, $key);
        return $now;
    }

    /** A row with neither a note nor a bookmark holds nothing: remove it. */
    private function tidy(int $userId, int $examId, string $key): void
    {
        $this->db->run('DELETE FROM question_notes WHERE user_id = ? AND exam_id = ? AND question_key = ? AND note IS NULL AND bookmarked = 0',
            [$userId, $examId, $key]);
    }
}
