<?php

declare(strict_types=1);

namespace Doloto\Big0nia\Ast;

final class JoinSignature
{
    public function __construct(
        public readonly string $outerDisplay,
        public readonly string $innerDisplay,
        public readonly string $innerKey,
    ) {
    }
}
