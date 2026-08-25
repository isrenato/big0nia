<?php

declare(strict_types=1);

namespace Doloto\Big0nia\Tests\Ast;

use Doloto\Big0nia\Ast\CallSiteFinder;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;

final class CallSiteFinderTest extends TestCase
{
    public function testFindsBareExpressionCall(): void
    {
        $stmts = $this->parse(<<<'PHP'
            <?php
            $a = 1;
            $this->service->process($item);
            PHP);

        $finder = new CallSiteFinder();
        $sites = $finder->findAll($stmts);

        self::assertCount(1, $sites);
        self::assertInstanceOf(MethodCall::class, $sites[0]->call);
        self::assertCount(1, $sites[0]->precedingStmts);
    }

    public function testFindsCallAsAssignmentRhs(): void
    {
        $stmts = $this->parse(<<<'PHP'
            <?php
            $result = $this->service->process($item);
            PHP);

        $finder = new CallSiteFinder();
        $sites = $finder->findAll($stmts);

        self::assertCount(1, $sites);
        self::assertInstanceOf(MethodCall::class, $sites[0]->call);
        self::assertCount(0, $sites[0]->precedingStmts);
    }

    public function testFindsCallsInsideIfGuardsWithCorrectPrecedingStatements(): void
    {
        $stmts = $this->parse(<<<'PHP'
            <?php
            $a = 1;
            if ($cond) {
                $b = 2;
                process($item);
            }
            PHP);

        $finder = new CallSiteFinder();
        $sites = $finder->findAll($stmts);

        self::assertCount(1, $sites);
        self::assertInstanceOf(FuncCall::class, $sites[0]->call);
        self::assertCount(2, $sites[0]->precedingStmts);
    }

    public function testFindsCallInsideAReturnStatement(): void
    {
        $stmts = $this->parse(<<<'PHP'
            <?php
            $a = 1;
            return $this->service->process($item);
            PHP);

        $finder = new CallSiteFinder();
        $sites = $finder->findAll($stmts);

        self::assertCount(1, $sites);
        self::assertInstanceOf(MethodCall::class, $sites[0]->call);
        self::assertCount(1, $sites[0]->precedingStmts);
    }

    public function testDoesNotDescendIntoOtherLoops(): void
    {
        $stmts = $this->parse(<<<'PHP'
            <?php
            foreach ($items as $item) {
                process($item);
            }
            PHP);

        $finder = new CallSiteFinder();

        self::assertSame([], $finder->findAll($stmts));
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
