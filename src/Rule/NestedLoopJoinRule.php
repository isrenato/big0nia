<?php

declare(strict_types=1);

namespace Doloto\Big0nia\Rule;

use Doloto\Big0nia\Ast\CollectionSize;
use Doloto\Big0nia\Ast\CollectionSizeClassifier;
use Doloto\Big0nia\Ast\JoinSignatureMatcher;
use Doloto\Big0nia\Ast\NestedForeachFinder;
use Doloto\Big0nia\Complexity\ComplexityLabel;
use PhpParser\Node;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Foreach_;

final class NestedLoopJoinRule
{
    private NestedForeachFinder $foreachFinder;
    private JoinSignatureMatcher $joinMatcher;
    private CollectionSizeClassifier $sizeClassifier;

    public function __construct()
    {
        $this->foreachFinder = new NestedForeachFinder();
        $this->joinMatcher = new JoinSignatureMatcher();
        $this->sizeClassifier = new CollectionSizeClassifier();
    }

    /**
     * @param Stmt[] $precedingStmts Statements preceding the outer loop in its own enclosing statement list.
     */
    public function check(Foreach_ $node, array $precedingStmts): ?Finding
    {
        $inner = $this->foreachFinder->find($node->stmts);
        if ($inner === null) {
            return null;
        }

        if (!$node->valueVar instanceof Variable || !is_string($node->valueVar->name)) {
            return null;
        }
        if (!$inner->valueVar instanceof Variable || !is_string($inner->valueVar->name)) {
            return null;
        }

        $outerVarName = $node->valueVar->name;
        $innerVarName = $inner->valueVar->name;

        $signature = $this->joinMatcher->find($inner->stmts, $outerVarName, $innerVarName);
        if ($signature === null) {
            return null;
        }

        $outerClass = $this->sizeClassifier->classify($node->expr, $precedingStmts);
        $innerClass = $this->sizeClassifier->classify($inner->expr, $precedingStmts);

        if ($outerClass === CollectionSize::FixedSmall || $innerClass === CollectionSize::FixedSmall) {
            return null;
        }

        $outerCollectionName = $this->exprLabel($node->expr) ?? $outerVarName;
        $innerCollectionName = $this->exprLabel($inner->expr) ?? $innerVarName;
        $sameCollection = $outerCollectionName === $innerCollectionName;

        $before = ComplexityLabel::forJoin($outerCollectionName, $innerCollectionName, $sameCollection);
        $after = ComplexityLabel::indexedForm($outerCollectionName, $innerCollectionName, $sameCollection);

        $message = sprintf(
            'Potential %s algorithm: every %s is compared against every %s using %s vs %s. Estimated complexity: %s.',
            $sameCollection ? 'O(n²)' : 'O(n × m)',
            $outerVarName,
            $innerVarName,
            $signature->outerDisplay,
            $signature->innerDisplay,
            $before
        );

        $tip = sprintf(
            'Index %s by %s before the loop, then look up matches instead of scanning. Possible complexity after optimization: %s.',
            $innerCollectionName,
            $signature->innerKey,
            $after
        );

        return new Finding($node->getLine(), $message, $tip);
    }

    private function exprLabel(Node\Expr $expr): ?string
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
