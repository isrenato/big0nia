<?php

declare(strict_types=1);

namespace Big0niaFixtures\Interprocedural;

class OrderMatcher
{
    public function matchAll($user): void
    {
        foreach ($this->orders() as $order) {
            if ($user->getId() === $order->getUserId()) {
                // match
            }
        }
    }

    private function orders(): array
    {
        return $this->fetchOrders();
    }

    private function fetchOrders(): array
    {
        return [];
    }
}
