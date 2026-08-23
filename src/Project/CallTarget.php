<?php

declare(strict_types=1);

namespace Doloto\Big0nia\Project;

use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;

final class CallTarget
{
    public function __construct(
        public readonly ClassMethod|Function_ $node,
        public readonly ?string $ownerFqcn,
        public readonly string $filePath,
    ) {
    }
}
