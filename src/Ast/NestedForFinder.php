<?php

declare(strict_types=1);

namespace Doloto\Big0nia\Ast;

use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\For_;
use PhpParser\Node\Stmt\If_;

final class NestedForFinder
{
    /**
     * @param Stmt[] $stmts
     */
    public function find(array $stmts): ?For_
    {
        foreach ($stmts as $stmt) {
            if ($stmt instanceof For_) {
                return $stmt;
            }

            if ($stmt instanceof If_) {
                $found = $this->find($stmt->stmts);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }
}
