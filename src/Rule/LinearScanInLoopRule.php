<?php

declare(strict_types=1);

namespace Doloto\Big0nia\Rule;

use Doloto\Big0nia\Ast\CanonicalForLoopMatcher;
use Doloto\Big0nia\Ast\CollectionSize;
use Doloto\Big0nia\Ast\CollectionSizeClassifier;
use Doloto\Big0nia\Ast\JoinSignatureMatcher;
use Doloto\Big0nia\Ast\LoopEarlyExitAnalyzer;
use Doloto\Big0nia\Complexity\ComplexityLabel;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\BinaryOp\BooleanAnd;
use PhpParser\Node\Expr\BinaryOp\BooleanOr;
use PhpParser\Node\Expr\BooleanNot;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\For_;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\If_;

final class LinearScanInLoopRule implements LoopRule
{
    private const SCAN_FUNCTIONS = ['in_array', 'array_search'];

    private CanonicalForLoopMatcher $forLoopMatcher;
    private JoinSignatureMatcher $joinMatcher;
    private CollectionSizeClassifier $sizeClassifier;
    private LoopEarlyExitAnalyzer $earlyExitAnalyzer;

    public function __construct()
    {
        $this->forLoopMatcher = new CanonicalForLoopMatcher();
        $this->joinMatcher = new JoinSignatureMatcher();
        $this->sizeClassifier = new CollectionSizeClassifier();
        $this->earlyExitAnalyzer = new LoopEarlyExitAnalyzer();
    }

    /**
     * @param Stmt[] $precedingStmts
     */
    public function check(Stmt $loopNode, array $precedingStmts): ?Finding
    {
        if ($loopNode instanceof Foreach_) {
            return $this->checkForeach($loopNode, $precedingStmts);
        }

        if ($loopNode instanceof For_) {
            return $this->checkFor($loopNode, $precedingStmts);
        }

        return null;
    }

    /**
     * @param Stmt[] $precedingStmts
     */
    private function checkForeach(Foreach_ $loopNode, array $precedingStmts): ?Finding
    {
        if (!$loopNode->valueVar instanceof Variable || !is_string($loopNode->valueVar->name)) {
            return null;
        }

        $needleVarName = $loopNode->valueVar->name;
        $call = $this->findScanCall(
            $loopNode->stmts,
            fn (Expr $needle): bool => $this->joinMatcher->isRootedInVar($needle, $needleVarName)
        );
        if ($call === null) {
            return null;
        }

        $outerCollectionName = $this->exprLabel($loopNode->expr) ?? $needleVarName;

        return $this->buildFinding($loopNode->getLine(), $outerCollectionName, $needleVarName, $call, $precedingStmts, $loopNode->stmts);
    }

    /**
     * @param Stmt[] $precedingStmts
     */
    private function checkFor(For_ $loopNode, array $precedingStmts): ?Finding
    {
        $binding = $this->forLoopMatcher->match($loopNode);
        if ($binding === null) {
            return null;
        }

        $call = $this->findScanCall(
            $loopNode->stmts,
            fn (Expr $needle): bool => $this->joinMatcher->isRootedInIndexedAccess($needle, $binding)
        );
        if ($call === null) {
            return null;
        }

        $outerLabel = sprintf('%s[%s]', $binding->collectionVarName, $binding->indexVarName);

        return $this->buildFinding($loopNode->getLine(), $binding->collectionVarName, $outerLabel, $call, $precedingStmts, $loopNode->stmts);
    }

    /**
     * @param Stmt[] $precedingStmts
     * @param Stmt[] $loopStmts
     */
    private function buildFinding(
        int $line,
        string $outerCollectionName,
        string $outerLabel,
        FuncCall $call,
        array $precedingStmts,
        array $loopStmts
    ): ?Finding {
        if ($this->earlyExitAnalyzer->boundsToOnePass($loopStmts)) {
            return null;
        }

        $haystackArg = $call->args[1] ?? null;
        if (!$haystackArg instanceof Arg) {
            return null;
        }

        $haystackClass = $this->sizeClassifier->classify($haystackArg->value, $precedingStmts);
        if ($haystackClass === CollectionSize::FixedSmall) {
            return null;
        }

        $haystackName = $this->exprLabel($haystackArg->value) ?? 'the collection';
        $funcName = $call->name instanceof Name ? $call->name->toLowerString() : 'in_array';
        $sameCollection = $outerCollectionName === $haystackName;

        $before = ComplexityLabel::forJoin($outerCollectionName, $haystackName, $sameCollection);
        $after = ComplexityLabel::indexedForm($outerCollectionName, $haystackName, $sameCollection);

        $message = sprintf(
            'Potential %s algorithm: every %s is checked against %s using %s(). Estimated complexity: %s.',
            $sameCollection ? 'O(n²)' : 'O(n × m)',
            $outerLabel,
            $haystackName,
            $funcName,
            $before
        );

        $tip = sprintf(
            'Flip %s into a lookup map (e.g. array_flip() or keyed by the value being searched) before the loop, then use isset()/array_key_exists() instead of %s(). Possible complexity after optimization: %s.',
            $haystackName,
            $funcName,
            $after
        );

        return new Finding($line, $message, $tip);
    }

    /**
     * @param Stmt[] $stmts
     * @param callable(Expr): bool $isNeedleRooted
     */
    private function findScanCall(array $stmts, callable $isNeedleRooted): ?FuncCall
    {
        foreach ($stmts as $stmt) {
            if (!$stmt instanceof If_) {
                continue;
            }

            $call = $this->findScanCallInExpr($stmt->cond, $isNeedleRooted);
            if ($call !== null) {
                return $call;
            }

            $nested = $this->findScanCall($stmt->stmts, $isNeedleRooted);
            if ($nested !== null) {
                return $nested;
            }
        }

        return null;
    }

    /**
     * @param callable(Expr): bool $isNeedleRooted
     */
    private function findScanCallInExpr(Expr $expr, callable $isNeedleRooted): ?FuncCall
    {
        if ($expr instanceof BooleanAnd || $expr instanceof BooleanOr) {
            return $this->findScanCallInExpr($expr->left, $isNeedleRooted) ?? $this->findScanCallInExpr($expr->right, $isNeedleRooted);
        }

        if ($expr instanceof BooleanNot) {
            return $this->findScanCallInExpr($expr->expr, $isNeedleRooted);
        }

        if (!$expr instanceof FuncCall || !$expr->name instanceof Name) {
            return null;
        }

        if (!in_array($expr->name->toLowerString(), self::SCAN_FUNCTIONS, true)) {
            return null;
        }

        if (count($expr->args) < 2 || !$expr->args[0] instanceof Arg) {
            return null;
        }

        return $isNeedleRooted($expr->args[0]->value) ? $expr : null;
    }

    private function exprLabel(Expr $expr): ?string
    {
        if ($expr instanceof Variable && is_string($expr->name)) {
            return $expr->name;
        }

        if ($expr instanceof PropertyFetch && $expr->name instanceof Identifier) {
            return $this->exprLabel($expr->var) . '->' . $expr->name->toString();
        }

        return null;
    }
}
