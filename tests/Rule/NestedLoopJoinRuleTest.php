<?php

declare(strict_types=1);

namespace Doloto\Big0nia\Tests\Rule;

use Doloto\Big0nia\Rule\NestedLoopJoinRule;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\BinaryOp\Identical;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Stmt\For_;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\If_;
use PHPUnit\Framework\TestCase;

final class NestedLoopJoinRuleTest extends TestCase
{
    public function testReportsJoinOverTwoDifferentUnboundedCollections(): void
    {
        $rule = new NestedLoopJoinRule();
        $outer = $this->buildJoinFixture('users', 'orders', 'user', 'order', 'getId', 'getUserId');

        $finding = $rule->check($outer, []);

        self::assertNotNull($finding);
        self::assertSame(
            'Potential O(n × m) algorithm: every user is compared against every order using getId() vs getUserId(). Estimated complexity: O(users × orders).',
            $finding->message
        );
        self::assertSame(
            'Index orders by userId before the loop, then look up matches instead of scanning. Possible complexity after optimization: O(users + orders).',
            $finding->tip
        );
    }

    public function testReportsSelfJoinOverSameCollectionAsQuadratic(): void
    {
        $rule = new NestedLoopJoinRule();
        $outer = $this->buildJoinFixture('users', 'users', 'user', 'candidate', 'getId', 'getId');

        $finding = $rule->check($outer, []);

        self::assertNotNull($finding);
        self::assertSame(
            'Potential O(n²) algorithm: every user is compared against every candidate using getId() vs getId(). Estimated complexity: O(users²).',
            $finding->message
        );
    }

    public function testSuppressesWhenInnerCollectionIsAFixedSmallArrayLiteral(): void
    {
        $rule = new NestedLoopJoinRule();

        $inner = new Foreach_(
            new Array_([new ArrayItem(new Variable('a')), new ArrayItem(new Variable('b'))]),
            new Variable('status'),
            [
                'stmts' => [
                    new If_(new Identical(
                        new MethodCall(new Variable('user'), 'getId'),
                        new MethodCall(new Variable('status'), 'getId')
                    ), ['stmts' => []]),
                ],
            ]
        );
        $outer = new Foreach_(new Variable('users'), new Variable('user'), ['stmts' => [$inner]]);

        self::assertNull($rule->check($outer, []));
    }

    public function testReturnsNullWhenThereIsNoNestedLoop(): void
    {
        $rule = new NestedLoopJoinRule();
        $outer = new Foreach_(new Variable('users'), new Variable('user'), []);

        self::assertNull($rule->check($outer, []));
    }

    public function testReturnsNullWhenLoopsAreNotJoinedByComparison(): void
    {
        $rule = new NestedLoopJoinRule();

        $inner = new Foreach_(new Variable('orders'), new Variable('order'), []);
        $outer = new Foreach_(new Variable('users'), new Variable('user'), ['stmts' => [$inner]]);

        self::assertNull($rule->check($outer, []));
    }

    public function testReturnsNullWhenGivenANonForeachLoopNode(): void
    {
        $rule = new NestedLoopJoinRule();

        self::assertNull($rule->check(new For_(), []));
    }

    private function buildJoinFixture(
        string $outerCollection,
        string $innerCollection,
        string $outerVar,
        string $innerVar,
        string $outerMethod,
        string $innerMethod
    ): Foreach_ {
        $inner = new Foreach_(
            new Variable($innerCollection),
            new Variable($innerVar),
            [
                'stmts' => [
                    new If_(new Identical(
                        new MethodCall(new Variable($outerVar), $outerMethod),
                        new MethodCall(new Variable($innerVar), $innerMethod)
                    ), ['stmts' => []]),
                ],
            ]
        );

        return new Foreach_(new Variable($outerCollection), new Variable($outerVar), ['stmts' => [$inner]]);
    }
}
