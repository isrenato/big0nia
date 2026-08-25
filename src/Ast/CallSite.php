<?php

declare(strict_types=1);

namespace Doloto\Big0nia\Ast;

use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Stmt;

final class CallSite
{
    /**
     * @param Stmt[] $precedingStmts
     */
    public function __construct(
        public readonly MethodCall|StaticCall|FuncCall $call,
        public readonly array $precedingStmts,
    ) {
    }
}
