<?php

declare(strict_types=1);

namespace Doloto\Big0nia\Analysis;

use Doloto\Big0nia\Rule\NestedLoopJoinRule;
use PhpParser\Node;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\Parser;
use RuntimeException;

final class FileAnalyser
{
    public function __construct(
        private readonly Parser $parser,
        private readonly NestedLoopJoinRule $rule,
    ) {
    }

    /**
     * @return Diagnostic[]
     */
    public function analyse(string $filePath): array
    {
        $code = file_get_contents($filePath);
        if ($code === false) {
            throw new RuntimeException(sprintf('Could not read file "%s".', $filePath));
        }

        $ast = $this->parser->parse($code);
        if ($ast === null) {
            return [];
        }

        $traverser = new NodeTraverser();
        $traverser->addVisitor(new ParentConnectingVisitor());

        $collector = new ForeachCollectingVisitor();
        $traverser->addVisitor($collector);

        $traverser->traverse($ast);

        $diagnostics = [];

        foreach ($collector->getForeachNodes() as $foreachNode) {
            $finding = $this->rule->check($foreachNode, $this->precedingStatements($foreachNode));
            if ($finding !== null) {
                $diagnostics[] = new Diagnostic($filePath, $finding->line, $finding->message, $finding->tip);
            }
        }

        return $diagnostics;
    }

    /**
     * @return Node\Stmt[]
     */
    private function precedingStatements(Foreach_ $node): array
    {
        $parent = $node->getAttribute('parent');
        if (!$parent instanceof Node) {
            return [];
        }

        foreach ($parent->getSubNodeNames() as $subNodeName) {
            $subNode = $parent->$subNodeName;
            if (!is_array($subNode)) {
                continue;
            }

            $index = array_search($node, $subNode, true);
            if (!is_int($index)) {
                continue;
            }

            return array_slice($subNode, 0, $index);
        }

        return [];
    }
}
