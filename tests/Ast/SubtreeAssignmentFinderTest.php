<?php

declare(strict_types=1);

namespace Doloto\Big0nia\Tests\Ast;

use Doloto\Big0nia\Ast\SubtreeAssignmentFinder;
use PhpParser\Node;
use PhpParser\Node\Expr\Variable;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;

final class SubtreeAssignmentFinderTest extends TestCase
{
    public function testFindsATopLevelAssignmentToTheTarget(): void
    {
        $stmts = $this->parse(<<<'PHP'
            <?php
            $a = 1;
            PHP);

        $finder = new SubtreeAssignmentFinder();

        self::assertTrue($finder->anyAssigns($stmts, $this->targetsVariable('a')));
    }

    public function testFindsAnAssignmentNestedInsideAnIfBlock(): void
    {
        $stmts = $this->parse(<<<'PHP'
            <?php
            if ($cond) {
                $a = 1;
            }
            PHP);

        $finder = new SubtreeAssignmentFinder();

        self::assertTrue($finder->anyAssigns($stmts, $this->targetsVariable('a')));
    }

    public function testFindsACompoundAssignmentToTheTarget(): void
    {
        $stmts = $this->parse(<<<'PHP'
            <?php
            $a += 1;
            PHP);

        $finder = new SubtreeAssignmentFinder();

        self::assertTrue($finder->anyAssigns($stmts, $this->targetsVariable('a')));
    }

    public function testReturnsFalseWhenNoAssignmentMatchesTheTarget(): void
    {
        $stmts = $this->parse(<<<'PHP'
            <?php
            $b = 1;
            if ($cond) {
                $c = 2;
            }
            echo $a;
            PHP);

        $finder = new SubtreeAssignmentFinder();

        self::assertFalse($finder->anyAssigns($stmts, $this->targetsVariable('a')));
    }

    public function testDoesNotDescendIntoAnAnonymousClass(): void
    {
        $stmts = $this->parse(<<<'PHP'
            <?php
            $obj = new class {
                public function set(): void {
                    $a = 1;
                }
            };
            PHP);

        $finder = new SubtreeAssignmentFinder();

        self::assertFalse($finder->anyAssigns($stmts, $this->targetsVariable('a')));
    }

    private function targetsVariable(string $name): callable
    {
        return static fn (Node $target): bool => $target instanceof Variable && $target->name === $name;
    }

    /**
     * @return \PhpParser\Node\Stmt[]
     */
    private function parse(string $code): array
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $ast = $parser->parse($code);
        self::assertNotNull($ast);

        $traverser = new NodeTraverser();
        $traverser->addVisitor(new ParentConnectingVisitor());

        return $traverser->traverse($ast);
    }
}
