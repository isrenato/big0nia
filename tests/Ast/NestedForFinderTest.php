<?php

declare(strict_types=1);

namespace Doloto\Big0nia\Tests\Ast;

use Doloto\Big0nia\Ast\NestedForFinder;
use PhpParser\Node\Expr\BinaryOp\Identical;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Stmt\For_;
use PhpParser\Node\Stmt\If_;
use PhpParser\Node\Stmt\Nop;
use PHPUnit\Framework\TestCase;

final class NestedForFinderTest extends TestCase
{
    public function testFindsDirectlyNestedFor(): void
    {
        $inner = new For_();

        $finder = new NestedForFinder();

        self::assertSame($inner, $finder->find([$inner]));
    }

    public function testFindsForThroughAnIfGuard(): void
    {
        $inner = new For_();
        $guard = new If_(new Identical(new Variable('a'), new Variable('b')), [
            'stmts' => [$inner],
        ]);

        $finder = new NestedForFinder();

        self::assertSame($inner, $finder->find([$guard]));
    }

    public function testReturnsNullWhenNoNestedForExists(): void
    {
        $finder = new NestedForFinder();

        self::assertNull($finder->find([new Nop()]));
    }

    public function testReturnsNullOnEmptyStatements(): void
    {
        $finder = new NestedForFinder();

        self::assertNull($finder->find([]));
    }
}
