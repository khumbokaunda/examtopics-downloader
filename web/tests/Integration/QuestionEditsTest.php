<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Controllers\QuestionController;
use App\Services\Access;
use Tests\Support\HttpTestCase;

/**
 * Questions corrected in the reader: wording, choices and pictures, shown over the collected
 * data in the reader and in documents, and put back as collected on request.
 */
final class QuestionEditsTest extends HttpTestCase
{
    private const BASE = '/library/huawei/h12-811';
    private array $version;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configure($this->startFixture());
        $this->newSession();
        $this->login();
        $this->s->jobService()->enqueueInventory($this->provider('huawei'));
        $this->drain();
        $id = $this->s->jobService()->enqueueCollect($this->provider('huawei'), 'h12-811', ['source_mode' => 'live', 'rps' => 50.0]);
        $this->drain();
        $this->version = (array) $this->s->versions()->find((int) $this->s->jobs()->find($id)['cache_version_id']);
    }

    /** @param array<string,string> $data */
    private function save(string $key, array $data): \App\Core\Http\Response
    {
        return $this->post(self::BASE . '/questions', ['key' => $key, 'version' => $this->version['version'], 'page' => '1'] + $data);
    }

    private function reader(string $mode = ''): string
    {
        return $this->get(self::BASE . '/view', ['version' => $this->version['version'], 'mode' => $mode])->body;
    }

    /** A small PNG file, different for each $seed. */
    private function png(int $seed): string
    {
        $img = imagecreatetruecolor(4, 3);
        imagefill($img, 0, 0, (int) imagecolorallocate($img, $seed % 256, 40, 90));
        $path = $this->app->paths()->storage() . '/upload-' . $seed . '.png';
        imagepng($img, $path);
        return $path;
    }

    /** @return array{name:string,tmp_name:string,size:int,error:int} */
    private static function upload(string $path, string $name = 'found.png'): array
    {
        return ['name' => $name, 'tmp_name' => $path, 'size' => (int) filesize($path), 'error' => UPLOAD_ERR_OK];
    }

    public function testTheWordingAndChoicesCanBeCorrected(): void
    {
        $collected = 'H12-811 question 1: which protocol is described?';
        $before = $this->reader();
        self::assertStringContainsString('Edit this question', $before);
        self::assertStringContainsString($collected, $before);

        $r = $this->save('1-1', ['text' => "H12-811 question 1: which routing protocol is described here?\r\nSecond line of the question.", 'choice_B' => 'BGP-4']);
        self::assertSame(303, $r->status);
        self::assertStringEndsWith('#question-1', $r->headers['Location']);
        $flash = $this->flashes()[0];
        self::assertSame('success', $flash['type']);
        self::assertSame('Question 1: the wording, the choices. Documents made before this can be rebuilt on the exam page.', $flash['message']);

        // Shown in study and practice mode, marked as edited; the collected data is unchanged.
        $study = $this->reader();
        self::assertStringContainsString('which routing protocol is described here?', $study);
        self::assertStringNotContainsString($collected, $study);
        self::assertStringContainsString('<span class="choice-text">BGP-4</span>', $study);
        self::assertStringContainsString('Change your edit of this question', $study);
        self::assertStringContainsString('>edited<', $study);
        self::assertStringContainsString('which routing protocol is described here?', $this->reader('practice'));
        self::assertStringContainsString('which protocol is described?', (string) file_get_contents($this->s->cache()->datasetPath($this->version)));
        self::assertStringContainsString('1 question edited', $this->get(self::BASE)->body);

        // Saving the same again changes nothing.
        $this->save('1-1', ['text' => "H12-811 question 1: which routing protocol is described here?\nSecond line of the question.", 'choice_B' => 'BGP-4']);
        self::assertSame('Question 1: nothing was changed.', $this->flashes()[0]['message']);

        // Documents carry it, and one made before it is offered again.
        $doc = $this->s->documents()->obtain($this->version, 'md', 'study');
        $md = (string) file_get_contents($this->app->paths()->documentsRoot() . '/' . $doc['rel_path']);
        self::assertStringContainsString('which routing protocol is described here?', $md);
        self::assertStringContainsString('BGP-4', $md);

        // The corrections page lists it, and the activity history says what changed.
        $page = $this->get(self::BASE . '/corrections', ['version' => $this->version['version']])->body;
        self::assertStringContainsString('Questions edited', $page);
        self::assertStringContainsString('wording, choice B', $page);
        self::assertStringContainsString('edited question 1 of', $this->get('/activity')->body);

        // Put back: as collected again, in the reader and in a new document.
        $this->save('1-1', ['action' => 'reset']);
        self::assertSame('Question 1 is shown as collected again.', $this->flashes()[0]['message']);
        self::assertStringContainsString($collected, $this->reader());
        self::assertNotSame($doc['options_hash'], $this->s->documents()->obtain($this->version, 'md', 'study')['options_hash']);
        self::assertSame(0, (int) $this->app->db()->value('SELECT COUNT(*) FROM question_edits'));
    }

    public function testPictureMarksKeepTheirPlaceAndNothingHalfMadeIsSaved(): void
    {
        $r = $this->save('1-1', ['text' => "Look at the exhibit.\n\n[picture]\n\nWhich protocol is shown?"]);
        self::assertSame('success', $this->flashes()[0]['type']);
        $edit = $this->s->questionEdits()->forExam((int) $this->version['exam_id'])['1-1'];
        self::assertSame("Look at the exhibit.\n\n//IMG//\n\nWhich protocol is shown?", $edit['text'], 'stored with the dataset\'s mark');
        self::assertStringContainsString("Look at the exhibit.\n\n[picture]\n\nWhich protocol is shown?</textarea>", $this->reader(), 'and shown in the editor as written');

        // An empty question, an empty choice or a file that is not a picture: nothing is saved.
        $this->save('1-1', ['text' => '   ', 'choice_A' => 'OSPFv3']);
        $flash = $this->flashes()[0];
        self::assertSame('warning', $flash['type']);
        self::assertStringContainsString('nothing was saved. The question cannot be left empty', $flash['message']);
        $this->save('1-1', ['choice_C' => '']);
        self::assertStringContainsString('Choice C cannot be left empty.', $this->flashes()[0]['message']);
        $text = $this->app->paths()->storage() . '/notes.png';
        file_put_contents($text, 'not a picture');
        $this->postFiles(self::BASE . '/questions', ['key' => '1-1', 'version' => $this->version['version']], ['question_image' => self::upload($text)]);
        self::assertStringContainsString('Only PNG, JPEG, GIF and WebP pictures can be added.', $this->flashes()[0]['message']);
        self::assertNull($this->s->questionEdits()->forExam((int) $this->version['exam_id'])['1-1']['choices']);
        self::assertSame(0, (int) $this->app->db()->value('SELECT COUNT(*) FROM uploaded_images'));

        self::assertSame(404, $this->save('9-99', ['text' => 'x'])->status);
        self::assertSame(404, $this->save('../1', ['text' => 'x'])->status);
    }

    public function testPicturesCanBeAddedAndRemoved(): void
    {
        // Question 2 has a picture from ExamTopics; it is removed, and two found elsewhere are added.
        $collected = (string) $this->app->db()->value("SELECT url FROM media WHERE status = 'saved'");
        $page = $this->reader();
        self::assertStringContainsString('name="remove_question_' . QuestionController::imageId($collected) . '"', $page);

        $file = $this->png(1);
        $sha = hash_file('sha256', $file);
        $r = $this->postFiles(self::BASE . '/questions', ['key' => '1-2', 'version' => $this->version['version'],
            'remove_question_' . QuestionController::imageId($collected) => '1'], ['question_image' => self::upload($file), 'answer_image' => self::upload($this->png(2))]);
        self::assertSame(303, $r->status);
        self::assertSame('Question 2: 2 pictures added, a picture removed. Documents made before this can be rebuilt on the exam page.', $this->flashes()[0]['message']);

        // Kept apart from downloaded images, served like them, and shown in the reader.
        $stored = $this->app->paths()->uploadFile($sha, 'png');
        self::assertFileExists($stored);
        self::assertSame(2, (int) $this->app->db()->value('SELECT COUNT(*) FROM uploaded_images'));
        $media = $this->get('/media/' . $sha);
        self::assertSame(200, $media->status);
        self::assertSame('image/png', $media->headers['Content-Type']);
        $study = $this->reader();
        self::assertStringContainsString('<img class="q-image" src="/media/' . $sha . '"', $study);
        self::assertStringNotContainsString('<img class="q-image" src="/media/' . $this->app->db()->value("SELECT sha256 FROM media WHERE status = 'saved'"), $study, 'the removed one is gone');
        self::assertStringContainsString('Remove 1 (added)', $study);
        $edit = $this->s->questionEdits()->forExam((int) $this->version['exam_id'])['1-2'];
        self::assertSame(['upload:' . $sha], $edit['question_images']);
        self::assertCount(1, $edit['answer_images']);
        self::assertNull($edit['text'], 'only what changed is stored');

        // The practice reveal shows the added answer picture.
        $reveal = json_decode($this->get('/api/library/huawei/h12-811/' . $this->version['version'] . '/questions/2')->body, true);
        self::assertSame(['/media/' . substr($edit['answer_images'][0], 7)], $reveal['answer_images']);

        // PDFs embed them; a Markdown file says where they are, since it cannot link to this server.
        $pdf = $this->s->documents()->obtain($this->version, 'pdf', 'study');
        self::assertSame(3, json_decode((string) $pdf['options'], true)['images'], 'the downloaded one and the two added');
        $md = $this->s->documents()->obtain($this->version, 'md', 'study');
        self::assertStringContainsString('*[Question image 1: a picture added by hand; it is in the PDF and the reader]*',
            (string) file_get_contents($this->app->paths()->documentsRoot() . '/' . $md['rel_path']));

        // Image cleanup never removes an upload, and backups always include them.
        $this->s->media()->prune([]);
        self::assertFileExists($stored);
        self::assertArrayHasKey('uploads', \App\Services\Backup::AREAS);
    }

    public function testOnlyPeopleWhoMayCorrectAnswersCanEditQuestions(): void
    {
        $this->s->users()->create('member', 'member password long', Access::MEMBER);
        $this->newSession();
        $this->login('member', 'member password long');
        self::assertStringNotContainsString('Edit this question', $this->reader());
        self::assertSame(403, $this->save('1-1', ['text' => 'Changed'])->status);
        self::assertSame(403, $this->get('/integrity')->status);
        self::assertSame(0, (int) $this->app->db()->value('SELECT COUNT(*) FROM question_edits'));
    }
}
