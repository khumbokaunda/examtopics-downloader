<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Process;
use PHPUnit\Framework\TestCase;

final class ProcessTest extends TestCase
{
    /** A child must not inherit the parent's sockets or lock files. */
    public function testChildDoesNotInheritDescriptors(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        $lockFile = tempnam(sys_get_temp_dir(), 'lock');
        $lock = fopen($lockFile, 'c');
        self::assertTrue(flock($lock, LOCK_EX));

        $log = tempnam(sys_get_temp_dir(), 'fdlog');
        $p = new Process(['/bin/ls', '-l', '/proc/self/fd/'], sys_get_temp_dir(), ['PATH' => '/usr/bin:/bin'], $log);
        while ($p->poll() === null) {
            usleep(20_000);
        }
        $out = (string) file_get_contents($log);
        self::assertStringNotContainsString('socket:', $out, 'listening socket leaked into the child');
        self::assertStringNotContainsString($lockFile, $out, 'lock file leaked into the child');
        self::assertSame(0, $p->poll());
        fclose($server);
    }

    public function testExitCodesAndSignals(): void
    {
        $log = tempnam(sys_get_temp_dir(), 'fdlog');
        $p = new Process(['/bin/sh', '-c', 'exit 3'], sys_get_temp_dir(), ['PATH' => '/usr/bin:/bin'], $log);
        while (($code = $p->poll()) === null) {
            usleep(20_000);
        }
        self::assertSame(3, $code);
        $p = new Process(['/bin/sleep', '30'], sys_get_temp_dir(), ['PATH' => '/usr/bin:/bin'], $log);
        self::assertSame(143, $p->stop(5));
    }
}
