<?php

declare(strict_types=1);

namespace Doloto\Big0nia\Rule;

final class Finding
{
    public function __construct(
        public readonly int $line,
        public readonly string $message,
        public readonly string $tip,
    ) {
    }
}
