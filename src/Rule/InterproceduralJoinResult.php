<?php

declare(strict_types=1);

namespace Doloto\Big0nia\Rule;

use Doloto\Big0nia\Ast\CollectionSize;
use Doloto\Big0nia\Ast\JoinSignature;

final class InterproceduralJoinResult
{
    /**
     * @param string[] $chainLabels
     */
    public function __construct(
        public readonly JoinSignature $signature,
        public readonly string $innerCollectionName,
        public readonly CollectionSize $innerCollectionSize,
        public readonly string $innerFilePath,
        public readonly int $innerLine,
        public readonly array $chainLabels,
    ) {
    }
}
