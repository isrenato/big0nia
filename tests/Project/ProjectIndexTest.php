<?php

declare(strict_types=1);

namespace Doloto\Big0nia\Tests\Project;

use Doloto\Big0nia\Project\ClassIndexEntry;
use Doloto\Big0nia\Project\FunctionIndexEntry;
use Doloto\Big0nia\Project\ProjectIndex;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Function_;
use PHPUnit\Framework\TestCase;

final class ProjectIndexTest extends TestCase
{
    public function testFindsIndexedClass(): void
    {
        $entry = new ClassIndexEntry('App\\Foo', new Class_('Foo'), '/virtual/Foo.php');
        $index = new ProjectIndex(['App\\Foo' => $entry], [], []);

        self::assertSame($entry, $index->findClass('App\\Foo'));
        self::assertNull($index->findClass('App\\Bar'));
    }

    public function testFindsImplementorsOrEmptyArray(): void
    {
        $index = new ProjectIndex([], ['App\\Repo' => ['App\\DoctrineRepo']], []);

        self::assertSame(['App\\DoctrineRepo'], $index->findImplementors('App\\Repo'));
        self::assertSame([], $index->findImplementors('App\\Unknown'));
    }

    public function testFindsIndexedFunction(): void
    {
        $entry = new FunctionIndexEntry(new Function_('helper'), '/virtual/helpers.php');
        $index = new ProjectIndex([], [], ['App\\helper' => $entry]);

        self::assertSame($entry, $index->findFunction('App\\helper'));
        self::assertNull($index->findFunction('App\\other'));
    }
}
