<?php

declare(strict_types=1);

namespace Doloto\Big0nia\Tests\Ast;

use Doloto\Big0nia\Ast\JoinSignatureMatcher;
use PhpParser\Node\Expr\BinaryOp\BooleanAnd;
use PhpParser\Node\Expr\BinaryOp\Identical;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Stmt\If_;
use PHPUnit\Framework\TestCase;

final class JoinSignatureMatcherTest extends TestCase
{
    public function testMatchesMethodCallComparisonInEitherOperandOrder(): void
    {
        $cond = new Identical(
            new MethodCall(new Variable('user'), 'getId'),
            new MethodCall(new Variable('order'), 'getUserId')
        );
        $stmts = [new If_($cond, ['stmts' => []])];

        $matcher = new JoinSignatureMatcher();
        $signature = $matcher->find($stmts, 'user', 'order');

        self::assertNotNull($signature);
        self::assertSame('getId()', $signature->outerDisplay);
        self::assertSame('getUserId()', $signature->innerDisplay);
        self::assertSame('userId', $signature->innerKey);
    }

    public function testMatchesWhenOperandOrderIsReversed(): void
    {
        $cond = new Identical(
            new MethodCall(new Variable('order'), 'getUserId'),
            new MethodCall(new Variable('user'), 'getId')
        );
        $stmts = [new If_($cond, ['stmts' => []])];

        $matcher = new JoinSignatureMatcher();
        $signature = $matcher->find($stmts, 'user', 'order');

        self::assertNotNull($signature);
        self::assertSame('getId()', $signature->outerDisplay);
        self::assertSame('getUserId()', $signature->innerDisplay);
    }

    public function testMatchesPropertyFetchComparison(): void
    {
        $cond = new Identical(
            new PropertyFetch(new Variable('user'), 'id'),
            new PropertyFetch(new Variable('order'), 'userId')
        );
        $stmts = [new If_($cond, ['stmts' => []])];

        $matcher = new JoinSignatureMatcher();
        $signature = $matcher->find($stmts, 'user', 'order');

        self::assertNotNull($signature);
        self::assertSame('id', $signature->outerDisplay);
        self::assertSame('userId', $signature->innerDisplay);
        self::assertSame('userId', $signature->innerKey);
    }

    public function testReturnsNullWhenComparisonDoesNotReferenceBothVars(): void
    {
        $cond = new Identical(
            new MethodCall(new Variable('order'), 'getUserId'),
            new MethodCall(new Variable('otherOrder'), 'getUserId')
        );
        $stmts = [new If_($cond, ['stmts' => []])];

        $matcher = new JoinSignatureMatcher();

        self::assertNull($matcher->find($stmts, 'user', 'order'));
    }

    public function testReturnsNullWhenNoIfStatementIsPresent(): void
    {
        $matcher = new JoinSignatureMatcher();

        self::assertNull($matcher->find([], 'user', 'order'));
    }

    public function testMatchesComparisonAsLeftOperandOfBooleanAnd(): void
    {
        $comparison = new Identical(
            new MethodCall(new Variable('user'), 'getId'),
            new MethodCall(new Variable('order'), 'getUserId')
        );
        $cond = new BooleanAnd($comparison, new MethodCall(new Variable('order'), 'isPaid'));
        $stmts = [new If_($cond, ['stmts' => []])];

        $matcher = new JoinSignatureMatcher();
        $signature = $matcher->find($stmts, 'user', 'order');

        self::assertNotNull($signature);
        self::assertSame('getId()', $signature->outerDisplay);
        self::assertSame('getUserId()', $signature->innerDisplay);
    }

    public function testMatchesComparisonAsRightOperandOfBooleanAnd(): void
    {
        $comparison = new Identical(
            new MethodCall(new Variable('user'), 'getId'),
            new MethodCall(new Variable('order'), 'getUserId')
        );
        $cond = new BooleanAnd(new MethodCall(new Variable('order'), 'isPaid'), $comparison);
        $stmts = [new If_($cond, ['stmts' => []])];

        $matcher = new JoinSignatureMatcher();
        $signature = $matcher->find($stmts, 'user', 'order');

        self::assertNotNull($signature);
        self::assertSame('getId()', $signature->outerDisplay);
        self::assertSame('getUserId()', $signature->innerDisplay);
    }

    public function testMatchesComparisonNestedInsideChainedBooleanAnd(): void
    {
        $comparison = new Identical(
            new MethodCall(new Variable('user'), 'getId'),
            new MethodCall(new Variable('order'), 'getUserId')
        );
        $cond = new BooleanAnd(
            new MethodCall(new Variable('order'), 'isPaid'),
            new BooleanAnd(new MethodCall(new Variable('order'), 'isShipped'), $comparison)
        );
        $stmts = [new If_($cond, ['stmts' => []])];

        $matcher = new JoinSignatureMatcher();
        $signature = $matcher->find($stmts, 'user', 'order');

        self::assertNotNull($signature);
        self::assertSame('getId()', $signature->outerDisplay);
        self::assertSame('getUserId()', $signature->innerDisplay);
    }

    public function testReturnsNullWhenBooleanAndHasNoComparisonOperand(): void
    {
        $cond = new BooleanAnd(
            new MethodCall(new Variable('order'), 'isPaid'),
            new MethodCall(new Variable('order'), 'isShipped')
        );
        $stmts = [new If_($cond, ['stmts' => []])];

        $matcher = new JoinSignatureMatcher();

        self::assertNull($matcher->find($stmts, 'user', 'order'));
    }
}
