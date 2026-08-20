<?php

declare(strict_types=1);

namespace Doloto\Big0nia\Rule;

use PhpParser\Node\Stmt;

interface LoopRule
{
    /**
     * @param Stmt[] $precedingStmts Statements preceding the loop in its own enclosing statement list.
     */
    public function check(Stmt $loopNode, array $precedingStmts): ?Finding;
}
