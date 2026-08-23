<?php

declare(strict_types=1);

namespace Doloto\Big0nia\Tests\Ast;

use Doloto\Big0nia\Ast\PrecedingStatementsFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;

final class PrecedingStatementsFinderTest extends TestCase
{
    public function testReturnsStatementsPrecedingTheGivenNode(): void
    {
        $ast = $this->parse(<<<'PHP'
            <?php
            function test() {
                $a = 1;
                $b = 2;
                foreach ($items as $item) {
                    echo $item;
                }
                $c = 3;
            }
            PHP);

        $function = $ast[0];
        $stmts = $function->stmts;
        $foreach = $stmts[2];

        $finder = new PrecedingStatementsFinder();

        $preceding = $finder->find($foreach);

        self::assertCount(2, $preceding);
        self::assertSame($stmts[0], $preceding[0]);
        self::assertSame($stmts[1], $preceding[1]);
    }

    public function testReturnsEmptyArrayWhenNodeHasNoParent(): void
    {
        $ast = $this->parse('<?php $a = 1;');

        $finder = new PrecedingStatementsFinder();

        self::assertSame([], $finder->find($ast[0]));
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
