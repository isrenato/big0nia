<?php

declare(strict_types=1);

namespace Doloto\Big0nia\Complexity;

final class ComplexityLabel
{
    public static function forJoin(string $outerName, string $innerName, bool $sameCollection): string
    {
        if ($sameCollection) {
            return sprintf('O(%s²)', $outerName);
        }

        return sprintf('O(%s × %s)', $outerName, $innerName);
    }

    public static function indexedForm(string $outerName, string $innerName, bool $sameCollection): string
    {
        if ($sameCollection) {
            return sprintf('O(%s)', $outerName);
        }

        return sprintf('O(%s + %s)', $outerName, $innerName);
    }
}
