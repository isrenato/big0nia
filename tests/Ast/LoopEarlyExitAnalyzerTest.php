<?php

declare(strict_types=1);

namespace Doloto\Big0nia\Tests\Ast;

use Doloto\Big0nia\Ast\LoopEarlyExitAnalyzer;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\Throw_;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Stmt\Break_;
use PhpParser\Node\Stmt\Continue_;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\If_;
use PhpParser\Node\Stmt\Return_;
use PHPUnit\Framework\TestCase;

final class LoopEarlyExitAnalyzerTest extends TestCase
{
    public function testUnconditionalBreakBoundsTheLoopToOnePass(): void
    {
        $analyzer = new LoopEarlyExitAnalyzer();

        $stmts = [
            new Expression(new Assign(new Variable('x'), new Variable('y'))),
            new Break_(),
        ];

        self::assertTrue($analyzer->boundsToOnePass($stmts));
    }

    public function testUnconditionalReturnBoundsTheLoopToOnePass(): void
    {
        $analyzer = new LoopEarlyExitAnalyzer();

        self::assertTrue($analyzer->boundsToOnePass([new Return_()]));
    }

    public function testUnconditionalThrowBoundsTheLoopToOnePass(): void
    {
        $analyzer = new LoopEarlyExitAnalyzer();

        $stmts = [new Expression(new Throw_(new Variable('e')))];

        self::assertTrue($analyzer->boundsToOnePass($stmts));
    }

    public function testPlainBreakWithExplicitLevelOneIsRecognized(): void
    {
        $analyzer = new LoopEarlyExitAnalyzer();

        self::assertTrue($analyzer->boundsToOnePass([new Break_(new Int_(1))]));
    }

    public function testBreakWithLevelTwoIsNotRecognized(): void
    {
        $analyzer = new LoopEarlyExitAnalyzer();

        self::assertFalse($analyzer->boundsToOnePass([new Break_(new Int_(2))]));
    }

    public function testConditionalBreakInsideAnIfDoesNotBoundTheLoop(): void
    {
        $analyzer = new LoopEarlyExitAnalyzer();

        $stmts = [new If_(new Variable('matched'), ['stmts' => [new Break_()]])];

        self::assertFalse($analyzer->boundsToOnePass($stmts));
    }

    public function testContinueDoesNotBoundTheLoop(): void
    {
        $analyzer = new LoopEarlyExitAnalyzer();

        self::assertFalse($analyzer->boundsToOnePass([new Continue_()]));
    }

    public function testEmptyBodyDoesNotBoundTheLoop(): void
    {
        $analyzer = new LoopEarlyExitAnalyzer();

        self::assertFalse($analyzer->boundsToOnePass([]));
    }

    public function testOrdinaryStatementsWithNoExitDoNotBoundTheLoop(): void
    {
        $analyzer = new LoopEarlyExitAnalyzer();

        $stmts = [new Expression(new Assign(new Variable('x'), new Variable('y')))];

        self::assertFalse($analyzer->boundsToOnePass($stmts));
    }
}
