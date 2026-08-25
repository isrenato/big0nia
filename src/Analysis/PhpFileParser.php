<?php

declare(strict_types=1);

namespace Doloto\Big0nia\Analysis;

use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\Parser;
use RuntimeException;

final class PhpFileParser
{
    public function __construct(
        private readonly Parser $parser,
    ) {
    }

    public function parse(string $filePath): ParsedFile
    {
        if (!is_readable($filePath)) {
            throw new RuntimeException(sprintf('Could not read file "%s".', $filePath));
        }

        $code = file_get_contents($filePath);
        if ($code === false) {
            throw new RuntimeException(sprintf('Could not read file "%s".', $filePath));
        }

        return $this->parseCode($filePath, $code);
    }

    public function parseCode(string $filePath, string $code): ParsedFile
    {
        $previousLevel = error_reporting();
        error_reporting($previousLevel & ~E_DEPRECATED);
        try {
            $ast = $this->parser->parse($code);
        } finally {
            error_reporting($previousLevel);
        }

        if ($ast === null) {
            return new ParsedFile($filePath, []);
        }

        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver());
        $traverser->addVisitor(new ParentConnectingVisitor());
        /** @var \PhpParser\Node\Stmt[] $ast */
        $ast = $traverser->traverse($ast);

        return new ParsedFile($filePath, $ast);
    }
}
