<?php

declare(strict_types=1);

namespace Doloto\Big0nia\Project;

use PhpParser\Node\Stmt\Class_;

final class ClassIndexEntry
{
    public function __construct(
        public readonly string $fqcn,
        public readonly Class_ $node,
        public readonly string $filePath,
    ) {
    }
}
