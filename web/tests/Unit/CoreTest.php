<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Ids;
use App\Core\Migrator;
use App\Core\Paths;
use App\Core\Redactor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CoreTest extends TestCase
{
    /** @return array<string,array{mixed,bool}> */
    public static function examIds(): array
    {
        return [
            'plain'         => ['h12-811', true],
            'versioned'     => ['h12-811-v2-0', true],
            'numeric'       => ['200-301', true],
            'long slug'     => ['professional-cloud-devops-engineer', true],
            'uppercase'     => ['H12-811', false],
            'traversal'     => ['../etc', false],
            'slash'         => ['h12/811', false],
            'double dash'   => ['h12--811', false],
            'leading dash'  => ['-h12', false],
            'trailing dash' => ['h12-', false],
            'empty'         => ['', false],
            'shell'         => ['h12;rm -rf', false],
            'flag'          => ['-exam', false],
            'non-string'    => [12, false],
        ];
    }

    #[DataProvider('examIds')]
    public function testExamIdentifierRules(mixed $id, bool $valid): void
    {
        self::assertSame($valid, Ids::isExam($id));
    }

    public function testVersionIdsAreValidAndSortable(): void
    {
        $a = Ids::newVersion(1_700_000_000);
        $b = Ids::newVersion(1_700_000_060);
        self::assertTrue(Ids::isVersion($a));
        self::assertTrue(Ids::isVersion($b));
        self::assertLessThan(0, strcmp($a, $b));
        self::assertFalse(Ids::isVersion('../20261012T083000Z-abcdef'));
    }

    public function testResolveInsideRejectsEscapes(): void
    {
        $root = sys_get_temp_dir() . '/examtopics-paths-' . bin2hex(random_bytes(4));
        mkdir($root . '/a', 0750, true);
        file_put_contents($root . '/a/doc.pdf', 'x');
        $outside = $root . '-outside';
        mkdir($outside);
        file_put_contents($outside . '/secret.txt', 'secret');
        symlink($outside . '/secret.txt', $root . '/a/link.txt');

        self::assertSame(realpath($root . '/a/doc.pdf'), Paths::resolveInside($root, 'a/doc.pdf'));
        self::assertNull(Paths::resolveInside($root, '../' . basename($outside) . '/secret.txt'));
        self::assertNull(Paths::resolveInside($root, 'a/../../etc/passwd'));
        self::assertNull(Paths::resolveInside($root, '/etc/passwd'));
        self::assertNull(Paths::resolveInside($root, "a/doc.pdf\0.txt"));
        self::assertNull(Paths::resolveInside($root, 'a/link.txt'), 'symlink escaping the root must be refused');
        self::assertNull(Paths::resolveInside($root, 'a/missing.pdf'));
    }

    public function testPathsRefuseInvalidIdentifiers(): void
    {
        $root = sys_get_temp_dir() . '/examtopics-paths-' . bin2hex(random_bytes(4));
        mkdir($root);
        $paths = new Paths($root);
        $this->expectException(\RuntimeException::class);
        $paths->examCacheDir('huawei', '../../etc');
    }

    public function testAtomicWriteLeavesNoTempFiles(): void
    {
        $dir = sys_get_temp_dir() . '/examtopics-atomic-' . bin2hex(random_bytes(4));
        Paths::atomicWrite($dir . '/f.json', '{"a":1}');
        Paths::atomicWrite($dir . '/f.json', '{"a":2}');
        self::assertSame('{"a":2}', file_get_contents($dir . '/f.json'));
        self::assertSame(['f.json'], array_values(array_diff(scandir($dir) ?: [], ['.', '..'])));
    }

    public function testRedactorRemovesSecrets(): void
    {
        Redactor::addSecret('my-configured-secret-value');
        $in = "token ghp_abcdefghijklmnopqrstuvwxyz0123 and github_pat_11ABCDEFG0123456789_abcdef "
            . "Authorization: Bearer abc.def and my-configured-secret-value and ?token=xyz&x=1";
        $out = Redactor::clean($in);
        self::assertStringNotContainsString('ghp_abcdefghijklmnopqrstuvwxyz0123', $out);
        self::assertStringNotContainsString('github_pat_11ABCDEFG', $out);
        self::assertStringNotContainsString('abc.def', $out);
        self::assertStringNotContainsString('my-configured-secret-value', $out);
        self::assertStringNotContainsString('xyz', $out);
        self::assertStringContainsString('x=1', $out);
    }

    public function testProviderNamesLoseTheirExamCount(): void
    {
        self::assertSame(['Certinia', 1], \App\Repositories\ProviderRepository::splitName('Certinia (1 exams)'));
        self::assertSame(['Checkpoint', 22], \App\Repositories\ProviderRepository::splitName(' Checkpoint (22 exams) '));
        self::assertSame(['(ISC)2', 9], \App\Repositories\ProviderRepository::splitName('(ISC)2 (9 exams)'));
        self::assertSame(['Cisco', null], \App\Repositories\ProviderRepository::splitName('Cisco'));
        self::assertSame('Checkpoint', provider_name(['name' => 'Checkpoint (22 exams)', 'slug' => 'checkpoint']));
        self::assertSame('checkpoint', provider_name(['name' => null, 'slug' => 'checkpoint']));
    }

    public function testErrorSummariesAreShortAndPlain(): void
    {
        $old = "listing page 20: exhausted retries for \"https://www.examtopics.com/discussions/checkpoint/20/\": "
            . "request for \"https://www.examtopics.com/discussions/checkpoint/20/\" failed with status code 429\nlisting page 21: same";
        self::assertSame('Listing page 20: ExamTopics asked us to slow down (HTTP 429), retries ran out (+1 more)', error_summary($old));
        $new = 'listing page 3: gave up after 6 attempts: https://www.examtopics.com/discussions/x/3/ returned HTTP 503 (Service Unavailable)';
        self::assertSame('Listing page 3: ExamTopics had a server error (HTTP 503), retries ran out', error_summary($new));
        self::assertSame('Listing page 1: ExamTopics refused access (HTTP 403)', error_summary('listing page 1: https://x/ returned HTTP 403 (Forbidden)'));
        self::assertSame('Something unusual', error_summary("Something unusual\n"));
        self::assertSame('', error_summary(''));
    }

    public function testMigrationSplitterIgnoresComments(): void
    {
        $sql = "-- header; with semicolon\nCREATE TABLE a (\n  id INT -- trailing; comment\n);\n\nCREATE TABLE b (id INT);\n";
        self::assertSame(["CREATE TABLE a (\n  id INT\n)", 'CREATE TABLE b (id INT)'], Migrator::splitStatements($sql));
    }
}
