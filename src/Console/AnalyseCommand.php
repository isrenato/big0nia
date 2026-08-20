<?php

declare(strict_types=1);

namespace Doloto\Big0nia\Console;

use Doloto\Big0nia\Analysis\FileAnalyser;
use Doloto\Big0nia\Rule\NestedLoopJoinRule;
use FilesystemIterator;
use PhpParser\Error as PhpParserError;
use PhpParser\ParserFactory;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

final class AnalyseCommand
{
    /**
     * @param string[] $args
     */
    public function run(array $args): int
    {
        if ($args === [] || $args[0] !== 'analyse' || count($args) < 2) {
            fwrite(STDERR, "Usage: big0nia analyse <path> [<path> ...]\n");

            return 1;
        }

        $paths = array_slice($args, 1);
        $hasMissingPath = false;
        $files = $this->collectPhpFiles($paths, $hasMissingPath);

        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $analyser = new FileAnalyser($parser, new NestedLoopJoinRule());

        $diagnosticCount = 0;
        $hasSkippedFile = false;
        foreach ($files as $file) {
            try {
                $diagnostics = $analyser->analyse($file);
            } catch (PhpParserError | RuntimeException $e) {
                fwrite(STDERR, sprintf("Skipping %s: %s\n", $file, $e->getMessage()));
                $hasSkippedFile = true;

                continue;
            }

            foreach ($diagnostics as $diagnostic) {
                $diagnosticCount++;
                echo sprintf("%s:%d\n", $diagnostic->file, $diagnostic->line);
                echo sprintf("  %s\n", $diagnostic->message);
                echo sprintf("  Tip: %s\n\n", $diagnostic->tip);
            }
        }

        if ($diagnosticCount > 0) {
            echo sprintf("%d issue(s) found.\n", $diagnosticCount);

            return 1;
        }

        if ($hasMissingPath || $hasSkippedFile) {
            return 1;
        }

        echo "No issues found.\n";

        return 0;
    }

    /**
     * @param string[] $paths
     * @return string[]
     */
    private function collectPhpFiles(array $paths, bool &$hasMissingPath): array
    {
        $files = [];

        foreach ($paths as $path) {
            if (is_file($path)) {
                $files[] = $path;

                continue;
            }

            if (is_dir($path)) {
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)
                );

                foreach ($iterator as $fileInfo) {
                    if ($fileInfo->isFile() && $fileInfo->getExtension() === 'php') {
                        $files[] = $fileInfo->getPathname();
                    }
                }

                continue;
            }

            fwrite(STDERR, sprintf("Path not found: %s\n", $path));
            $hasMissingPath = true;
        }

        return $files;
    }
}
