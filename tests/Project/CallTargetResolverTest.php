<?php

declare(strict_types=1);

namespace Doloto\Big0nia\Tests\Project;

use Doloto\Big0nia\Analysis\ParsedFile;
use Doloto\Big0nia\Analysis\PhpFileParser;
use Doloto\Big0nia\Project\CallTargetResolver;
use Doloto\Big0nia\Project\ProjectIndex;
use Doloto\Big0nia\Project\ProjectIndexBuilder;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Expression;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;

final class CallTargetResolverTest extends TestCase
{
    public function testResolvesTypedPropertyMethodCall(): void
    {
        $call = $this->firstCallIn(<<<'PHP'
            <?php
            namespace App;
            class OrderRepository {
                public function findAll(): array { return []; }
            }
            class UserService {
                public function __construct(private OrderRepository $orderRepo) {}
                public function process(): void {
                    $this->orderRepo->findAll();
                }
            }
            PHP);

        $resolver = $this->resolverFor($call['file']);
        $target = $resolver->resolve($call['expr'], []);

        self::assertNotNull($target);
        self::assertInstanceOf(ClassMethod::class, $target->node);
        self::assertSame('findAll', $target->node->name->toString());
        self::assertSame('App\\OrderRepository', $target->ownerFqcn);
    }

    public function testResolvesLocalNewAssignedVariable(): void
    {
        $call = $this->firstCallIn(<<<'PHP'
            <?php
            namespace App;
            class Bar {
                public function baz(): void {}
            }
            class Foo {
                public function run(): void {
                    $bar = new Bar();
                    $bar->baz();
                }
            }
            PHP);

        $resolver = $this->resolverFor($call['file']);
        $precedingStmts = $call['method']->stmts[0] instanceof Expression ? [$call['method']->stmts[0]] : [];
        $target = $resolver->resolve($call['expr'], $precedingStmts);

        self::assertNotNull($target);
        self::assertSame('baz', $target->node->name->toString());
        self::assertSame('App\\Bar', $target->ownerFqcn);
    }

    public function testResolvesStaticCall(): void
    {
        $call = $this->firstCallIn(<<<'PHP'
            <?php
            namespace App;
            class Factory {
                public static function make(): void {}
            }
            class Foo {
                public function run(): void {
                    Factory::make();
                }
            }
            PHP);

        $resolver = $this->resolverFor($call['file']);
        $target = $resolver->resolve($call['expr'], []);

        self::assertNotNull($target);
        self::assertSame('make', $target->node->name->toString());
        self::assertSame('App\\Factory', $target->ownerFqcn);
    }

    public function testResolvesFreeFunctionCall(): void
    {
        $call = $this->firstCallIn(<<<'PHP'
            <?php
            namespace App;
            function helper(): void {}
            class Foo {
                public function run(): void {
                    helper();
                }
            }
            PHP);

        $resolver = $this->resolverFor($call['file']);
        $target = $resolver->resolve($call['expr'], []);

        self::assertNotNull($target);
        self::assertSame('helper', $target->node->name->toString());
        self::assertNull($target->ownerFqcn);
    }

    public function testResolvesInterfaceTypeWhenExactlyOneImplementorExists(): void
    {
        $call = $this->firstCallIn(<<<'PHP'
            <?php
            namespace App;
            interface RepositoryInterface {
                public function findAll(): array;
            }
            class DoctrineRepository implements RepositoryInterface {
                public function findAll(): array { return []; }
            }
            class UserService {
                public function __construct(private RepositoryInterface $repo) {}
                public function process(): void {
                    $this->repo->findAll();
                }
            }
            PHP);

        $resolver = $this->resolverFor($call['file']);
        $target = $resolver->resolve($call['expr'], []);

        self::assertNotNull($target);
        self::assertSame('App\\DoctrineRepository', $target->ownerFqcn);
    }

    public function testDoesNotResolveInterfaceTypeWithZeroOrMultipleImplementors(): void
    {
        $call = $this->firstCallIn(<<<'PHP'
            <?php
            namespace App;
            interface RepositoryInterface {
                public function findAll(): array;
            }
            class RepoA implements RepositoryInterface {
                public function findAll(): array { return []; }
            }
            class RepoB implements RepositoryInterface {
                public function findAll(): array { return []; }
            }
            class UserService {
                public function __construct(private RepositoryInterface $repo) {}
                public function process(): void {
                    $this->repo->findAll();
                }
            }
            PHP);

        $resolver = $this->resolverFor($call['file']);

        self::assertNull($resolver->resolve($call['expr'], []));
    }

