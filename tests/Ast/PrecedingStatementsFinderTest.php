<?php

declare(strict_types=1);

namespace Doloto\Big0nia\Tests\Ast;

use Doloto\Big0nia\Ast\PrecedingStatementsFinder;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\Function_;
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

    public function testReturnsEmptyArrayWhenNodeNotFoundInParentSubnodes(): void
    {
        // Create a foreach node not actually contained in the parent's subnodes
        $foreach = new Foreach_(new Variable('items'), new Variable('item'), []);
        $function = new Function_('test', ['stmts' => []]);

        // Manually set parent, but don't add foreach to function's stmts
        $foreach->setAttribute('parent', $function);

        $finder = new PrecedingStatementsFinder();

        // Should return empty array because foreach is not actually in function's stmts
        self::assertSame([], $finder->find($foreach));
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
