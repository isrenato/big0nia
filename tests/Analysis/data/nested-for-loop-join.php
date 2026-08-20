<?php

declare(strict_types=1);

namespace Doloto\Big0nia\Tests\Analysis\Data;

final class IndexedUser
{
    public function getId(): int
    {
        return 1;
    }
}

final class IndexedOrder
{
    public function getUserId(): int
    {
        return 1;
    }
}

final class DifferentCollections
{
    /**
     * @param IndexedUser[] $users
     * @param IndexedOrder[] $orders
     */
    public function match(array $users, array $orders): void
    {
        for ($i = 0; $i < count($users); $i++) {
            for ($j = 0; $j < count($orders); $j++) {
                if ($users[$i]->getId() === $orders[$j]->getUserId()) {
                }
            }
        }
    }
}

final class NotCanonicalForm
{
    /**
     * @param IndexedUser[] $users
     * @param IndexedOrder[] $orders
     */
    public function matchWithLessThanOrEqual(array $users, array $orders): void
    {
        for ($i = 0; $i <= count($users) - 1; $i++) {
            for ($j = 0; $j < count($orders); $j++) {
                if ($users[$i]->getId() === $orders[$j]->getUserId()) {
                }
            }
        }
    }
}