    public function testReturnsNullForCallOnUnresolvableType(): void
    {
        $call = $this->firstCallIn(<<<'PHP'
            <?php
            namespace App;
            class UserService {
                public function __construct(private \Vendor\External $external) {}
                public function process(): void {
                    $this->external->doSomething();
                }
            }
            PHP);

        $resolver = $this->resolverFor($call['file']);

        self::assertNull($resolver->resolve($call['expr'], []));
    }

    public function testResolveReturnsNullForUnsupportedExpressionType(): void
    {
        $index = (new ProjectIndexBuilder())->build([]);
        $resolver = new CallTargetResolver($index);

        self::assertNull($resolver->resolve(new Variable('x'), []));
    }

    public function testResolveStaticCallReturnsNullForDynamicClassName(): void
    {
        $call = $this->firstCallIn(<<<'PHP'
            <?php
            namespace App;
            class Foo {
                public function run(): void {
                    $className = 'Bar';
                    $className::method();
                }
            }
            PHP);

        $resolver = $this->resolverFor($call['file']);

        self::assertNull($resolver->resolve($call['expr'], []));
    }

    public function testResolveStaticCallReturnsNullForDynamicMethodName(): void
    {
        $call = $this->firstCallIn(<<<'PHP'
            <?php
            namespace App;
            class Foo {
                public static function make(): void {}
                public function run(): void {
                    $method = 'make';
                    Foo::$method();
                }
            }
            PHP);

        $resolver = $this->resolverFor($call['file']);

        self::assertNull($resolver->resolve($call['expr'], []));
    }

    public function testResolveFuncCallReturnsNullForDynamicFunctionCall(): void
    {
        $call = $this->firstCallIn(<<<'PHP'
            <?php
            namespace App;
            class Foo {
                public function run(): void {
                    $fn = 'helper';
                    $fn();
                }
            }
            PHP);

        $resolver = $this->resolverFor($call['file']);

        self::assertNull($resolver->resolve($call['expr'], []));
    }

    public function testResolveFuncCallFallsBackToNameToStringInGlobalNamespace(): void
    {
        $call = $this->firstCallIn(<<<'PHP'
            <?php
            function helper(): void {}
            class Foo {
                public function run(): void {
                    helper();
                }
            }
            PHP);

        $resolver = $this->resolverFor($call['file']);
        $target = $resolver->resolve($call['expr'], []);

        self::assertNotNull($target);
        self::assertSame('helper', $target->node->name->toString());
        self::assertNull($target->ownerFqcn);
    }

    public function testResolveFuncCallReturnsNullWhenFunctionIsNotIndexed(): void
    {
        $call = $this->firstCallIn(<<<'PHP'
            <?php
            namespace App;
            class Foo {
                public function run(): void {
                    undefinedHelper();
                }
            }
            PHP);

        $resolver = $this->resolverFor($call['file']);

        self::assertNull($resolver->resolve($call['expr'], []));
    }

    public function testResolveMethodCallReturnsNullForDynamicMethodName(): void
    {
        $call = $this->firstCallIn(<<<'PHP'
            <?php
            namespace App;
            class Foo {
                public function run(): void {
                    $methodName = 'bar';
                    $obj = new \stdClass();
                    $obj->{$methodName}();
                }
            }
            PHP);

        $resolver = $this->resolverFor($call['file']);

        self::assertNull($resolver->resolve($call['expr'], []));
    }

    public function testResolveVariableTypeFqcnReturnsNullForCallOnFunctionReturnValue(): void
    {
        $call = $this->firstCallIn(<<<'PHP'
            <?php
            namespace App;
            function foo(): object { return new \stdClass(); }
            class Test {
                public function run(): void {
                    foo()->bar();
                }
            }
            PHP);

        $resolver = $this->resolverFor($call['file']);

        self::assertNull($resolver->resolve($call['expr'], []));
    }

    public function testFindLastNewAssignmentTypeFqcnSkipsNonAssignmentsUnrelatedVarsAndNonNewAssignments(): void
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $file = (new PhpFileParser($parser))->parseCode('/virtual/App.php', <<<'PHP'
            <?php
            namespace App;
            class Bar {
                public function baz(): void {}
            }
            class SomethingElse {}
            function someHelper(): ?SomethingElse { return null; }
            class Foo {
                public function run(): void {
                    echo 'noop';
                    $other = new SomethingElse();
                    $bar = someHelper();
                    $bar = new Bar();
                    $bar->baz();
                }
            }
            PHP);

