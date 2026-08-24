<?php

declare(strict_types=1);

namespace Doloto\Big0nia\Tests\Rule;

use Doloto\Big0nia\Analysis\PhpFileParser;
use Doloto\Big0nia\Project\ProjectIndex;
use Doloto\Big0nia\Project\ProjectIndexBuilder;
use Doloto\Big0nia\Rule\InterproceduralLoopJoinRule;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;

final class InterproceduralLoopJoinRuleTest extends TestCase
{
    public function testFindsJoinAcrossASingleTypedPropertyHop(): void
    {
        $index = $this->buildIndex([
            '/virtual/UserService.php' => <<<'PHP'
                <?php
                namespace App;
                class UserService {
                    public function __construct(private OrderMatcher $matcher) {}
                    public function process(array $users): void {
                        foreach ($users as $user) {
                            $this->matcher->matchAll($user);
                        }
                    }
                }
                PHP,
            '/virtual/OrderMatcher.php' => <<<'PHP'
                <?php
                namespace App;
                class OrderMatcher {
                    public function matchAll($user): void {
                        foreach ($this->orders as $order) {
                            if ($user->getId() === $order->getUserId()) {
                                // match
                            }
                        }
                    }
                }
                PHP,
        ]);

        $rule = new InterproceduralLoopJoinRule($index);
        $outer = $this->findFirstForeach($index, 'App\\UserService', 'process');

        $finding = $rule->check($outer, []);

        self::assertNotNull($finding);
        self::assertSame($outer->getLine(), $finding->line);
        self::assertStringContainsString('via OrderMatcher::matchAll()', $finding->message);
        self::assertStringContainsString('getId() vs getUserId()', $finding->message);
        self::assertStringContainsString('/virtual/OrderMatcher.php', $finding->tip);
    }

    public function testReturnsNullWhenNoCallLeadsToAJoinedLoop(): void
    {
        $index = $this->buildIndex([
            '/virtual/UserService.php' => <<<'PHP'
                <?php
                namespace App;
                class UserService {
                    public function __construct(private Logger $logger) {}
                    public function process(array $users): void {
                        foreach ($users as $user) {
                            $this->logger->log($user);
                        }
                    }
                }
                PHP,
            '/virtual/Logger.php' => <<<'PHP'
                <?php
                namespace App;
                class Logger {
                    public function log($user): void {
                        echo $user;
                    }
                }
                PHP,
        ]);

        $rule = new InterproceduralLoopJoinRule($index);
        $outer = $this->findFirstForeach($index, 'App\\UserService', 'process');

        self::assertNull($rule->check($outer, []));
    }

    public function testReturnsNullWhenGivenANonLoopNode(): void
    {
        $index = new ProjectIndex([], [], []);
        $rule = new InterproceduralLoopJoinRule($index);

        self::assertNull($rule->check(new \PhpParser\Node\Stmt\Nop(), []));
    }

    /**
     * @param array<string, string> $filesByPath
     */
    private function buildIndex(array $filesByPath): ProjectIndex
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $fileParser = new PhpFileParser($parser);

        $files = [];
        foreach ($filesByPath as $path => $code) {
            $files[] = $fileParser->parseCode($path, $code);
        }

        return (new ProjectIndexBuilder())->build($files);
    }

    private function findFirstForeach(ProjectIndex $index, string $classFqcn, string $methodName): Foreach_
    {
        $class = $index->findClass($classFqcn);
        self::assertNotNull($class);

        $method = $class->node->getMethod($methodName);
        self::assertNotNull($method);

        $foreach = (new NodeFinder())->findFirstInstanceOf($method->stmts ?? [], Foreach_::class);
        self::assertInstanceOf(Foreach_::class, $foreach);

        return $foreach;
    }
}
