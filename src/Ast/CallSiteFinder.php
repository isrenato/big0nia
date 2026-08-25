<?php

declare(strict_types=1);

namespace Doloto\Big0nia\Ast;

use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\If_;

final class CallSiteFinder
{
    /**
     * @param Stmt[] $stmts
     * @return CallSite[]
     */
    public function findAll(array $stmts): array
    {
        $sites = [];

        foreach ($stmts as $index => $stmt) {
            if ($stmt instanceof Expression) {
                $call = $this->extractCall($stmt->expr);
                if ($call !== null) {
                    $sites[] = new CallSite($call, array_slice($stmts, 0, $index));
                }

                continue;
            }

            if ($stmt instanceof If_) {
                $prefix = array_slice($stmts, 0, $index);
                foreach ($this->findAll($stmt->stmts) as $nested) {
                    $sites[] = new CallSite($nested->call, [...$prefix, ...$nested->precedingStmts]);
                }
            }
        }

        return $sites;
    }

    private function extractCall(Expr $expr): MethodCall|StaticCall|FuncCall|null
    {
        if ($expr instanceof MethodCall || $expr instanceof StaticCall || $expr instanceof FuncCall) {
            return $expr;
        }

        if ($expr instanceof Assign) {
            return $this->extractCall($expr->expr);
        }

        return null;
    }
}
