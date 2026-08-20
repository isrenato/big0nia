<?php

declare(strict_types=1);

namespace Doloto\Big0nia\Rule;

use Doloto\Big0nia\Ast\CanonicalForLoopMatcher;
use Doloto\Big0nia\Ast\CollectionSize;
use Doloto\Big0nia\Ast\CollectionSizeClassifier;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\AssignOp;
use PhpParser\Node\Expr\AssignRef;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\For_;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\If_;

final class RepeatedSortInLoopRule implements LoopRule
{
    private const SORT_FUNCTIONS = ['usort', 'uasort', 'uksort'];

    private CanonicalForLoopMatcher $forLoopMatcher;
    private CollectionSizeClassifier $sizeClassifier;

    public function __construct()
    {
        $this->forLoopMatcher = new CanonicalForLoopMatcher();
        $this->sizeClassifier = new CollectionSizeClassifier();
    }

    /**
     * @param Stmt[] $precedingStmts
     */
    public function check(Stmt $loopNode, array $precedingStmts): ?Finding
    {
        if (!$loopNode instanceof Foreach_ && !$loopNode instanceof For_) {
            return null;
        }

        $call = $this->findSortCall($loopNode->stmts);
        if ($call === null) {
            return null;
        }

        /** @var Arg $firstArg */
        $firstArg = $call->args[0];
        /** @var Variable $targetVar */
        $targetVar = $firstArg->value;
        /** @var string $varName */
        $varName = $targetVar->name;

        if ($this->isReassignedInSubtree($loopNode->stmts, $varName)) {
            return null;
        }

        if ($this->iteratesAProvablySmallCollection($loopNode, $precedingStmts)) {
            return null;
        }

        /** @var Name $funcName */
        $funcName = $call->name;
        $function = $funcName->toString();

        $message = sprintf(
            'Potential wasted work: %s($%s, ...) re-sorts $%s on every iteration, but $%s is never modified inside this loop.',
            $function,
            $varName,
            $varName,
            $varName
        );

        $tip = sprintf(
            'Move %s($%s, ...) above the loop — sorting an already-sorted, unchanged array repeatedly wastes work per iteration for no benefit.',
            $function,
            $varName
        );

        return new Finding($call->getLine(), $message, $tip);
    }

    /**
     * @param Stmt[] $stmts
     */
    private function findSortCall(array $stmts): ?FuncCall
    {
        foreach ($stmts as $stmt) {
            if ($stmt instanceof Expression && $stmt->expr instanceof FuncCall && $this->isSortCall($stmt->expr)) {
                return $stmt->expr;
            }

            if ($stmt instanceof If_) {
                $found = $this->findSortCall($stmt->stmts);
                if ($found !== null) {
                    return $found;
                }

                foreach ($stmt->elseifs as $elseif) {
                    $found = $this->findSortCall($elseif->stmts);
                    if ($found !== null) {
                        return $found;
                    }
                }

                if ($stmt->else !== null) {
                    $found = $this->findSortCall($stmt->else->stmts);
                    if ($found !== null) {
                        return $found;
                    }
                }
            }
        }

        return null;
    }

    private function isSortCall(FuncCall $call): bool
    {
        if (!$call->name instanceof Name || !in_array($call->name->toString(), self::SORT_FUNCTIONS, true)) {
            return false;
        }

        return isset($call->args[0]) && $call->args[0] instanceof Arg && $call->args[0]->value instanceof Variable;
    }

    /**
     * @param Node[] $nodes
     */
    private function isReassignedInSubtree(array $nodes, string $varName): bool
    {
        foreach ($nodes as $node) {
            if ($this->isReassignmentOf($node, $varName)) {
                return true;
            }

            foreach ($node->getSubNodeNames() as $subNodeName) {
                $subNode = $node->$subNodeName;

                if ($subNode instanceof Node && $this->isReassignedInSubtree([$subNode], $varName)) {
                    return true;
                }

                if (is_array($subNode)) {
                    $childNodes = array_filter($subNode, static fn ($item): bool => $item instanceof Node);
                    if ($this->isReassignedInSubtree($childNodes, $varName)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    private function isReassignmentOf(Node $node, string $varName): bool
    {
        if (!$node instanceof Assign && !$node instanceof AssignOp && !$node instanceof AssignRef) {
            return false;
        }

        return $this->isRootedInVariable($node->var, $varName);
    }

    private function isRootedInVariable(Node $node, string $varName): bool
    {
        if ($node instanceof Variable && $node->name === $varName) {
            return true;
        }

        return $node instanceof ArrayDimFetch && $this->isRootedInVariable($node->var, $varName);
    }

    /**
     * @param Stmt[] $precedingStmts
     */
    private function iteratesAProvablySmallCollection(Foreach_|For_ $loopNode, array $precedingStmts): bool
    {
        if ($loopNode instanceof Foreach_) {
            return $this->sizeClassifier->classify($loopNode->expr, $precedingStmts) === CollectionSize::FixedSmall;
        }

        $binding = $this->forLoopMatcher->match($loopNode);
        if ($binding === null) {
            return false;
        }

        return $this->sizeClassifier->classify(new Variable($binding->collectionVarName), $precedingStmts) === CollectionSize::FixedSmall;
    }
}
