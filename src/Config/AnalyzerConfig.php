<?php

declare(strict_types=1);

namespace Doloto\Big0nia\Config;

final class AnalyzerConfig
{
    /**
     * @param string[] $ignorePaths
     */
    public function __construct(
        public readonly array $ignorePaths,
    ) {
    }
}
