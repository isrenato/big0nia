<?php

declare(strict_types=1);

namespace Doloto\Big0nia\Tests\Analysis\Data;

final class SelfReferentialMerge
{
    /**
     * @param mixed[] $items
     * @return mixed[]
     */
    public function collect(array $items): array
    {
        $result = [];

        foreach ($items as $item) {
            $result = array_merge($result, [$item]);
        }

        return $result;
    }
}

final class UnrelatedMerge
{
    /**
     * @param mixed[] $items
     */
    public function combine(array $items): void
    {
        foreach ($items as $item) {
            $combined = array_merge($item->a, $item->b);
            unset($combined);
        }
    }
}

final class SelfReferentialMergeInFixedSmallLoop
{
    /**
     * @return mixed[]
     */
    public function collect(): array
    {
        $result = [];
        $small = ['a', 'b'];

        foreach ($small as $item) {
            $result = array_merge($result, [$item]);
        }

        return $result;
    }
}
