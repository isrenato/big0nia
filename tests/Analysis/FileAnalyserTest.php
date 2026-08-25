<?php

declare(strict_types=1);

namespace Doloto\Big0nia\Tests\Analysis;

use Doloto\Big0nia\Analysis\FileAnalyser;
use Doloto\Big0nia\Analysis\PhpFileParser;
use Doloto\Big0nia\Rule\ArrayMergeInLoopRule;
use Doloto\Big0nia\Rule\NestedForLoopJoinRule;
use Doloto\Big0nia\Rule\NestedLoopJoinRule;
use Doloto\Big0nia\Rule\RepeatedSortInLoopRule;
use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;

final class FileAnalyserTest extends TestCase
{
    public function testFindsExpectedDiagnosticsAndSuppressesFalsePositives(): void
    {
        $analyser = new FileAnalyser([new NestedLoopJoinRule()]);
        $parsed = $this->parseFixture('nested-loop-join.php');

        $diagnostics = $analyser->analyse($parsed);

        self::assertCount(2, $diagnostics);

        self::assertSame($parsed->filePath, $diagnostics[0]->file);
        self::assertSame(39, $diagnostics[0]->line);
        self::assertSame(
            'Potential O(n × m) algorithm: every user is compared against every order using getId() vs getUserId(). Estimated complexity: O(users × orders).',
            $diagnostics[0]->message
        );

        self::assertSame(55, $diagnostics[1]->line);
        self::assertSame(
            'Potential O(n²) algorithm: every user is compared against every candidate using getId() vs getId(). Estimated complexity: O(users²).',
            $diagnostics[1]->message
        );
    }

    public function testDoesNotOverSuppressEmptyLiteralOrNonLiteralReassignment(): void
    {
        $analyser = new FileAnalyser([new NestedLoopJoinRule()]);
        $parsed = $this->parseFixture('collection-size-edge-cases.php');

        $diagnostics = $analyser->analyse($parsed);

        self::assertCount(2, $diagnostics);
        self::assertSame(43, $diagnostics[0]->line);
        self::assertSame(62, $diagnostics[1]->line);
    }

    public function testDetectsCanonicalForLoopJoinButNotNonCanonicalForm(): void
    {
        $analyser = new FileAnalyser([new NestedLoopJoinRule(), new NestedForLoopJoinRule()]);
        $parsed = $this->parseFixture('nested-for-loop-join.php');

        $diagnostics = $analyser->analyse($parsed);

        self::assertCount(1, $diagnostics);
        self::assertSame(31, $diagnostics[0]->line);
        self::assertSame(
            'Potential O(n × m) algorithm: every users[i] is compared against every orders[j] using getId() vs getUserId(). Estimated complexity: O(users × orders).',
            $diagnostics[0]->message
        );
    }

    public function testDetectsSelfReferentialArrayMergeButNotUnrelatedOrSuppressedForms(): void
    {
        $analyser = new FileAnalyser([new ArrayMergeInLoopRule()]);
        $parsed = $this->parseFixture('array-merge-in-loop.php');

        $diagnostics = $analyser->analyse($parsed);

        self::assertCount(1, $diagnostics);
        self::assertSame(17, $diagnostics[0]->line);
        self::assertSame(
            'Potential O(n²) algorithm: array_merge() rebuilds $result from scratch on every iteration.',
            $diagnostics[0]->message
        );
    }

    public function testDetectsLoopInvariantSortButNotVariantOrSuppressedForms(): void
    {
        $analyser = new FileAnalyser([new RepeatedSortInLoopRule()]);
        $parsed = $this->parseFixture('repeated-sort-in-loop.php');

        $diagnostics = $analyser->analyse($parsed);

        self::assertCount(1, $diagnostics);
        self::assertSame(16, $diagnostics[0]->line);
        self::assertSame(
            'Potential wasted work: usort($data, ...) re-sorts $data on every iteration, but $data is never modified inside this loop.',
            $diagnostics[0]->message
        );
    }

    private function parseFixture(string $name): \Doloto\Big0nia\Analysis\ParsedFile
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();

        return (new PhpFileParser($parser))->parse(__DIR__ . '/data/' . $name);
    }
}
