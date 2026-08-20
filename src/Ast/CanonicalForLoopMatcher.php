<?php

declare(strict_types=1);

namespace Doloto\Big0nia\Ast;

use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\BinaryOp\Smaller;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\PostInc;
use PhpParser\Node\Expr\PreInc;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Stmt\For_;

final class CanonicalForLoopMatcher
{
    public function match(For_ $for): ?ForLoopBinding
    {
        if (count($for->init) !== 1 || count($for->cond) !== 1 || count($for->loop) !== 1) {
            return null;
        }

        $indexVarName = $this->matchInit($for->init[0]);
        if ($indexVarName === null) {
            return null;
        }

        $collectionVarName = $this->matchCond($for->cond[0], $indexVarName);
        if ($collectionVarName === null) {
            return null;
        }

        if (!$this->matchIncrement($for->loop[0], $indexVarName)) {
            return null;
        }

        return new ForLoopBinding($collectionVarName, $indexVarName);
    }

    private function matchInit(Expr $init): ?string
    {
        if (!$init instanceof Assign || !$init->var instanceof Variable || !is_string($init->var->name)) {
            return null;
        }

        if (!$init->expr instanceof Int_ || $init->expr->value !== 0) {
            return null;
        }

        return $init->var->name;
    }

    private function matchCond(Expr $cond, string $indexVarName): ?string
    {
        if (!$cond instanceof Smaller) {
            return null;
        }

        if (!$cond->left instanceof Variable || $cond->left->name !== $indexVarName) {
            return null;
        }

        if (!$cond->right instanceof FuncCall || !$cond->right->name instanceof Name) {
            return null;
        }

        if ($cond->right->name->toString() !== 'count' || count($cond->right->args) !== 1) {
            return null;
        }

        $countArg = $cond->right->args[0];
        if (!$countArg instanceof Arg || !$countArg->value instanceof Variable || !is_string($countArg->value->name)) {
            return null;
        }

        return $countArg->value->name;
    }

    private function matchIncrement(Expr $loop, string $indexVarName): bool
    {
        if (!$loop instanceof PostInc && !$loop instanceof PreInc) {
            return false;
        }

        return $loop->var instanceof Variable && $loop->var->name === $indexVarName;
    }
}
