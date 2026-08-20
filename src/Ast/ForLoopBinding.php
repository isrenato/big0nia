<?php

declare(strict_types=1);

namespace Doloto\Big0nia\Ast;

final class ForLoopBinding
{
    public function __construct(
        public readonly string $collectionVarName,
        public readonly string $indexVarName,
    ) {
    }
}
