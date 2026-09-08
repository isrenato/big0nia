<?php

declare(strict_types=1);

namespace Doloto\Big0nia\Ast;

use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Expression;

final class CollectionSizeClassifier
{
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

        if ($expr instanceof ClassConstFetch && $this->isSelfOrStatic($expr->class) && $expr->name instanceof Identifier) {
            $literal = $this->memberResolver->findClassConstArray($expr, $expr->name->toString());
            if ($literal !== null) {
                return $this->classifyBySize($literal);
            }
        }

        if ($expr instanceof FuncCall && $this->isFixedRangeCall($expr)) {
            return CollectionSize::FixedSmall;
        }

        return CollectionSize::Unknown;
    }

    private function isThis(Expr $expr): bool
    {
        return $expr instanceof Variable && $expr->name === 'this';
    }

    private function classifyBySize(Array_ $array): CollectionSize
    {
        return count($array->items) === 0 ? CollectionSize::Unknown : CollectionSize::FixedSmall;
    }

    private function isSelfOrStatic(\PhpParser\Node $classRef): bool
    {
        return $classRef instanceof Name && in_array($classRef->toLowerString(), ['self', 'static'], true);
    }

    private function isFixedRangeCall(FuncCall $call): bool
    {
        if (!$call->name instanceof Name || $call->name->toLowerString() !== 'range' || count($call->args) < 2) {
            return false;
        }

        $start = $call->args[0];
        $end = $call->args[1];

        return $start instanceof Arg && $end instanceof Arg
            && $this->isLiteralScalar($start->value)
            && $this->isLiteralScalar($end->value);
    }

    private function isLiteralScalar(Expr $expr): bool
    {
        return $expr instanceof Int_ || $expr instanceof String_;
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
