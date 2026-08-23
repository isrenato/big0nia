<?php

declare(strict_types=1);

namespace Doloto\Big0nia\Tests\Analysis;

use Doloto\Big0nia\Analysis\PhpFileParser;
use PhpParser\Error as PhpParserError;
use PhpParser\ErrorHandler;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PhpFileParserTest extends TestCase
{
    public function testParsesCodeAndResolvesNamesAndParents(): void
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $fileParser = new PhpFileParser($parser);

        $parsed = $fileParser->parseCode('/virtual/Foo.php', <<<'PHP'
            <?php
            namespace App;
            class Foo {
                public function run(array $items): void {
                    foreach ($items as $item) {
                        echo $item;
                    }
                }
            }
            PHP);

        self::assertSame('/virtual/Foo.php', $parsed->filePath);

        $class = $parsed->ast[0]->stmts[0];
        self::assertSame('App\\Foo', $class->namespacedName->toString());

        $foreach = $class->getMethod('run')->stmts[0];
        self::assertInstanceOf(Foreach_::class, $foreach);
        self::assertNotNull($foreach->getAttribute('parent'));
    }

    public function testParseReadsFileFromDisk(): void
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $fileParser = new PhpFileParser($parser);

        $fixture = __DIR__ . '/data/nested-loop-join.php';
        $parsed = $fileParser->parse($fixture);

        self::assertSame($fixture, $parsed->filePath);
        self::assertNotEmpty($parsed->ast);
    }

    public function testThrowsRuntimeExceptionWhenFileCannotBeRead(): void
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $fileParser = new PhpFileParser($parser);

        $this->expectException(RuntimeException::class);

        $fileParser->parse(__DIR__ . '/data/does-not-exist.php');
    }

    public function testPropagatesParserErrorsOnInvalidSyntax(): void
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $fileParser = new PhpFileParser($parser);

        $this->expectException(PhpParserError::class);

        $fileParser->parseCode('/virtual/Bad.php', '<?php class {');
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
            $fileParser = new PhpFileParser($parser);
            $fileParser->parseCode('/virtual/Foo.php', '<?php');

            self::assertNotNull($parser->levelDuringParse);
            self::assertSame(0, $parser->levelDuringParse & E_DEPRECATED);
            self::assertSame($originalLevel, error_reporting());
        } finally {
            error_reporting($levelBeforeTest);
        }
    }

    public function testReturnsEmptyAstWhenParserReturnsNull(): void
    {
        $parser = new class () implements Parser {
            public function parse(string $code, ?ErrorHandler $errorHandler = null): ?array
            {
                return null;
            }

            public function getTokens(): array
            {
                return [];
            }
        };

        $fileParser = new PhpFileParser($parser);
        $parsed = $fileParser->parseCode('/virtual/Null.php', '<?php');

        self::assertSame('/virtual/Null.php', $parsed->filePath);
        self::assertSame([], $parsed->ast);
    }

    public function testThrowsRuntimeExceptionWhenFileCannotBeReadBetweenChecks(): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'phpparser_test_');
        file_put_contents($tempFile, '<?php');

        try {
            $parser = (new ParserFactory())->createForNewestSupportedVersion();
            $fileParser = new PhpFileParser($parser);

            unlink($tempFile);

            $this->expectException(RuntimeException::class);
            $fileParser->parse($tempFile);
        } finally {
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
        }
    }
}
