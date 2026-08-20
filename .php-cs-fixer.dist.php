<?php

declare(strict_types=1);

$finder = (new PhpCsFixer\Finder())
    ->in([__DIR__ . '/src', __DIR__ . '/tests'])
    ->notPath('Console/data/invalid-syntax.php')
    ->append([__DIR__ . '/bin/big0nia']);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PSR12' => true,
        '@PSR12:risky' => true,
        'declare_strict_types' => true,
        'no_unused_imports' => true,
        'ordered_imports' => true,
        'single_quote' => true,
        'trailing_comma_in_multiline' => true,
        'no_trailing_whitespace' => true,
        'blank_line_after_namespace' => true,
        'blank_line_after_opening_tag' => true,
    ])
    ->setFinder($finder);
