<?php

declare(strict_types=1);

namespace Doloto\Big0nia\Rule;

use Doloto\Big0nia\Ast\CanonicalForLoopMatcher;
use Doloto\Big0nia\Ast\CollectionSize;
use Doloto\Big0nia\Ast\CollectionSizeClassifier;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\For_;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\If_;

final class ArrayMergeInLoopRule implements LoopRule
{
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

        $assign = $this->findSelfMergeAssignment($loopNode->stmts);
        if ($assign === null) {
            return null;
        }

        if ($this->iteratesAProvablySmallCollection($loopNode, $precedingStmts)) {
            return null;
        }

        /** @var Variable $var */
        $var = $assign->var;
        /** @var string $varName */
        $varName = $var->name;

        $message = sprintf(
            'Potential O(n²) algorithm: array_merge() rebuilds $%s from scratch on every iteration.',
            $varName
        );

        $tip = sprintf(
            'Replace array_merge($%s, [...]) with $%s[] = ... (or an equivalent append), or build the pieces separately and merge once after the loop.',
            $varName,
            $varName
        );

        return new Finding($loopNode->getLine(), $message, $tip);
    }

    /**
     * @param Stmt[] $stmts
     */
    private function findSelfMergeAssignment(array $stmts): ?Assign
    {
        foreach ($stmts as $stmt) {
            if ($stmt instanceof Expression && $stmt->expr instanceof Assign && $this->isSelfReferentialMerge($stmt->expr)) {
                return $stmt->expr;
            }

            if ($stmt instanceof If_) {
                $found = $this->findSelfMergeAssignment($stmt->stmts);
                if ($found !== null) {
                    return $found;
                }

                foreach ($stmt->elseifs as $elseif) {
                    $found = $this->findSelfMergeAssignment($elseif->stmts);
                    if ($found !== null) {
                        return $found;
                    }
                }

                if ($stmt->else !== null) {
                    $found = $this->findSelfMergeAssignment($stmt->else->stmts);
                    if ($found !== null) {
                        return $found;
                    }
                }
            }
        }

        return null;
    }

    private function isSelfReferentialMerge(Assign $assign): bool
    {
        if (!$assign->var instanceof Variable || !is_string($assign->var->name)) {
            return false;
        }

        if (!$assign->expr instanceof FuncCall || !$assign->expr->name instanceof Name) {
            return false;
        }

        if ($assign->expr->name->toString() !== 'array_merge') {
            return false;
        }

        $targetName = $assign->var->name;

        foreach ($assign->expr->args as $arg) {
            if ($arg instanceof Arg && $arg->value instanceof Variable && $arg->value->name === $targetName) {
                return true;
            }
        }

        return false;
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
