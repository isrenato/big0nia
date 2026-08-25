<?php

declare(strict_types=1);

namespace Doloto\Big0nia\Ast;

use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\BinaryOp\BooleanAnd;
use PhpParser\Node\Expr\BinaryOp\Equal;
use PhpParser\Node\Expr\BinaryOp\Identical;
use PhpParser\Node\Expr\BinaryOp\NotEqual;
use PhpParser\Node\Expr\BinaryOp\NotIdentical;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\If_;

final class JoinSignatureMatcher
{
    /**
     * @param Stmt[] $stmts
     */
    public function find(array $stmts, string $outerVarName, string $innerVarName): ?JoinSignature
    {
        return $this->findWithMatcher(
            $stmts,
            fn (Expr $outerSide, Expr $innerSide): ?JoinSignature => $this->match($outerSide, $outerVarName, $innerSide, $innerVarName)
        );
    }

    /**
     * @param Stmt[] $stmts
     */
    public function findIndexed(array $stmts, ForLoopBinding $outer, ForLoopBinding $inner): ?JoinSignature
    {
        return $this->findWithMatcher(
            $stmts,
            fn (Expr $outerSide, Expr $innerSide): ?JoinSignature => $this->matchIndexed($outerSide, $outer, $innerSide, $inner)
        );
    }

    /**
     * @param Stmt[] $stmts
     */
    public function findVariableAgainstIndexed(array $stmts, string $outerVarName, ForLoopBinding $inner): ?JoinSignature
    {
        return $this->findWithMatcher(
            $stmts,
            fn (Expr $outerSide, Expr $innerSide): ?JoinSignature => $this->matchVariableAgainstIndexed($outerSide, $outerVarName, $innerSide, $inner)
        );
    }

    /**
     * @param Stmt[] $stmts
     * @param callable(Expr, Expr): ?JoinSignature $matcher
     */
    private function findWithMatcher(array $stmts, callable $matcher): ?JoinSignature
    {
        foreach ($stmts as $stmt) {
            if (!$stmt instanceof If_) {
                continue;
            }

            $cond = $this->findComparison($stmt->cond);
            if ($cond === null) {
                continue;
            }

            $signature = $matcher($cond->left, $cond->right) ?? $matcher($cond->right, $cond->left);

            if ($signature !== null) {
                return $signature;
            }
        }

        return null;
    }

    private function matchIndexed(Expr $outerSide, ForLoopBinding $outer, Expr $innerSide, ForLoopBinding $inner): ?JoinSignature
    {
        if (!$this->isRootedInIndexedAccess($outerSide, $outer) || !$this->isRootedInIndexedAccess($innerSide, $inner)) {
            return null;
        }

        $outerDesc = $this->describe($outerSide);
        $innerDesc = $this->describe($innerSide);

        if ($outerDesc === null || $innerDesc === null) {
            return null;
        }

        return new JoinSignature($outerDesc[0], $innerDesc[0], $innerDesc[1]);
    }

    private function matchVariableAgainstIndexed(Expr $outerSide, string $outerVarName, Expr $innerSide, ForLoopBinding $inner): ?JoinSignature
    {
        if (!$this->isRootedInVar($outerSide, $outerVarName) || !$this->isRootedInIndexedAccess($innerSide, $inner)) {
            return null;
        }

        $outerDesc = $this->describe($outerSide);
        $innerDesc = $this->describe($innerSide);

        if ($outerDesc === null || $innerDesc === null) {
            return null;
        }

        return new JoinSignature($outerDesc[0], $innerDesc[0], $innerDesc[1]);
    }

    public function isRootedInIndexedAccess(Expr $expr, ForLoopBinding $binding): bool
    {
        if (
            $expr instanceof ArrayDimFetch
            && $expr->var instanceof Variable
            && $expr->var->name === $binding->collectionVarName
            && $expr->dim instanceof Variable
            && $expr->dim->name === $binding->indexVarName
        ) {
            return true;
        }

        if ($expr instanceof MethodCall || $expr instanceof PropertyFetch) {
            return $this->isRootedInIndexedAccess($expr->var, $binding);
        }

        return false;
    }

    private function findComparison(Expr $expr): Equal|Identical|NotEqual|NotIdentical|null
    {
        if ($this->isComparison($expr)) {
            /** @var Equal|Identical|NotEqual|NotIdentical $expr */
            return $expr;
        }

        if ($expr instanceof BooleanAnd) {
            return $this->findComparison($expr->left) ?? $this->findComparison($expr->right);
        }

        return null;
    }

    private function isComparison(Expr $expr): bool
    {
        return $expr instanceof Identical
            || $expr instanceof Equal
            || $expr instanceof NotIdentical
            || $expr instanceof NotEqual;
    }

    private function match(Expr $outerSide, string $outerVarName, Expr $innerSide, string $innerVarName): ?JoinSignature
    {
        if (!$this->isRootedInVar($outerSide, $outerVarName) || !$this->isRootedInVar($innerSide, $innerVarName)) {
            return null;
        }

        $outer = $this->describe($outerSide);
        $inner = $this->describe($innerSide);

        if ($outer === null || $inner === null) {
            return null;
        }

        return new JoinSignature($outer[0], $inner[0], $inner[1]);
    }

    public function isRootedInVar(Expr $expr, string $varName): bool
    {
        if ($expr instanceof Variable && $expr->name === $varName) {
            return true;
        }

        if ($expr instanceof MethodCall || $expr instanceof PropertyFetch) {
            return $this->isRootedInVar($expr->var, $varName);
        }

        return false;
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private function describe(Expr $expr): ?array
    {
        if ($expr instanceof MethodCall && $expr->name instanceof Identifier) {
            $name = $expr->name->toString();

            return [$name . '()', $this->normalizeFieldName($name)];
        }

        if ($expr instanceof PropertyFetch && $expr->name instanceof Identifier) {
            $name = $expr->name->toString();

            return [$name, $name];
        }

        return null;
    }

    private function normalizeFieldName(string $methodName): string
    {
        if (str_starts_with($methodName, 'get') && strlen($methodName) > 3) {
            return lcfirst(substr($methodName, 3));
        }

        return $methodName;
    }
}
