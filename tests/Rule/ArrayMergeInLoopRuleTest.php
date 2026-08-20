<?php

declare(strict_types=1);

namespace Doloto\Big0nia\Tests\Rule;

use Doloto\Big0nia\Rule\ArrayMergeInLoopRule;
use PhpParser\Node\Arg;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\BinaryOp\Identical;
use PhpParser\Node\Expr\BinaryOp\Smaller;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\PostInc;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Stmt\Else_;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\For_;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\If_;
use PHPUnit\Framework\TestCase;

final class ArrayMergeInLoopRuleTest extends TestCase
{
    public function testReportsSelfReferentialMergeInsideForeach(): void
    {
        $rule = new ArrayMergeInLoopRule();

        $loop = new Foreach_(new Variable('items'), new Variable('item'), [
            'stmts' => [$this->selfMergeStmt('result', 'item')],
        ]);

        $finding = $rule->check($loop, []);

        self::assertNotNull($finding);
        self::assertStringContainsString('$result', $finding->message);
    }

    public function testReportsSelfReferentialMergeInsideCanonicalFor(): void
    {
        $rule = new ArrayMergeInLoopRule();

        $loop = $this->buildForLoop('items', 'i', [$this->selfMergeStmt('result', 'item')]);

        $finding = $rule->check($loop, []);

        self::assertNotNull($finding);
    }

    public function testDoesNotReportUnrelatedArrayMergeCall(): void
    {
        $rule = new ArrayMergeInLoopRule();

        $stmt = new Expression(new Assign(
            new Variable('combined'),
            new FuncCall(new Name('array_merge'), [new Arg(new Variable('a')), new Arg(new Variable('b'))])
        ));
        $loop = new Foreach_(new Variable('items'), new Variable('item'), ['stmts' => [$stmt]]);

        self::assertNull($rule->check($loop, []));
    }

    public function testDoesNotReportWhenNoArrayMergeIsPresent(): void
    {
        $rule = new ArrayMergeInLoopRule();

        $loop = new Foreach_(new Variable('items'), new Variable('item'), ['stmts' => []]);

        self::assertNull($rule->check($loop, []));
    }

    public function testDoesNotReportForANonLoopNode(): void
    {
        $rule = new ArrayMergeInLoopRule();

        self::assertNull($rule->check(new Expression(new Variable('x')), []));
    }

    public function testDetectsMergeNestedInsideIfElseifElse(): void
    {
        $rule = new ArrayMergeInLoopRule();

        $ifStmt = new If_(new Identical(new Variable('a'), new Variable('b')), [
            'stmts' => [],
            'elseifs' => [],
            'else' => new Else_([$this->selfMergeStmt('result', 'item')]),
        ]);
        $loop = new Foreach_(new Variable('items'), new Variable('item'), ['stmts' => [$ifStmt]]);

        $finding = $rule->check($loop, []);

        self::assertNotNull($finding);
    }

    public function testDoesNotDetectMergeInsideANestedLoop(): void
    {
        $rule = new ArrayMergeInLoopRule();

        $inner = new Foreach_(new Variable('subItems'), new Variable('sub'), [
            'stmts' => [$this->selfMergeStmt('result', 'sub')],
        ]);
        $outer = new Foreach_(new Variable('items'), new Variable('item'), ['stmts' => [$inner]]);

        self::assertNull($rule->check($outer, []));
    }

    public function testSuppressesWhenForeachIteratesAFixedSmallArrayLiteral(): void
    {
        $rule = new ArrayMergeInLoopRule();

        $small = new Array_([new ArrayItem(new Variable('a')), new ArrayItem(new Variable('b'))]);
        $loop = new Foreach_($small, new Variable('item'), [
            'stmts' => [$this->selfMergeStmt('result', 'item')],
        ]);

        self::assertNull($rule->check($loop, []));
    }

    private function selfMergeStmt(string $resultVar, string $itemVar): Expression
    {
        return new Expression(new Assign(
            new Variable($resultVar),
            new FuncCall(new Name('array_merge'), [
                new Arg(new Variable($resultVar)),
                new Arg(new Array_([new ArrayItem(new Variable($itemVar))])),
            ])
        ));
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
