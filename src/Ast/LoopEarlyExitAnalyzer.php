<?php

declare(strict_types=1);

namespace Doloto\Big0nia\Ast;

use PhpParser\Node\Expr\Throw_;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Break_;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Return_;

final class LoopEarlyExitAnalyzer
{
    /**
     * @param Stmt[] $stmts
     */
    public function boundsToOnePass(array $stmts): bool
    {
        foreach ($stmts as $stmt) {
            if ($stmt instanceof Break_ && $this->targetsInnermostLoop($stmt)) {
                return true;
            }

            if ($stmt instanceof Return_) {
                return true;
            }

            if ($stmt instanceof Expression && $stmt->expr instanceof Throw_) {
                return true;
            }
        }

        return false;
    }

    private function targetsInnermostLoop(Break_ $break): bool
    {
        if ($break->num === null) {
            return true;
        }

        return $break->num instanceof Int_ && $break->num->value === 1;
    }
}
