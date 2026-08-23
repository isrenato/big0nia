<?php

declare(strict_types=1);

namespace Doloto\Big0nia\Tests\Project;

use Doloto\Big0nia\Analysis\ParsedFile;
use Doloto\Big0nia\Analysis\PhpFileParser;
use Doloto\Big0nia\Project\ProjectIndexBuilder;
use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;

final class ProjectIndexBuilderTest extends TestCase
{
    public function testIndexesClassesAcrossFiles(): void
    {
        $files = [
            $this->parse('/virtual/UserService.php', <<<'PHP'
                <?php
                namespace App;
                class UserService {
                    public function process(): void {}
                }
                PHP),
            $this->parse('/virtual/OrderMatcher.php', <<<'PHP'
                <?php
                namespace App;
                class OrderMatcher {
                    public function matchAll(): void {}
                }
                PHP),
        ];

        $index = (new ProjectIndexBuilder())->build($files);

        $userService = $index->findClass('App\\UserService');
        self::assertNotNull($userService);
        self::assertSame('/virtual/UserService.php', $userService->filePath);

        self::assertNotNull($index->findClass('App\\OrderMatcher'));
        self::assertNull($index->findClass('App\\DoesNotExist'));
    }

    public function testDoesNotIndexAnonymousClasses(): void
    {
        $files = [$this->parse('/virtual/Foo.php', <<<'PHP'
            <?php
            namespace App;
            class Foo {
                public function make(): object {
                    return new class {};
                }
            }
            PHP)];

        $index = (new ProjectIndexBuilder())->build($files);

        self::assertNotNull($index->findClass('App\\Foo'));
    }

    public function testIndexesDirectInterfaceImplementorsOnly(): void
    {
        $files = [$this->parse('/virtual/App.php', <<<'PHP'
            <?php
            namespace App;
            interface RepositoryInterface {}
            class DoctrineRepository implements RepositoryInterface {}
            abstract class BaseRepository implements RepositoryInterface {}
            class ExtendingRepository extends BaseRepository {}
            PHP)];

        $index = (new ProjectIndexBuilder())->build($files);

        $implementors = $index->findImplementors('App\\RepositoryInterface');
        sort($implementors);

        self::assertSame(['App\\BaseRepository', 'App\\DoctrineRepository'], $implementors);
    }

    public function testIndexesTopLevelFunctions(): void
    {
        $files = [$this->parse('/virtual/helpers.php', <<<'PHP'
            <?php
            namespace App;
            function helper(): void {}
            PHP)];

        $index = (new ProjectIndexBuilder())->build($files);

        $function = $index->findFunction('App\\helper');
        self::assertNotNull($function);
        self::assertSame('/virtual/helpers.php', $function->filePath);
    }

    private function parse(string $filePath, string $code): ParsedFile
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();

        return (new PhpFileParser($parser))->parseCode($filePath, $code);
    }
}
