<?php

declare(strict_types=1);

namespace Doloto\Big0nia\Tests\Rule;

use Doloto\Big0nia\Rule\RepeatedSortInLoopRule;
use PhpParser\Node\Arg;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrowFunction;
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

final class RepeatedSortInLoopRuleTest extends TestCase
{
    public function testReportsUsortOnALoopInvariantVariableInsideForeach(): void
    {
        $rule = new RepeatedSortInLoopRule();

        $loop = new Foreach_(new Variable('items'), new Variable('item'), [
            'stmts' => [$this->sortStmt('usort', 'data')],
        ]);

        $finding = $rule->check($loop, []);

        self::assertNotNull($finding);
        self::assertStringContainsString('usort($data', $finding->message);
    }

    public function testReportsUasortInsideCanonicalFor(): void
    {
        $rule = new RepeatedSortInLoopRule();

        $loop = $this->buildForLoop('items', 'i', [$this->sortStmt('uasort', 'data')]);

        $finding = $rule->check($loop, []);

        self::assertNotNull($finding);
    }

    public function testDoesNotReportWhenTargetIsReassignedInTheLoop(): void
    {
        $rule = new RepeatedSortInLoopRule();

        $loop = new Foreach_(new Variable('groups'), new Variable('group'), [
            'stmts' => [
                new Expression(new Assign(new Variable('data'), new Variable('group'))),
                $this->sortStmt('usort', 'data'),
            ],
        ]);

        self::assertNull($rule->check($loop, []));
    }

    public function testDoesNotReportWhenNoSortCallIsPresent(): void
    {
        $rule = new RepeatedSortInLoopRule();

        $loop = new Foreach_(new Variable('items'), new Variable('item'), ['stmts' => []]);

        self::assertNull($rule->check($loop, []));
    }

    public function testDoesNotReportBuiltInSortWithoutComparator(): void
    {
        $rule = new RepeatedSortInLoopRule();

        $stmt = new Expression(new FuncCall(new Name('sort'), [new Arg(new Variable('data'))]));
        $loop = new Foreach_(new Variable('items'), new Variable('item'), ['stmts' => [$stmt]]);

        self::assertNull($rule->check($loop, []));
    }

    public function testDoesNotReportForANonLoopNode(): void
    {
        $rule = new RepeatedSortInLoopRule();

        self::assertNull($rule->check(new Expression(new Variable('x')), []));
    }

    public function testDetectsSortNestedInsideIfElseifElse(): void
    {
        $rule = new RepeatedSortInLoopRule();

        $ifStmt = new If_(new Identical(new Variable('a'), new Variable('b')), [
            'stmts' => [],
            'elseifs' => [],
            'else' => new Else_([$this->sortStmt('usort', 'data')]),
        ]);
        $loop = new Foreach_(new Variable('items'), new Variable('item'), ['stmts' => [$ifStmt]]);

        self::assertNotNull($rule->check($loop, []));
    }

    public function testDoesNotDetectSortInsideANestedLoop(): void
    {
        $rule = new RepeatedSortInLoopRule();

        $inner = new Foreach_(new Variable('subItems'), new Variable('sub'), [
            'stmts' => [$this->sortStmt('usort', 'data')],
        ]);
        $outer = new Foreach_(new Variable('items'), new Variable('item'), ['stmts' => [$inner]]);

        self::assertNull($rule->check($outer, []));
    }

    public function testDoesNotReportWhenTargetIsReassignedInsideANestedLoop(): void
    {
        $rule = new RepeatedSortInLoopRule();

        $reassignInner = new Foreach_(new Variable('subItems'), new Variable('sub'), [
            'stmts' => [new Expression(new Assign(new Variable('data'), new Variable('sub')))],
        ]);
        $loop = new Foreach_(new Variable('items'), new Variable('item'), [
            'stmts' => [$this->sortStmt('usort', 'data'), $reassignInner],
        ]);

        self::assertNull($rule->check($loop, []));
    }

    public function testSuppressesWhenForeachIteratesAFixedSmallArrayLiteral(): void
    {
        $rule = new RepeatedSortInLoopRule();

        $small = new Array_([new ArrayItem(new Variable('a')), new ArrayItem(new Variable('b'))]);
        $loop = new Foreach_($small, new Variable('item'), [
            'stmts' => [$this->sortStmt('usort', 'data')],
        ]);

        self::assertNull($rule->check($loop, []));
    }

    private function sortStmt(string $function, string $varName): Expression
    {
        return new Expression(new FuncCall(new Name($function), [
            new Arg(new Variable($varName)),
            new Arg(new ArrowFunction([
                'params' => [],
                'expr' => new Int_(0),
            ])),
        ]));
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
