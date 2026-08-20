<?php

declare(strict_types=1);

namespace Doloto\Big0nia\Tests\Analysis\Data;

final class User
{
    public function getId(): int
    {
        return 1;
    }
}

final class Order
{
    public function getUserId(): int
    {
        return 1;
    }
}

final class Status
{
    public function getId(): int
    {
        return 1;
    }
}

final class DifferentCollections
{
    /**
     * @param User[] $users
     * @param Order[] $orders
     */
    public function match(array $users, array $orders): void
    {
        foreach ($users as $user) {
            foreach ($orders as $order) {
                if ($user->getId() === $order->getUserId()) {
                }
            }
        }
    }
}

final class SameCollection
{
    /**
     * @param User[] $users
     */
    public function findDuplicates(array $users): void
    {
        foreach ($users as $user) {
            foreach ($users as $candidate) {
                if ($user->getId() === $candidate->getId()) {
                }
            }
        }
    }
}

final class FixedSmallArray
{
    /**
     * @param User[] $users
     */
    public function matchAgainstFixedStatuses(array $users): void
    {
        $statuses = [new Status(), new Status(), new Status()];

        foreach ($users as $user) {
            foreach ($statuses as $status) {
                if ($user->getId() === $status->getId()) {
                }
            }
        }
    }
}

final class NoJoinSignature
{
    /**
     * @param User[] $users
     * @param Order[] $orders
     */
    public function unrelatedNesting(array $users, array $orders): void
    {
        foreach ($users as $user) {
            foreach ($orders as $order) {
                $order->getUserId();
            }
        }
    }
}

final class SingleLoop
{
    /**
     * @param User[] $users
     */
    public function iterateOnce(array $users): void
    {
        foreach ($users as $user) {
            $user->getId();
        }
    }
}
