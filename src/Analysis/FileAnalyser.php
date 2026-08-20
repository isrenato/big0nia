<?php

declare(strict_types=1);

namespace Doloto\Big0nia\Analysis;

use Doloto\Big0nia\Rule\LoopJoinRule;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\Parser;
use RuntimeException;

final class FileAnalyser
{
    /**
     * @param LoopJoinRule[] $rules
     */
    public function __construct(
        private readonly Parser $parser,
        private readonly array $rules,
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

        $previousLevel = error_reporting();
        error_reporting($previousLevel & ~E_DEPRECATED);
        try {
            $ast = $this->parser->parse($code);
        } finally {
            error_reporting($previousLevel);
        }

        if ($ast === null) {
            return [];
        }

        $traverser = new NodeTraverser();
        $traverser->addVisitor(new ParentConnectingVisitor());

        $collector = new LoopCollectingVisitor();
        $traverser->addVisitor($collector);

        $traverser->traverse($ast);

        $diagnostics = [];

        foreach ($collector->getLoopNodes() as $loopNode) {
            $precedingStmts = $this->precedingStatements($loopNode);

            foreach ($this->rules as $rule) {
                $finding = $rule->check($loopNode, $precedingStmts);
                if ($finding !== null) {
                    $diagnostics[] = new Diagnostic($filePath, $finding->line, $finding->message, $finding->tip);
                }
            }
        }

        return $diagnostics;
    }

    /**
     * @return Node\Stmt[]
     */
    private function precedingStatements(Node\Stmt $node): array
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
