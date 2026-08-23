<?php

declare(strict_types=1);

namespace Doloto\Big0nia\Project;

use PhpParser\Node\Stmt\Function_;

final class FunctionIndexEntry
{
    public function __construct(
        public readonly Function_ $node,
        public readonly string $filePath,
    ) {
    }
}
