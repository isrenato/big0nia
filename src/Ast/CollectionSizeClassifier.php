<?php

declare(strict_types=1);

namespace Doloto\Big0nia\Ast;

use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Expression;

final class CollectionSizeClassifier
{
    private const MAX_FIXED_SIZE = 5;

    private ClassMemberResolver $memberResolver;

    public function __construct()
    {
        $this->memberResolver = new ClassMemberResolver();
    }

    /**
     * @param Stmt[] $precedingStmts
     */
    public function classify(Expr $expr, array $precedingStmts): CollectionSize
    {
        if ($expr instanceof Array_) {
            return $this->classifyBySize($expr);
        }

        if ($expr instanceof Variable && is_string($expr->name)) {
            $literal = $this->findLastArrayAssignment($expr->name, $precedingStmts);
            if ($literal !== null) {
                return $this->classifyBySize($literal);
            }
        }

        if ($expr instanceof PropertyFetch && $this->isThis($expr->var) && $expr->name instanceof Identifier) {
            $literal = $this->memberResolver->findPropertyDefaultArray($expr, $expr->name->toString());
            if ($literal !== null) {
                return $this->classifyBySize($literal);
            }
        }

        if ($expr instanceof MethodCall && $this->isThis($expr->var) && $expr->name instanceof Identifier) {
            $literal = $this->memberResolver->findMethodReturnArray($expr, $expr->name->toString());
            if ($literal !== null) {
                return $this->classifyBySize($literal);
            }
        }

        return CollectionSize::Unknown;
    }

    private function isThis(Expr $expr): bool
    {
        return $expr instanceof Variable && $expr->name === 'this';
    }

    private function classifyBySize(Array_ $array): CollectionSize
    {
        if (count($array->items) === 0) {
            return CollectionSize::Unknown;
        }

        return count($array->items) <= self::MAX_FIXED_SIZE ? CollectionSize::FixedSmall : CollectionSize::Unbounded;
    }

    /**
     * @param Stmt[] $stmts
     */
    private function findLastArrayAssignment(string $varName, array $stmts): ?Array_
    {
        $found = null;

        foreach ($stmts as $stmt) {
            if (!$stmt instanceof Expression) {
                continue;
            }

            $expr = $stmt->expr;
            if (!$expr instanceof Assign) {
                continue;
            }

            if (!$expr->var instanceof Variable || $expr->var->name !== $varName) {
                continue;
            }

            $found = $expr->expr instanceof Array_ ? $expr->expr : null;
        }

        return $found;
    }
}
