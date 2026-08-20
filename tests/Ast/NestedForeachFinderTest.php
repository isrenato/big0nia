<?php

declare(strict_types=1);

namespace Doloto\Big0nia\Tests\Ast;

use Doloto\Big0nia\Ast\NestedForeachFinder;
use PhpParser\Node\Expr\BinaryOp\Identical;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\If_;
use PhpParser\Node\Stmt\Nop;
use PHPUnit\Framework\TestCase;

final class NestedForeachFinderTest extends TestCase
{
    public function testFindsDirectlyNestedForeach(): void
    {
        $inner = new Foreach_(new Variable('orders'), new Variable('order'), []);

        $finder = new NestedForeachFinder();

        self::assertSame($inner, $finder->find([$inner]));
    }

    public function testFindsForeachThroughAnIfGuard(): void
    {
        $inner = new Foreach_(new Variable('orders'), new Variable('order'), []);
        $guard = new If_(new Identical(new Variable('a'), new Variable('b')), [
            'stmts' => [$inner],
        ]);

        $finder = new NestedForeachFinder();

        self::assertSame($inner, $finder->find([$guard]));
    }

    public function testReturnsNullWhenNoNestedForeachExists(): void
    {
        $finder = new NestedForeachFinder();

        self::assertNull($finder->find([new Nop()]));
    }

    public function testReturnsNullOnEmptyStatements(): void
    {
        $finder = new NestedForeachFinder();

        self::assertNull($finder->find([]));
    }
}
