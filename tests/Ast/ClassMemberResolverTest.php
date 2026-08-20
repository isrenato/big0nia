<?php

declare(strict_types=1);

namespace Doloto\Big0nia\Tests\Ast;

use Doloto\Big0nia\Ast\ClassMemberResolver;
use PhpParser\Node;
use PhpParser\Node\Expr\Assign;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;

final class ClassMemberResolverTest extends TestCase
{
    private function contextNodeInside(string $code): Node
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $ast = $parser->parse($code);
        self::assertNotNull($ast);

        $traverser = new NodeTraverser();
        $traverser->addVisitor(new ParentConnectingVisitor());
        $traverser->traverse($ast);

        $marker = (new NodeFinder())->findFirstInstanceOf($ast, Assign::class);
        self::assertNotNull($marker);

        return $marker;
    }

    public function testFindsPropertyDefaultArrayLiteral(): void
    {
        $context = $this->contextNodeInside(<<<'PHP'
            <?php
            class Foo {
                private array $statuses = ['a', 'b', 'c'];

                public function marker(): void {
                    $x = 1;
                }
            }
            PHP);

        $resolver = new ClassMemberResolver();
        $default = $resolver->findPropertyDefaultArray($context, 'statuses');

        self::assertNotNull($default);
        self::assertCount(3, $default->items);
    }

    public function testReturnsNullWhenPropertyDoesNotExist(): void
    {
        $context = $this->contextNodeInside(<<<'PHP'
            <?php
            class Foo {
                public function marker(): void {
                    $x = 1;
                }
            }
            PHP);

        $resolver = new ClassMemberResolver();

        self::assertNull($resolver->findPropertyDefaultArray($context, 'statuses'));
    }

    public function testReturnsNullWhenPropertyDefaultIsNotAnArrayLiteral(): void
    {
        $context = $this->contextNodeInside(<<<'PHP'
            <?php
            class Foo {
                private array $statuses = [];

                public function marker(): void {
                    $x = 1;
                }
            }
            PHP);

        $resolver = new ClassMemberResolver();
        $default = $resolver->findPropertyDefaultArray($context, 'statuses');

        self::assertNotNull($default);
        self::assertCount(0, $default->items);
    }

    public function testFindsMethodReturnArrayLiteral(): void
    {
        $context = $this->contextNodeInside(<<<'PHP'
            <?php
            class Foo {
                private function statuses(): array {
                    return ['a', 'b'];
                }

                public function marker(): void {
                    $x = 1;
                }
            }
            PHP);

        $resolver = new ClassMemberResolver();
        $returned = $resolver->findMethodReturnArray($context, 'statuses');

        self::assertNotNull($returned);
        self::assertCount(2, $returned->items);
    }

    public function testReturnsNullWhenMethodDoesNotExist(): void
    {
        $context = $this->contextNodeInside(<<<'PHP'
            <?php
            class Foo {
                public function marker(): void {
                    $x = 1;
                }
            }
            PHP);

        $resolver = new ClassMemberResolver();

        self::assertNull($resolver->findMethodReturnArray($context, 'statuses'));
    }

    public function testReturnsNullWhenMethodBodyIsMoreThanASingleReturnStatement(): void
    {
        $context = $this->contextNodeInside(<<<'PHP'
            <?php
            class Foo {
                private function statuses(): array {
                    $extra = 1;
                    return ['a', 'b'];
                }

                public function marker(): void {
                    $x = 1;
                }
            }
            PHP);

        $resolver = new ClassMemberResolver();

        self::assertNull($resolver->findMethodReturnArray($context, 'statuses'));
    }

    public function testReturnsNullWhenMethodReturnsANonLiteralExpression(): void
    {
        $context = $this->contextNodeInside(<<<'PHP'
            <?php
            class Foo {
                private function statuses(): array {
                    return $this->repo->findAll();
                }

                public function marker(): void {
                    $x = 1;
                }
            }
            PHP);

        $resolver = new ClassMemberResolver();

        self::assertNull($resolver->findMethodReturnArray($context, 'statuses'));
    }

    public function testReturnsNullWhenPropertyDefaultIsReassignedInConstructor(): void
    {
        $context = $this->contextNodeInside(<<<'PHP'
            <?php
            class Foo {
                private array $statuses = ['a', 'b', 'c'];

                public function __construct(array $statuses) {
                    $this->statuses = $statuses;
                }
            }
            PHP);

        $resolver = new ClassMemberResolver();

        self::assertNull($resolver->findPropertyDefaultArray($context, 'statuses'));
    }

    public function testReturnsNullWhenPropertyDefaultIsReassignedInAnotherMethod(): void
    {
        $context = $this->contextNodeInside(<<<'PHP'
            <?php
            class Foo {
                private array $statuses = ['a', 'b', 'c'];

                public function reset(array $statuses): void {
                    $this->statuses = $statuses;
                }
            }
            PHP);

        $resolver = new ClassMemberResolver();

        self::assertNull($resolver->findPropertyDefaultArray($context, 'statuses'));
    }

    public function testFindsConstructorPromotedPropertyDefaultArrayLiteral(): void
    {
        $context = $this->contextNodeInside(<<<'PHP'
            <?php
            class Foo {
                public function __construct(private array $statuses = ['a', 'b', 'c']) {
                }

                public function marker(): void {
                    $x = 1;
                }
            }
            PHP);

        $resolver = new ClassMemberResolver();
        $default = $resolver->findPropertyDefaultArray($context, 'statuses');

        self::assertNotNull($default);
        self::assertCount(3, $default->items);
    }

    public function testReturnsNullWhenPromotedPropertyDefaultIsReassignedElsewhere(): void
    {
        $context = $this->contextNodeInside(<<<'PHP'
            <?php
            class Foo {
                public function __construct(private array $statuses = ['a', 'b', 'c']) {
                }

                public function reset(array $statuses): void {
                    $this->statuses = $statuses;
                }
            }
            PHP);

        $resolver = new ClassMemberResolver();

        self::assertNull($resolver->findPropertyDefaultArray($context, 'statuses'));
    }

    public function testReturnsNullWhenContextNodeHasNoEnclosingClass(): void
    {
        $context = $this->contextNodeInside(<<<'PHP'
            <?php
            $x = 1;
            PHP);

        $resolver = new ClassMemberResolver();

        self::assertNull($resolver->findPropertyDefaultArray($context, 'statuses'));
        self::assertNull($resolver->findMethodReturnArray($context, 'statuses'));
    }
}
