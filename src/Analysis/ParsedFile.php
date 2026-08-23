<?php

declare(strict_types=1);

namespace Doloto\Big0nia\Analysis;

use PhpParser\Node\Stmt;

final class ParsedFile
{
    /**
     * @param Stmt[] $ast
     */
    public function __construct(
        public readonly string $filePath,
        public readonly array $ast,
    ) {
    }
}
