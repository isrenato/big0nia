<?php

declare(strict_types=1);

namespace Doloto\Big0nia\Tests\Rule;

use Doloto\Big0nia\Rule\LinearScanInLoopRule;
use PhpParser\Node\Arg;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\BinaryOp\BooleanAnd;
use PhpParser\Node\Expr\BinaryOp\Smaller;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PostInc;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Stmt\Break_;
use PhpParser\Node\Stmt\For_;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\If_;
use PhpParser\Node\Stmt\Nop;
use PHPUnit\Framework\TestCase;

final class LinearScanInLoopRuleTest extends TestCase
{
    public function testReportsInArrayScanAgainstAnUnboundedCollectionInForeach(): void
    {
        $rule = new LinearScanInLoopRule();

        $call = new FuncCall(new Name('in_array'), [
            new Arg(new MethodCall(new Variable('user'), 'getId')),
            new Arg(new Variable('bannedIds')),
        ]);
        $outer = new Foreach_(new Variable('users'), new Variable('user'), [
            'stmts' => [new If_($call, ['stmts' => []])],
        ]);

        $finding = $rule->check($outer, []);

        self::assertNotNull($finding);
        self::assertSame(
            'Potential O(n × m) algorithm: every user is checked against bannedIds using in_array(). Estimated complexity: O(users × bannedIds).',
            $finding->message
        );
        self::assertSame(
            'Flip bannedIds into a lookup map (e.g. array_flip() or keyed by the value being searched) before the loop, then use isset()/array_key_exists() instead of in_array(). Possible complexity after optimization: O(users + bannedIds).',
            $finding->tip
        );
    }

    public function testReportsArraySearchScanInForeach(): void
    {
        $rule = new LinearScanInLoopRule();

        $call = new FuncCall(new Name('array_search'), [
            new Arg(new MethodCall(new Variable('user'), 'getId')),
            new Arg(new Variable('bannedIds')),
        ]);
        $outer = new Foreach_(new Variable('users'), new Variable('user'), [
            'stmts' => [new If_($call, ['stmts' => []])],
        ]);

        $finding = $rule->check($outer, []);

        self::assertNotNull($finding);
        self::assertStringContainsString('using array_search()', $finding->message);
    }

    public function testReportsInArrayScanInsideACombinedCondition(): void
    {
        $rule = new LinearScanInLoopRule();

        $call = new BooleanAnd(
            new Variable('active'),
            new FuncCall(new Name('in_array'), [
                new Arg(new MethodCall(new Variable('user'), 'getId')),
                new Arg(new Variable('bannedIds')),
            ])
        );
        $outer = new Foreach_(new Variable('users'), new Variable('user'), [
            'stmts' => [new If_($call, ['stmts' => []])],
        ]);

        self::assertNotNull($rule->check($outer, []));
    }

    public function testReportsInArrayScanOverAnIndexedForLoop(): void
    {
        $rule = new LinearScanInLoopRule();

        $call = new FuncCall(new Name('in_array'), [
            new Arg(new ArrayDimFetch(new Variable('users'), new Variable('i'))),
            new Arg(new Variable('bannedIds')),
        ]);
        $outer = $this->buildForLoop('users', 'i', [new If_($call, ['stmts' => []])]);

        $finding = $rule->check($outer, []);

        self::assertNotNull($finding);
        self::assertSame(
            'Potential O(n × m) algorithm: every users[i] is checked against bannedIds using in_array(). Estimated complexity: O(users × bannedIds).',
            $finding->message
        );
    }

    public function testSuppressesWhenHaystackIsAFixedSmallArrayLiteral(): void
    {
        $rule = new LinearScanInLoopRule();

        $call = new FuncCall(new Name('in_array'), [
            new Arg(new MethodCall(new Variable('user'), 'getStatus')),
            new Arg(new Array_([new ArrayItem(new Variable('a')), new ArrayItem(new Variable('b'))])),
        ]);
        $outer = new Foreach_(new Variable('users'), new Variable('user'), [
            'stmts' => [new If_($call, ['stmts' => []])],
        ]);

        self::assertNull($rule->check($outer, []));
    }

    public function testSuppressesWhenLoopUnconditionallyBreaksAfterOnePass(): void
    {
        $rule = new LinearScanInLoopRule();

        $call = new FuncCall(new Name('in_array'), [
            new Arg(new MethodCall(new Variable('user'), 'getId')),
            new Arg(new Variable('bannedIds')),
        ]);
        $outer = new Foreach_(new Variable('users'), new Variable('user'), [
            'stmts' => [new If_($call, ['stmts' => []]), new Break_()],
        ]);

        self::assertNull($rule->check($outer, []));
    }

    public function testReturnsNullWhenNeedleIsNotRootedInTheLoopVariable(): void
    {
        $rule = new LinearScanInLoopRule();

        $call = new FuncCall(new Name('in_array'), [
            new Arg(new Variable('somethingElse')),
            new Arg(new Variable('bannedIds')),
        ]);
        $outer = new Foreach_(new Variable('users'), new Variable('user'), [
            'stmts' => [new If_($call, ['stmts' => []])],
        ]);

        self::assertNull($rule->check($outer, []));
    }

    public function testReturnsNullWhenThereIsNoScanCall(): void
    {
        $rule = new LinearScanInLoopRule();
        $outer = new Foreach_(new Variable('users'), new Variable('user'), []);

        self::assertNull($rule->check($outer, []));
    }

    public function testReturnsNullWhenGivenANonLoopNode(): void
    {
        $rule = new LinearScanInLoopRule();

        self::assertNull($rule->check(new Nop(), []));
    }

    public function testReturnsNullWhenOuterForLoopIsNotCanonicalForm(): void
    {
        $rule = new LinearScanInLoopRule();

        $nonCanonical = new For_([
            'init' => [new Assign(new Variable('i'), new Int_(1))],
            'cond' => [new Smaller(
                new Variable('i'),
                new FuncCall(new Name('count'), [new Arg(new Variable('users'))])
            )],
            'loop' => [new PostInc(new Variable('i'))],
            'stmts' => [],
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