        $finder = new NodeFinder();
        $method = $finder->findFirst($file->ast, static fn ($node): bool =>
            $node instanceof ClassMethod && $node->name->toString() === 'run');
        self::assertInstanceOf(ClassMethod::class, $method);
        self::assertNotNull($method->stmts);

        $lastStmt = $method->stmts[count($method->stmts) - 1];
        self::assertInstanceOf(Expression::class, $lastStmt);
        self::assertInstanceOf(MethodCall::class, $lastStmt->expr);

        $resolver = $this->resolverFor($file);
        $target = $resolver->resolve($lastStmt->expr, array_slice($method->stmts, 0, -1));

        self::assertNotNull($target);
        self::assertSame('baz', $target->node->name->toString());
        self::assertSame('App\\Bar', $target->ownerFqcn);
    }

    public function testClassMethodTargetReturnsNullWhenImplementorIndexEntryIsMissing(): void
    {
        $call = $this->firstCallIn(<<<'PHP'
            <?php
            namespace App;
            interface RepositoryInterface {
                public function findAll(): array;
            }
            class UserService {
                public function __construct(private RepositoryInterface $repo) {}
                public function process(): void {
                    $this->repo->findAll();
                }
            }
            PHP);

        // Constructed directly (not via ProjectIndexBuilder) so that the
        // implementor FQCN has no corresponding classesByFqcn entry — a
        // state ProjectIndexBuilder never produces, but ProjectIndex's own
        // constructor doesn't forbid.
        $index = new ProjectIndex(
            [],
            ['App\\RepositoryInterface' => ['App\\DoctrineRepository']],
            [],
        );
        $resolver = new CallTargetResolver($index);

        self::assertNull($resolver->resolve($call['expr'], []));
    }

    public function testClassMethodTargetReturnsNullWhenMethodDoesNotExistOnResolvedClass(): void
    {
        $call = $this->firstCallIn(<<<'PHP'
            <?php
            namespace App;
            class Bar {
                public function baz(): void {}
            }
            class Foo {
                public function run(): void {
                    $bar = new Bar();
                    $bar->missingMethod();
                }
            }
            PHP);

        $resolver = $this->resolverFor($call['file']);
        $precedingStmts = $call['method']->stmts[0] instanceof Expression ? [$call['method']->stmts[0]] : [];

        self::assertNull($resolver->resolve($call['expr'], $precedingStmts));
    }

    public function testClassMethodTargetReturnsNullWhenResolvedMethodIsAbstract(): void
    {
        $call = $this->firstCallIn(<<<'PHP'
            <?php
            namespace App;
            abstract class Bar {
                abstract public function baz(): void;
            }
            class Foo {
                public function __construct(private Bar $bar) {}
                public function run(): void {
                    $this->bar->baz();
                }
            }
            PHP);

        $resolver = $this->resolverFor($call['file']);

        self::assertNull($resolver->resolve($call['expr'], []));
    }

    /**
     * @return array{expr: Expr, file: ParsedFile, method: ClassMethod}
     */
    private function firstCallIn(string $code): array
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $file = (new PhpFileParser($parser))->parseCode('/virtual/App.php', $code);

        // Search the whole AST (not just the first ClassMethod found) and
        // then walk back up to the enclosing method. Scoping to the first
        // ClassMethod node would silently grab the wrong method whenever a
        // fixture declares an unrelated, call-free class/method first (e.g.
        // a dependency class declared before its consumer) — every given
        // fixture below does exactly that.
        $finder = new NodeFinder();
        $call = $finder->findFirst($file->ast, static fn ($node): bool =>
            $node instanceof \PhpParser\Node\Expr\MethodCall
            || $node instanceof \PhpParser\Node\Expr\StaticCall
            || $node instanceof \PhpParser\Node\Expr\FuncCall);
        self::assertInstanceOf(Expr::class, $call);

        $method = $this->enclosingClassMethod($call);
        self::assertInstanceOf(ClassMethod::class, $method);

        return ['expr' => $call, 'file' => $file, 'method' => $method];
    }

    private function enclosingClassMethod(Node $node): ?ClassMethod
    {
        $current = $node;

        while (true) {
            $parent = $current->getAttribute('parent');
            if (!$parent instanceof Node) {
                return null;
            }

            if ($parent instanceof ClassMethod) {
                return $parent;
            }

            $current = $parent;
        }
    }

    private function resolverFor(ParsedFile $file): CallTargetResolver
    {
        $index = (new ProjectIndexBuilder())->build([$file]);

        return new CallTargetResolver($index);
    }
}
