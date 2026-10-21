<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Editions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Short codes, and which exams are editions of one another on the library's shelves. */
final class EditionsTest extends TestCase
{
    /** @return array<string,array{string,string}> */
    public static function codes(): array
    {
        return [
            'short code as it is'       => ['az-104', 'AZ-104'],
            'twenty characters exactly' => ['abcdefghij-klmnopqrs', 'ABCDEFGHIJ-KLMNOPQRS'],
            'spelled out, ends in code' => ['aws-certified-solutions-architect-associate-saa-c03', 'SAA-C03'],
            'name then a short code'    => ['microsoft-azure-administrator-az-104', 'AZ-104'],
            'version with letters'      => ['veeam-certified-engineer-vmce-v12', 'VMCE-V12'],
            'long with no digits'       => ['certified-kubernetes-administrator', 'CERTIFIED-KUBERNETES-ADMINISTRATOR'],
            'all digits and letters'    => ['h12-811-v2-0', 'H12-811-V2-0'],
        ];
    }

    #[DataProvider('codes')]
    public function testShortCode(string $code, string $short): void
    {
        self::assertSame($short, Editions::shortCode($code));
    }

    private static function row(string $provider, string $code, ?string $name, ?string $group = null, int $first = 0): array
    {
        return ['provider_slug' => $provider, 'provider_name' => ucfirst($provider), 'code' => $code, 'name' => $name,
            'edition_group' => $group, 'edition_first' => $first];
    }

    public function testEditionsShareAKeyByTheirNameWithoutVersions(): void
    {
        $key = static fn(array $r): string => Editions::key($r);
        $c02 = self::row('amazon', 'aws-certified-solutions-architect-associate-saa-c02', 'AWS Certified Solutions Architect - Associate SAA-C02');
        $c03 = self::row('amazon', 'aws-certified-solutions-architect-associate-saa-c03', 'AWS Certified Solutions Architect - Associate SAA-C03');
        self::assertSame($key($c02), $key($c03));

        $v12 = self::row('veeam', 'vmce-v12', 'Veeam Certified Engineer v12');
        $y2021 = self::row('veeam', 'vmce2021', 'Veeam Certified Engineer 2021');
        self::assertSame($key($v12), $key($y2021), 'years and v-numbers are both versions');

        // Different exams that only share a vendor word stay apart, and so do two providers.
        self::assertNotSame($key(self::row('ibm', 'c2090-101', 'IBM Big Data Engineer')), $key(self::row('ibm', 'c2090-312', 'IBM DB2 11 DBA for z/OS')));
        self::assertNotSame($key(self::row('ibm', 'c2090-101', 'Big Data')), $key(self::row('cloudera', 'cca-101', 'Big Data')));

        // No name, or a name that is just the code: never grouped.
        self::assertNotSame($key(self::row('cisco', '200-301', null)), $key(self::row('cisco', '200-301v2', null)));
        self::assertNotSame($key(self::row('cisco', '200-301', '200-301')), $key(self::row('cisco', '350-401', '350-401')));
    }

    public function testAnAdministratorsChoiceWins(): void
    {
        $a = self::row('amazon', 'saa-c02', 'Solutions Architect SAA-C02', 'solo');
        $b = self::row('amazon', 'saa-c03', 'Solutions Architect SAA-C03');
        self::assertNotSame(Editions::key($a), Editions::key($b), 'kept on its own card');

        $c = self::row('amazon', 'aws-sysops', 'SysOps Administrator', Editions::key($b));
        self::assertSame(Editions::key($b), Editions::key($c), 'grouped with another');
    }

    public function testShelvesGroupAndOrder(): void
    {
        $rows = [
            self::row('veeam', 'vmce2021', 'Veeam Certified Engineer 2021'),
            self::row('amazon', 'saa-c02', 'Solutions Architect SAA-C02'),
            self::row('veeam', 'vmce-v12', 'Veeam Certified Engineer v12'),
            self::row('amazon', 'saa-c03', 'Solutions Architect SAA-C03'),
            self::row('amazon', 'clf-c02', 'Cloud Practitioner CLF-C02'),
            self::row('veeam', 'vmce-v9', 'Veeam Certified Engineer v9'),
        ];
        $shelves = Editions::shelves($rows);
        self::assertSame(['amazon', 'veeam'], array_column($shelves, 'slug'), 'providers by name');
        $amazon = $shelves[0]['cards'];
        self::assertSame(['clf-c02'], array_column($amazon[0], 'code'), 'cards by title');
        self::assertSame(['saa-c03', 'saa-c02'], array_column($amazon[1], 'code'), 'newest edition first');
        $veeam = array_column($shelves[1]['cards'][0], 'code');
        self::assertCount(3, $veeam);
        self::assertLessThan(array_search('vmce-v9', $veeam, true), array_search('vmce-v12', $veeam, true), 'v12 is newer than v9: numbers compare as numbers');

        $rows[2]['edition_first'] = 0;
        $rows[5]['edition_first'] = 1;
        self::assertSame('vmce-v9', Editions::shelves($rows)[1]['cards'][0][0]['code'], 'the chosen edition comes first');
    }

    public function testTheCodeIsShownOnlyWhenTheTitleDoesNotSayIt(): void
    {
        self::assertSame('SAA-C03', Editions::codeLabel(self::row('amazon', 'aws-certified-solutions-architect-associate-saa-c03', 'AWS Certified Solutions Architect Associate')));
        self::assertNull(Editions::codeLabel(self::row('amazon', 'aws-certified-cloud-practitioner', 'AWS Certified Cloud Practitioner')), 'the code is the name spelled out');
        self::assertNull(Editions::codeLabel(self::row('microsoft', 'az-500', 'AZ-500')), 'the name is the code');
        self::assertNull(Editions::codeLabel(self::row('microsoft', 'az-500', null)), 'no name: the title is the code');
        self::assertSame('AZ-500', Editions::title(self::row('microsoft', 'az-500', '')));
        self::assertSame('Azure Security', Editions::title(self::row('microsoft', 'az-500', 'Azure Security')));
    }
}
