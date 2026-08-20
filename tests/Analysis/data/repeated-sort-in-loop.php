<?php

declare(strict_types=1);

namespace Doloto\Big0nia\Tests\Analysis\Data;

final class LoopInvariantSort
{
    /**
     * @param mixed[] $data
     * @param mixed[] $items
     */
    public function process(array $data, array $items): void
    {
        foreach ($items as $item) {
            usort($data, static fn ($a, $b) => $a <=> $b);
            unset($item);
        }
    }
}

final class LoopVariantSort
{
    /**
     * @param object[] $groups
     */
    public function process(array $groups): void
    {
        foreach ($groups as $group) {
            $data = $group->items;
            usort($data, static fn ($a, $b) => $a <=> $b);
            unset($data);
        }
    }
}

final class LoopInvariantSortOfFixedSmallData
{
    public function process(): void
    {
        $data = [3, 1, 2];
        $small = ['a', 'b'];

        foreach ($small as $item) {
            usort($data, static fn ($a, $b) => $a <=> $b);
            unset($item);
        }
    }
}
