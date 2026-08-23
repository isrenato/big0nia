<?php

declare(strict_types=1);

namespace Doloto\Big0nia\Tests\Ast;

use Doloto\Big0nia\Ast\ForLoopBinding;
use Doloto\Big0nia\Ast\JoinSignatureMatcher;
use PhpParser\Node\Expr\ArrayDimFetch;
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

    public function testFindsIndexedComparisonInEitherOperandOrder(): void
    {
        $outer = new ForLoopBinding('users', 'i');
        $inner = new ForLoopBinding('orders', 'j');

        $cond = new Identical(
            new MethodCall(new ArrayDimFetch(new Variable('users'), new Variable('i')), 'getId'),
            new MethodCall(new ArrayDimFetch(new Variable('orders'), new Variable('j')), 'getUserId')
        );
        $stmts = [new If_($cond, ['stmts' => []])];

        $matcher = new JoinSignatureMatcher();
        $signature = $matcher->findIndexed($stmts, $outer, $inner);

        self::assertNotNull($signature);
        self::assertSame('getId()', $signature->outerDisplay);
        self::assertSame('getUserId()', $signature->innerDisplay);
        self::assertSame('userId', $signature->innerKey);
    }

    public function testFindsIndexedComparisonCombinedWithBooleanAnd(): void
    {
        $outer = new ForLoopBinding('users', 'i');
        $inner = new ForLoopBinding('orders', 'j');

        $comparison = new Identical(
            new MethodCall(new ArrayDimFetch(new Variable('users'), new Variable('i')), 'getId'),
            new MethodCall(new ArrayDimFetch(new Variable('orders'), new Variable('j')), 'getUserId')
        );
        $cond = new BooleanAnd(
            $comparison,
            new MethodCall(new ArrayDimFetch(new Variable('orders'), new Variable('j')), 'isPaid')
        );
        $stmts = [new If_($cond, ['stmts' => []])];

        $matcher = new JoinSignatureMatcher();
        $signature = $matcher->findIndexed($stmts, $outer, $inner);

        self::assertNotNull($signature);
        self::assertSame('getId()', $signature->outerDisplay);
        self::assertSame('getUserId()', $signature->innerDisplay);
    }

    public function testReturnsNullWhenIndexedComparisonUsesADifferentCollectionOrIndex(): void
    {
        $outer = new ForLoopBinding('users', 'i');
        $inner = new ForLoopBinding('orders', 'j');

        $cond = new Identical(
            new MethodCall(new ArrayDimFetch(new Variable('users'), new Variable('i')), 'getId'),
            new MethodCall(new ArrayDimFetch(new Variable('otherOrders'), new Variable('j')), 'getUserId')
        );
        $stmts = [new If_($cond, ['stmts' => []])];

        $matcher = new JoinSignatureMatcher();

        self::assertNull($matcher->findIndexed($stmts, $outer, $inner));
    }

    public function testReturnsNullWhenIndexedComparisonUsesTheWrongIndexVariable(): void
    {
        $outer = new ForLoopBinding('users', 'i');
        $inner = new ForLoopBinding('orders', 'j');

        $cond = new Identical(
            new MethodCall(new ArrayDimFetch(new Variable('users'), new Variable('k')), 'getId'),
            new MethodCall(new ArrayDimFetch(new Variable('orders'), new Variable('j')), 'getUserId')
        );
        $stmts = [new If_($cond, ['stmts' => []])];

        $matcher = new JoinSignatureMatcher();

        self::assertNull($matcher->findIndexed($stmts, $outer, $inner));
    }

    public function testReturnsNullWhenIndexedComparisonUsesAPlainVariableInsteadOfArrayAccess(): void
    {
        $outer = new ForLoopBinding('users', 'i');
        $inner = new ForLoopBinding('orders', 'j');

        $cond = new Identical(
            new MethodCall(new Variable('user'), 'getId'),
            new MethodCall(new ArrayDimFetch(new Variable('orders'), new Variable('j')), 'getUserId')
        );
        $stmts = [new If_($cond, ['stmts' => []])];

        $matcher = new JoinSignatureMatcher();

        self::assertNull($matcher->findIndexed($stmts, $outer, $inner));
    }

    public function testFindsSignatureBetweenAPlainVariableAndAnIndexedAccess(): void
    {
        $binding = new ForLoopBinding('orders', 'j');
        $stmts = [
            new If_(new Identical(
                new MethodCall(new Variable('user'), 'getId'),
                new MethodCall(new ArrayDimFetch(new Variable('orders'), new Variable('j')), 'getUserId')
            ), ['stmts' => []]),
        ];

        $matcher = new JoinSignatureMatcher();
        $signature = $matcher->findVariableAgainstIndexed($stmts, 'user', $binding);

        self::assertNotNull($signature);
        self::assertSame('getId()', $signature->outerDisplay);
        self::assertSame('getUserId()', $signature->innerDisplay);
        self::assertSame('userId', $signature->innerKey);
    }

    public function testReturnsNullWhenIndexedSideDoesNotMatchTheBinding(): void
    {
        $binding = new ForLoopBinding('orders', 'j');
        $stmts = [
            new If_(new Identical(
                new MethodCall(new Variable('user'), 'getId'),
                new MethodCall(new ArrayDimFetch(new Variable('orders'), new Variable('k')), 'getUserId')
            ), ['stmts' => []]),
        ];

        $matcher = new JoinSignatureMatcher();

        self::assertNull($matcher->findVariableAgainstIndexed($stmts, 'user', $binding));
    }

    public function testIsRootedInVarIsPubliclyCallable(): void
    {
        $matcher = new JoinSignatureMatcher();

        self::assertTrue($matcher->isRootedInVar(new MethodCall(new Variable('user'), 'getId'), 'user'));
        self::assertFalse($matcher->isRootedInVar(new Variable('order'), 'user'));
    }

    public function testIsRootedInIndexedAccessIsPubliclyCallable(): void
    {
        $binding = new ForLoopBinding('orders', 'j');
        $matcher = new JoinSignatureMatcher();

        self::assertTrue($matcher->isRootedInIndexedAccess(new ArrayDimFetch(new Variable('orders'), new Variable('j')), $binding));
        self::assertFalse($matcher->isRootedInIndexedAccess(new Variable('orders'), $binding));
    }

    public function testReturnsNullWhenOuterSideNotRootedInTheVariable(): void
    {
        $binding = new ForLoopBinding('orders', 'j');
        $stmts = [
            new If_(new Identical(
                new MethodCall(new Variable('other'), 'getId'),
                new MethodCall(new ArrayDimFetch(new Variable('orders'), new Variable('j')), 'getUserId')
            ), ['stmts' => []]),
        ];

        $matcher = new JoinSignatureMatcher();

        self::assertNull($matcher->findVariableAgainstIndexed($stmts, 'user', $binding));
    }

    public function testReturnsNullWhenOuterSideIsNotDescribable(): void
    {
        $binding = new ForLoopBinding('orders', 'j');
        $stmts = [
            new If_(new Identical(
                new Variable('user'),
                new MethodCall(new ArrayDimFetch(new Variable('orders'), new Variable('j')), 'getUserId')
            ), ['stmts' => []]),
        ];

        $matcher = new JoinSignatureMatcher();

        self::assertNull($matcher->findVariableAgainstIndexed($stmts, 'user', $binding));
    }

    public function testReturnsNullWhenInnerSideIsNotDescribable(): void
    {
        $binding = new ForLoopBinding('orders', 'j');
        $stmts = [
            new If_(new Identical(
                new MethodCall(new Variable('user'), 'getId'),
                new ArrayDimFetch(new Variable('orders'), new Variable('j'))
            ), ['stmts' => []]),
        ];

        $matcher = new JoinSignatureMatcher();

        self::assertNull($matcher->findVariableAgainstIndexed($stmts, 'user', $binding));
    }
}
