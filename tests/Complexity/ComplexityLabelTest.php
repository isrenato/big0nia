<?php

declare(strict_types=1);

namespace Doloto\Big0nia\Tests\Complexity;

use Doloto\Big0nia\Complexity\ComplexityLabel;
use PHPUnit\Framework\TestCase;

final class ComplexityLabelTest extends TestCase
{
    public function testForJoinWithDifferentCollections(): void
    {
        self::assertSame('O(users × orders)', ComplexityLabel::forJoin('users', 'orders', false));
    }

    public function testForJoinWithSameCollection(): void
    {
        self::assertSame('O(users²)', ComplexityLabel::forJoin('users', 'users', true));
    }

    public function testIndexedFormWithDifferentCollections(): void
    {
        self::assertSame('O(users + orders)', ComplexityLabel::indexedForm('users', 'orders', false));
    }

    public function testIndexedFormWithSameCollection(): void
    {
        self::assertSame('O(users)', ComplexityLabel::indexedForm('users', 'users', true));
    }
}
