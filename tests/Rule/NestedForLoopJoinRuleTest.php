<?php

declare(strict_types=1);

namespace Doloto\Big0nia\Tests\Rule;

use Doloto\Big0nia\Rule\NestedForLoopJoinRule;
use PhpParser\Node\Arg;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\BinaryOp\Identical;
use PhpParser\Node\Expr\BinaryOp\Smaller;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PostInc;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\For_;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\If_;
use PHPUnit\Framework\TestCase;

final class NestedForLoopJoinRuleTest extends TestCase
{
    public function testReportsJoinOverTwoDifferentUnboundedCollections(): void
    {
        $rule = new NestedForLoopJoinRule();

        $comparison = new Identical(
            new MethodCall(new ArrayDimFetch(new Variable('users'), new Variable('i')), 'getId'),
            new MethodCall(new ArrayDimFetch(new Variable('orders'), new Variable('j')), 'getUserId')
        );
        $inner = $this->buildForLoop('orders', 'j', [new If_($comparison, ['stmts' => []])]);
        $outer = $this->buildForLoop('users', 'i', [$inner]);

        $finding = $rule->check($outer, []);

        self::assertNotNull($finding);
        self::assertSame(
            'Potential O(n × m) algorithm: every users[i] is compared against every orders[j] using getId() vs getUserId(). Estimated complexity: O(users × orders).',
            $finding->message
        );
        self::assertSame(
            'Index orders by userId before the loop, then look up matches instead of scanning. Possible complexity after optimization: O(users + orders).',
            $finding->tip
        );
    }

    public function testReportsSelfJoinOverSameCollectionAsQuadratic(): void
    {
        $rule = new NestedForLoopJoinRule();

        $comparison = new Identical(
            new MethodCall(new ArrayDimFetch(new Variable('users'), new Variable('i')), 'getId'),
            new MethodCall(new ArrayDimFetch(new Variable('users'), new Variable('j')), 'getId')
        );
        $inner = $this->buildForLoop('users', 'j', [new If_($comparison, ['stmts' => []])]);
        $outer = $this->buildForLoop('users', 'i', [$inner]);

        $finding = $rule->check($outer, []);

        self::assertNotNull($finding);
        self::assertSame(
            'Potential O(n²) algorithm: every users[i] is compared against every users[j] using getId() vs getId(). Estimated complexity: O(users²).',
            $finding->message
        );
    }

    public function testSuppressesWhenInnerCollectionIsAFixedSmallArrayLiteral(): void
    {
        $rule = new NestedForLoopJoinRule();

        $comparison = new Identical(
            new MethodCall(new ArrayDimFetch(new Variable('users'), new Variable('i')), 'getId'),
            new MethodCall(new ArrayDimFetch(new Variable('statuses'), new Variable('j')), 'getId')
        );
        $inner = $this->buildForLoop('statuses', 'j', [new If_($comparison, ['stmts' => []])]);
        $outer = $this->buildForLoop('users', 'i', [$inner]);

        $precedingStmts = [
            new Expression(new Assign(
                new Variable('statuses'),
                new Array_([new ArrayItem(new Variable('a')), new ArrayItem(new Variable('b'))])
            )),
        ];

        self::assertNull($rule->check($outer, $precedingStmts));
    }

    public function testReturnsNullWhenThereIsNoNestedFor(): void
    {
        $rule = new NestedForLoopJoinRule();
        $outer = $this->buildForLoop('users', 'i', []);

        self::assertNull($rule->check($outer, []));
    }

    public function testReturnsNullWhenLoopsAreNotJoinedByComparison(): void
    {
        $rule = new NestedForLoopJoinRule();

        $inner = $this->buildForLoop('orders', 'j', []);
        $outer = $this->buildForLoop('users', 'i', [$inner]);

        self::assertNull($rule->check($outer, []));
    }

    public function testReturnsNullWhenGivenANonForLoopNode(): void
    {
        $rule = new NestedForLoopJoinRule();

        self::assertNull($rule->check(new Foreach_(new Variable('users'), new Variable('user'), []), []));
    }

    public function testReturnsNullWhenOuterLoopIsNotCanonicalForm(): void
    {
        $rule = new NestedForLoopJoinRule();

        $nonCanonical = new For_([
            'init' => [new Assign(new Variable('i'), new Int_(1))],
            'cond' => [new Smaller(
                new Variable('i'),
                new FuncCall(new Name('count'), [new Arg(new Variable('users'))])
            )],
            'loop' => [new PostInc(new Variable('i'))],
            'stmts' => [$this->buildForLoop('orders', 'j', [])],
        ]);

        self::assertNull($rule->check($nonCanonical, []));
    }

    /**
     * @param \PhpParser\Node\Stmt[] $stmts
     */
    private function buildForLoop(string $collectionVarName, string $indexVarName, array $stmts): For_
    {
        return new For_([
            'init' => [new Assign(new Variable($indexVarName), new Int_(0))],
            'cond' => [new Smaller(
                new Variable($indexVarName),
                new FuncCall(new Name('count'), [new Arg(new Variable($collectionVarName))])
            )],
            'loop' => [new PostInc(new Variable($indexVarName))],
            'stmts' => $stmts,
        ]);
    }
}
