<?php

declare(strict_types=1);

namespace Doloto\Big0nia\Tests\Ast;

use Doloto\Big0nia\Ast\CollectionSize;
use Doloto\Big0nia\Ast\CollectionSizeClassifier;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;

final class CollectionSizeClassifierTest extends TestCase
{
    public function testClassifiesDirectArrayLiteralBySize(): void
    {
        $classifier = new CollectionSizeClassifier();

        $small = new Array_([new ArrayItem(new Variable('a')), new ArrayItem(new Variable('b'))]);
        $big = new Array_(array_fill(0, 6, new ArrayItem(new Variable('x'))));

        self::assertSame(CollectionSize::FixedSmall, $classifier->classify($small, []));
        self::assertSame(CollectionSize::Unbounded, $classifier->classify($big, []));
    }

    public function testClassifiesVariableViaPrecedingLiteralAssignment(): void
    {
        $classifier = new CollectionSizeClassifier();

        $assignment = new Expression(new Assign(
            new Variable('statuses'),
            new Array_([
                new ArrayItem(new Variable('a')),
                new ArrayItem(new Variable('b')),
                new ArrayItem(new Variable('c')),
            ])
        ));

        self::assertSame(CollectionSize::FixedSmall, $classifier->classify(new Variable('statuses'), [$assignment]));
    }

    public function testReturnsUnknownWhenNoAssignmentIsFound(): void
    {
        $classifier = new CollectionSizeClassifier();

        self::assertSame(CollectionSize::Unknown, $classifier->classify(new Variable('users'), []));
    }

    public function testUsesTheLastAssignmentWhenVariableIsReassigned(): void
    {
        $classifier = new CollectionSizeClassifier();

        $stmts = [
            new Expression(new Assign(new Variable('statuses'), new Array_([new ArrayItem(new Variable('a'))]))),
            new Expression(new Assign(
                new Variable('statuses'),
                new Array_(array_fill(0, 6, new ArrayItem(new Variable('x'))))
            )),
        ];

        self::assertSame(CollectionSize::Unbounded, $classifier->classify(new Variable('statuses'), $stmts));
    }

    public function testEmptyArrayLiteralClassifiesAsUnknownNotFixedSmall(): void
    {
        $classifier = new CollectionSizeClassifier();

        self::assertSame(CollectionSize::Unknown, $classifier->classify(new Array_([]), []));
    }

    public function testEmptyArrayLiteralAssignmentClassifiesAsUnknownNotFixedSmall(): void
    {
        $classifier = new CollectionSizeClassifier();

        $assignment = new Expression(new Assign(new Variable('orders'), new Array_([])));

        self::assertSame(CollectionSize::Unknown, $classifier->classify(new Variable('orders'), [$assignment]));
    }

    public function testLaterNonLiteralReassignmentClearsThePriorLiteralClassification(): void
    {
        $classifier = new CollectionSizeClassifier();

        $stmts = [
            new Expression(new Assign(
                new Variable('orders'),
                new Array_([new ArrayItem(new Variable('a')), new ArrayItem(new Variable('b'))])
            )),
            new Expression(new Assign(
                new Variable('orders'),
                new MethodCall(new Variable('repo'), new Identifier('findAll'))
            )),
        ];

        self::assertSame(CollectionSize::Unknown, $classifier->classify(new Variable('orders'), $stmts));
    }

    public function testClassifiesThisPropertyFetchViaItsDefaultArrayLiteral(): void
    {
        $expr = $this->parseAndFindLastExpr(<<<'PHP'
            <?php
            class Foo {
                private array $statuses = ['a', 'b', 'c'];

                public function m(): void {
                    foreach ($this->statuses as $status) {
                    }
                }
            }
            PHP);

        $classifier = new CollectionSizeClassifier();

        self::assertSame(CollectionSize::FixedSmall, $classifier->classify($expr, []));
    }

    public function testClassifiesThisMethodCallViaItsSingleReturnArrayLiteral(): void
    {
        $expr = $this->parseAndFindLastExpr(<<<'PHP'
            <?php
            class Foo {
                private function statuses(): array {
                    return ['a', 'b', 'c'];
                }

                public function m(): void {
                    foreach ($this->statuses() as $status) {
                    }
                }
            }
            PHP);

        $classifier = new CollectionSizeClassifier();

        self::assertSame(CollectionSize::FixedSmall, $classifier->classify($expr, []));
    }

    public function testReturnsUnknownForThisPropertyFetchWithNoResolvableDefault(): void
    {
        $expr = $this->parseAndFindLastExpr(<<<'PHP'
            <?php
            class Foo {
                private array $orders;

                public function m(): void {
                    foreach ($this->orders as $order) {
                    }
                }
            }
            PHP);

        $classifier = new CollectionSizeClassifier();

        self::assertSame(CollectionSize::Unknown, $classifier->classify($expr, []));
    }

    public function testReturnsUnknownForThisMethodCallWithNoResolvableSingleReturnLiteral(): void
    {
        $expr = $this->parseAndFindLastExpr(<<<'PHP'
            <?php
            class Foo {
                private function orders(): array {
                    return $this->repo->findAll();
                }

                public function m(): void {
                    foreach ($this->orders() as $order) {
                    }
                }
            }
            PHP);

        $classifier = new CollectionSizeClassifier();

        self::assertSame(CollectionSize::Unknown, $classifier->classify($expr, []));
    }

    private function parseAndFindLastExpr(string $code): Expr
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $ast = $parser->parse($code);
        self::assertNotNull($ast);

        $traverser = new NodeTraverser();
        $traverser->addVisitor(new ParentConnectingVisitor());
        $traverser->traverse($ast);

        $foreach = (new NodeFinder())->findFirstInstanceOf($ast, Foreach_::class);
        self::assertInstanceOf(Foreach_::class, $foreach);

        return $foreach->expr;
    }
}
