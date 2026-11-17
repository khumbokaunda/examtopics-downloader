<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Readiness;
use PHPUnit\Framework\TestCase;

/** The exam named on a readiness certificate: never the provider twice. */
final class CertificateExamTitleTest extends TestCase
{
    public function testARepeatedProviderIsLeftOut(): void
    {
        self::assertSame('Cisco Certified Network Associate (CCNA)', Readiness::examTitle('Cisco Cisco Certified Network Associate (CCNA)'));
        self::assertSame('Palo Alto Networks Certified Engineer', Readiness::examTitle('Palo Alto Networks Palo Alto Networks Certified Engineer'));
        self::assertSame('Microsoft Azure Fundamentals', Readiness::examTitle('microsoft Microsoft Azure Fundamentals'));
    }

    public function testATitleWithoutARepeatIsKept(): void
    {
        self::assertSame('HCIA-Datacom V1.0', Readiness::examTitle('HCIA-Datacom V1.0'));
        self::assertSame('Huawei H12-811', Readiness::examTitle('Huawei H12-811'));
        self::assertSame('CompTIA Security+', Readiness::examTitle('  CompTIA Security+ '));
        self::assertSame('', Readiness::examTitle(''));
    }
}
