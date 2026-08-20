<?php

declare(strict_types=1);

namespace Doloto\Big0nia\Tests\Ast;

use Doloto\Big0nia\Ast\CanonicalForLoopMatcher;
use PhpParser\Node\Stmt\For_;
use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;

final class CanonicalForLoopMatcherTest extends TestCase
{
    private function parseFor(string $code): For_
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $ast = $parser->parse($code);
        self::assertNotNull($ast);
        self::assertInstanceOf(For_::class, $ast[0]);

        return $ast[0];
    }

    public function testMatchesCanonicalPostIncrementForm(): void
    {
        $for = $this->parseFor('<?php for ($i = 0; $i < count($users); $i++) {}');

        $binding = (new CanonicalForLoopMatcher())->match($for);

        self::assertNotNull($binding);
        self::assertSame('users', $binding->collectionVarName);
        self::assertSame('i', $binding->indexVarName);
    }

    public function testMatchesCanonicalPreIncrementForm(): void
    {
        $for = $this->parseFor('<?php for ($i = 0; $i < count($users); ++$i) {}');

        $binding = (new CanonicalForLoopMatcher())->match($for);

        self::assertNotNull($binding);
        self::assertSame('users', $binding->collectionVarName);
        self::assertSame('i', $binding->indexVarName);
    }

    public function testReturnsNullWhenInitIsNotZero(): void
    {
        $for = $this->parseFor('<?php for ($i = 1; $i < count($users); $i++) {}');

        self::assertNull((new CanonicalForLoopMatcher())->match($for));
    }

    public function testReturnsNullWhenConditionUsesLessThanOrEqual(): void
    {
        $for = $this->parseFor('<?php for ($i = 0; $i <= count($users); $i++) {}');

        self::assertNull((new CanonicalForLoopMatcher())->match($for));
    }

    public function testReturnsNullWhenConditionBoundIsNotACountCall(): void
    {
        $for = $this->parseFor('<?php for ($i = 0; $i < $max; $i++) {}');

        self::assertNull((new CanonicalForLoopMatcher())->match($for));
    }

    public function testReturnsNullWhenConditionBoundIsCountOfADifferentVariableThanIndexIsNot(): void
    {
        $for = $this->parseFor('<?php for ($i = 0; $j < count($users); $i++) {}');

        self::assertNull((new CanonicalForLoopMatcher())->match($for));
    }

    public function testReturnsNullWhenIncrementVariableDoesNotMatchIndex(): void
    {
        $for = $this->parseFor('<?php for ($i = 0; $i < count($users); $j++) {}');

        self::assertNull((new CanonicalForLoopMatcher())->match($for));
    }

    public function testReturnsNullWhenDecrementing(): void
    {
        $for = $this->parseFor('<?php for ($i = 0; $i < count($users); $i--) {}');

        self::assertNull((new CanonicalForLoopMatcher())->match($for));
    }

    public function testReturnsNullWhenInitIsMissing(): void
    {
        $for = $this->parseFor('<?php for (; $i < count($users); $i++) {}');

        self::assertNull((new CanonicalForLoopMatcher())->match($for));
    }

    public function testReturnsNullWhenMultipleInitExpressions(): void
    {
        $for = $this->parseFor('<?php for ($i = 0, $n = count($users); $i < $n; $i++) {}');

        self::assertNull((new CanonicalForLoopMatcher())->match($for));
    }

    public function testReturnsNullWhenCountHasMoreThanOneArgument(): void
    {
        $for = $this->parseFor('<?php for ($i = 0; $i < count($users, 1); $i++) {}');

        self::assertNull((new CanonicalForLoopMatcher())->match($for));
    }
}
