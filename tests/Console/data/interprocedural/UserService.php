<?php

declare(strict_types=1);

namespace Big0niaFixtures\Interprocedural;

class UserService
{
    public function __construct(private OrderMatcher $matcher)
    {
    }

    public function process(array $users): void
    {
        foreach ($users as $user) {
            $this->matcher->matchAll($user);
        }
    }
}
