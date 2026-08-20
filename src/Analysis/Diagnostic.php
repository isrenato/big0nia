<?php

declare(strict_types=1);

namespace Doloto\Big0nia\Analysis;

final class Diagnostic
{
    public function __construct(
        public readonly string $file,
        public readonly int $line,
        public readonly string $message,
        public readonly string $tip,
    ) {
    }
}
