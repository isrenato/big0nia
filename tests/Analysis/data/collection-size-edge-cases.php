<?php

declare(strict_types=1);

namespace Doloto\Big0nia\Tests\Analysis\Data;

final class Buyer
{
    public function getId(): int
    {
        return 1;
    }
}

final class Purchase
{
    public function getUserId(): int
    {
        return 1;
    }
}

final class PurchaseRepository
{
    /**
     * @return Purchase[]
     */
    public function findAll(): array
    {
        return [];
    }
}

final class EmptyLiteral
{
    /**
     * @param Buyer[] $users
     */
    public function matchAgainstEmptyLiteral(array $users): void
    {
        $orders = [];

        foreach ($users as $user) {
            foreach ($orders as $order) {
                if ($user->getId() === $order->getUserId()) {
                }
            }
        }
    }
}

final class ReassignedToNonLiteral
{
    /**
     * @param Buyer[] $users
     */
    public function matchAgainstReassignedCollection(array $users, PurchaseRepository $repo): void
    {
        $orders = ['a', 'b'];
        $orders = $repo->findAll();

        foreach ($users as $user) {
            foreach ($orders as $order) {
                if ($user->getId() === $order->getUserId()) {
                }
            }
        }
    }
}
