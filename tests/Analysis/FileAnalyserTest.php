<?php

declare(strict_types=1);

namespace Doloto\Big0nia\Tests\Analysis;

use Doloto\Big0nia\Analysis\FileAnalyser;
use Doloto\Big0nia\Rule\NestedForLoopJoinRule;
use Doloto\Big0nia\Rule\NestedLoopJoinRule;
use PhpParser\ErrorHandler;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;

final class FileAnalyserTest extends TestCase
{
    public function testFindsExpectedDiagnosticsAndSuppressesFalsePositives(): void
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $analyser = new FileAnalyser($parser, [new NestedLoopJoinRule()]);

        $fixture = __DIR__ . '/data/nested-loop-join.php';
        $diagnostics = $analyser->analyse($fixture);

        self::assertCount(2, $diagnostics);

        self::assertSame($fixture, $diagnostics[0]->file);
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
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $analyser = new FileAnalyser($parser, [new NestedLoopJoinRule()]);

        $fixture = __DIR__ . '/data/collection-size-edge-cases.php';
        $diagnostics = $analyser->analyse($fixture);

        self::assertCount(2, $diagnostics);
        self::assertSame(43, $diagnostics[0]->line);
        self::assertSame(62, $diagnostics[1]->line);
    }

    public function testDetectsCanonicalForLoopJoinButNotNonCanonicalForm(): void
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $analyser = new FileAnalyser($parser, [new NestedLoopJoinRule(), new NestedForLoopJoinRule()]);

        $fixture = __DIR__ . '/data/nested-for-loop-join.php';
        $diagnostics = $analyser->analyse($fixture);

        self::assertCount(1, $diagnostics);
        self::assertSame(31, $diagnostics[0]->line);
        self::assertSame(
            'Potential O(n × m) algorithm: every users[i] is compared against every orders[j] using getId() vs getUserId(). Estimated complexity: O(users × orders).',
            $diagnostics[0]->message
        );
    }

    public function testSuppressesDeprecationNoticesOnlyDuringParsing(): void
    {
        $levelBeforeTest = error_reporting();
        error_reporting(E_ALL);
        $originalLevel = error_reporting();

        $parser = new class () implements Parser {
            public ?int $levelDuringParse = null;

            public function parse(string $code, ?ErrorHandler $errorHandler = null): ?array
            {
                $this->levelDuringParse = error_reporting();

                return [];
            }

            public function getTokens(): array
            {
                return [];
            }
        };

        try {
            $analyser = new FileAnalyser($parser, [new NestedLoopJoinRule()]);
            $analyser->analyse(__DIR__ . '/data/nested-loop-join.php');

            self::assertNotNull($parser->levelDuringParse);
            self::assertSame(0, $parser->levelDuringParse & E_DEPRECATED);
            self::assertSame($originalLevel, error_reporting());
        } finally {
            error_reporting($levelBeforeTest);
        }
    }
}
