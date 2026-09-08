<?php

declare(strict_types=1);

namespace Doloto\Big0nia\Tests\Console;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class AnalyseCommandTest extends TestCase
{
    public function testReportsFindingsAndExitsNonZero(): void
    {
        $fixture = __DIR__ . '/../Analysis/data/nested-loop-join.php';

        [$exitCode, $stdout, $stderr] = $this->runBinary(['analyse', $fixture]);

        self::assertSame('', $stderr);
        self::assertSame(1, $exitCode);
        self::assertStringContainsString('2 issue(s) found.', $stdout);
        self::assertStringContainsString('Estimated complexity: O(users × orders).', $stdout);
        self::assertStringContainsString('Estimated complexity: O(users²).', $stdout);
    }

    public function testExitsZeroWhenNoIssuesAreFound(): void
    {
        [$exitCode, $stdout] = $this->runBinary(['analyse', __FILE__]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('No issues found.', $stdout);
    }

    public function testPrintsUsageAndExitsNonZeroWithoutArguments(): void
    {
        [$exitCode, , $stderr] = $this->runBinary([]);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('Usage:', $stderr);
    }

    public function testSkipsFilesWithInvalidSyntaxAndStillAnalysesTheRest(): void
    {
        $invalid = __DIR__ . '/data/invalid-syntax.php';
        $valid = __DIR__ . '/../Analysis/data/nested-loop-join.php';

        [$exitCode, $stdout, $stderr] = $this->runBinary(['analyse', $invalid, $valid]);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('Skipping ' . $invalid, $stderr);
        self::assertStringContainsString('2 issue(s) found.', $stdout);
    }

    public function testReportsErrorAndExitsNonZeroForNonexistentPath(): void
    {
        $missing = __DIR__ . '/data/does-not-exist.php';

        [$exitCode, , $stderr] = $this->runBinary(['analyse', $missing]);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('Path not found: ' . $missing, $stderr);
    }

    public function testDetectsAnInterproceduralJoinAcrossTwoFiles(): void
    {
        $directory = __DIR__ . '/data/interprocedural';

        [$exitCode, $stdout, $stderr] = $this->runBinary(['analyse', $directory]);

        self::assertSame('', $stderr);
        self::assertSame(1, $exitCode);
        self::assertStringContainsString('1 issue(s) found.', $stdout);
        self::assertStringContainsString('via OrderMatcher::matchAll()', $stdout);
    }

    public function testExcludesFilesMatchingIgnorePaths(): void
    {
        $dir = $this->makeTempProject();

        try {
            file_put_contents($dir . '/big0nia.neon', "ignore_paths:\n    - ignored\n");
            mkdir($dir . '/ignored');
            file_put_contents($dir . '/ignored/HasIssue.php', <<<'PHP'
                <?php
                function process(array $users, array $orders): void {
                    foreach ($users as $user) {
                        foreach ($orders as $order) {
                            if ($user->getId() === $order->getUserId()) {
                                // match
                            }
                        }
                    }
                }
                PHP);

            [$exitCode, $stdout, $stderr] = $this->runBinary(['analyse', $dir], $dir);

            self::assertSame('', $stderr);
            self::assertSame(0, $exitCode);
            self::assertStringContainsString('No issues found.', $stdout);
        } finally {
            $this->removeTempProject($dir);
        }
    }

    public function testDoesNotReportSkippingForSyntaxErrorsUnderAnIgnoredPath(): void
    {
        $dir = $this->makeTempProject();

        try {
            file_put_contents($dir . '/big0nia.neon', "ignore_paths:\n    - ignored\n");
            mkdir($dir . '/ignored');
            file_put_contents($dir . '/ignored/Broken.php', "<?php\nfunction broken( {\n");

            [$exitCode, $stdout, $stderr] = $this->runBinary(['analyse', $dir], $dir);

            self::assertSame('', $stderr);
            self::assertSame(0, $exitCode);
            self::assertStringContainsString('No issues found.', $stdout);
        } finally {
            $this->removeTempProject($dir);
        }
    }

    public function testFailsWithMalformedConfigFileAndAnalysesNothing(): void
    {
        $dir = $this->makeTempProject();

        try {
            file_put_contents($dir . '/big0nia.neon', "ignore_paths: [\n");
            file_put_contents($dir . '/Clean.php', "<?php\n\$x = 1;\n");

            [$exitCode, $stdout, $stderr] = $this->runBinary(['analyse', $dir], $dir);

            self::assertSame(1, $exitCode);
            self::assertStringContainsString('big0nia.neon', $stderr);
            self::assertSame('', $stdout);
        } finally {
            $this->removeTempProject($dir);
        }
    }

    public function testFailsWhenIgnorePathsIsNotAListOfStrings(): void
    {
        $dir = $this->makeTempProject();

        try {
            file_put_contents($dir . '/big0nia.neon', "ignore_paths: vendor\n");
            file_put_contents($dir . '/Clean.php', "<?php\n\$x = 1;\n");

            [$exitCode, $stdout, $stderr] = $this->runBinary(['analyse', $dir], $dir);

            self::assertSame(1, $exitCode);
            self::assertStringContainsString('ignore_paths', $stderr);
            self::assertSame('', $stdout);
        } finally {
            $this->removeTempProject($dir);
        }
    }

    private function makeTempProject(): string
    {
        $dir = sys_get_temp_dir() . '/big0nia-cli-test-' . uniqid();
        mkdir($dir);

        return $dir;
    }

    private function removeTempProject(string $dir): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $fileInfo) {
            $fileInfo->isDir() ? rmdir($fileInfo->getPathname()) : unlink($fileInfo->getPathname());
        }

        rmdir($dir);
    }

    /**
     * @param string[] $args
     * @return array{0: int, 1: string, 2: string}
     */
    private function runBinary(array $args, ?string $cwd = null): array
    {
        $binary = __DIR__ . '/../../bin/big0nia';

        $process = proc_open(
            array_merge([PHP_BINARY, $binary], $args),
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $cwd
        );
        self::assertIsResource($process);

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        self::assertIsString($stdout);
        self::assertIsString($stderr);

        return [$exitCode, $stdout, $stderr];
    }
}
