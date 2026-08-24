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

    public function testReturnsNullWhenTheJoinArgumentIsPassedByName(): void
    {
        // The tracked variable is passed as a *named* argument (`needle: $user`), landing
        // at array index 0 even though it is not bound to the parameter at position 0
        // ($haystack). If the rule mapped argument positions purely by array index, it
        // would wrongly track `$haystack` (always null here, never actually receiving
        // $user) and stumble into a spurious join on it. It must instead refuse to resolve
        // through this call entirely.
        $index = $this->buildIndex([
            '/virtual/UserService.php' => <<<'PHP'
                <?php
                namespace App;
                class UserService {
                    public function __construct(private OrderMatcher $matcher) {}
                    public function process(array $users): void {
                        foreach ($users as $user) {
                            $this->matcher->matchAll(needle: $user);
                        }
                    }
                }
                PHP,
            '/virtual/OrderMatcher.php' => <<<'PHP'
                <?php
                namespace App;
                class OrderMatcher {
                    public function matchAll($haystack = null, $needle = null): void {
                        foreach ($this->orders as $order) {
                            if ($haystack->getId() === $order->getUserId()) {
                                // match
                            }
                        }
                    }
                }
                PHP,
        ]);

        $rule = new InterproceduralLoopJoinRule($index);
        $outer = $this->findFirstForeach($index, 'App\\UserService', 'process');

        self::assertNull($rule->check($outer, []));
    }

    public function testReturnsNullWhenAnEarlierArgumentIsUnpacked(): void
    {
        // A spread/unpacked argument before the tracked variable makes every subsequent
        // positional index unreliable, because the spread's runtime length is unknown
        // statically: here `...$extra, $user` binds $user to $first (position 0) if
        // $extra unpacks to zero items, but a naive array-index scan would return
        // position 1 ($needle). The rule must bail out of the whole call site rather than
        // guess.
        $index = $this->buildIndex([
            '/virtual/UserService.php' => <<<'PHP'
                <?php
                namespace App;
                class UserService {
                    public function __construct(private OrderMatcher $matcher) {}
                    public function process(array $users): void {
                        foreach ($users as $user) {
                            $extra = [];
                            $this->matcher->matchAll(...$extra, $user);
                        }
                    }
                }
                PHP,
            '/virtual/OrderMatcher.php' => <<<'PHP'
                <?php
                namespace App;
                class OrderMatcher {
                    public function matchAll($first = null, $needle = null): void {
                        foreach ($this->orders as $order) {
                            if ($needle->getId() === $order->getUserId()) {
                                // match
                            }
                        }
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

    public function testFollowsATransitiveTwoHopChain(): void
    {
        $index = $this->buildIndex([
            '/virtual/UserService.php' => <<<'PHP'
                <?php
                namespace App;
                class UserService {
                    public function __construct(private Dispatcher $dispatcher) {}
                    public function process(array $users): void {
                        foreach ($users as $user) {
                            $this->dispatcher->dispatch($user);
                        }
                    }
                }
                PHP,
            '/virtual/Dispatcher.php' => <<<'PHP'
                <?php
                namespace App;
                class Dispatcher {
                    public function __construct(private OrderMatcher $matcher) {}
                    public function dispatch($user): void {
                        $this->matcher->matchAll($user);
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
        self::assertStringContainsString('via Dispatcher::dispatch() → OrderMatcher::matchAll()', $finding->message);
    }

    public function testFollowsAChainStartingFromACanonicalForLoop(): void
    {
        $index = $this->buildIndex([
            '/virtual/UserService.php' => <<<'PHP'
                <?php
                namespace App;
                class UserService {
                    public function __construct(private OrderMatcher $matcher) {}
                    public function process(array $users): void {
                        for ($i = 0; $i < count($users); $i++) {
                            $this->matcher->matchOne($users[$i]);
                        }
                    }
                }
                PHP,
            '/virtual/OrderMatcher.php' => <<<'PHP'
                <?php
                namespace App;
                class OrderMatcher {
                    public function matchOne($user): void {
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
        $class = $index->findClass('App\\UserService');
        self::assertNotNull($class);
        $method = $class->node->getMethod('process');
        self::assertNotNull($method);
        $outer = (new \PhpParser\NodeFinder())->findFirstInstanceOf($method->stmts ?? [], \PhpParser\Node\Stmt\For_::class);
        self::assertInstanceOf(\PhpParser\Node\Stmt\For_::class, $outer);

        $finding = $rule->check($outer, []);

        self::assertNotNull($finding);
        self::assertStringContainsString('via OrderMatcher::matchOne()', $finding->message);
    }

    public function testResolvesAStaticCallHop(): void
    {
        $index = $this->buildIndex([
            '/virtual/UserService.php' => <<<'PHP'
                <?php
                namespace App;
                class UserService {
                    public function process(array $users): void {
                        foreach ($users as $user) {
                            OrderMatcher::matchAll($user);
                        }
                    }
                }
                PHP,
            '/virtual/OrderMatcher.php' => <<<'PHP'
                <?php
                namespace App;
                class OrderMatcher {
                    public static function matchAll($user): void {
                        foreach (self::orders() as $order) {
                            if ($user->getId() === $order->getUserId()) {
                                // match
                            }
                        }
                    }
                    private static function orders(): array { return []; }
                }
                PHP,
        ]);

        $rule = new InterproceduralLoopJoinRule($index);
        $outer = $this->findFirstForeach($index, 'App\\UserService', 'process');

        $finding = $rule->check($outer, []);

        self::assertNotNull($finding);
        self::assertStringContainsString('via OrderMatcher::matchAll()', $finding->message);
    }

    public function testResolvesAFreeFunctionHop(): void
    {
        $index = $this->buildIndex([
            '/virtual/App.php' => <<<'PHP'
                <?php
                namespace App;

                function matchAll($user, array $orders): void {
                    foreach ($orders as $order) {
                        if ($user->getId() === $order->getUserId()) {
                            // match
                        }
                    }
                }

                class UserService {
                    public function process(array $users, array $orders): void {
                        foreach ($users as $user) {
                            matchAll($user, $orders);
                        }
                    }
                }
                PHP,
        ]);

        $rule = new InterproceduralLoopJoinRule($index);
        $outer = $this->findFirstForeach($index, 'App\\UserService', 'process');

        $finding = $rule->check($outer, []);

        self::assertNotNull($finding);
        self::assertStringContainsString('via matchAll()', $finding->message);
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

    public function testResolvesALocalNewAssignedVariableHop(): void
    {
        $index = $this->buildIndex([
            '/virtual/UserService.php' => <<<'PHP'
                <?php
                namespace App;
                class UserService {
                    public function process(array $users): void {
                        $matcher = new OrderMatcher();
                        foreach ($users as $user) {
                            $matcher->matchAll($user);
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
        $class = $index->findClass('App\\UserService');
        self::assertNotNull($class);
        $method = $class->node->getMethod('process');
        self::assertNotNull($method);
        $outer = (new \PhpParser\NodeFinder())->findFirstInstanceOf($method->stmts ?? [], Foreach_::class);
        self::assertInstanceOf(Foreach_::class, $outer);
        $precedingStmts = array_slice($method->stmts ?? [], 0, 1);

        $finding = $rule->check($outer, $precedingStmts);

        self::assertNotNull($finding);
        self::assertStringContainsString('via OrderMatcher::matchAll()', $finding->message);
    }

    public function testChainBreaksOnAnUnresolvableCallTarget(): void
    {
        $index = $this->buildIndex([
            '/virtual/UserService.php' => <<<'PHP'
                <?php
                namespace App;
                class UserService {
                    public function __construct(private \Vendor\External $external) {}
                    public function process(array $users): void {
                        foreach ($users as $user) {
                            $this->external->handle($user);
                        }
                    }
                }
                PHP,
        ]);

        $rule = new InterproceduralLoopJoinRule($index);
        $outer = $this->findFirstForeach($index, 'App\\UserService', 'process');

        self::assertNull($rule->check($outer, []));
    }

    public function testDoesNotHangOrDoubleReportOnACallCycle(): void
    {
        $index = $this->buildIndex([
            '/virtual/A.php' => <<<'PHP'
                <?php
                namespace App;
                class A {
                    public function __construct(private B $b) {}
                    public function process(array $users): void {
                        foreach ($users as $user) {
                            $this->b->step($user);
                        }
                    }
                    public function step($user): void {
                        $this->b->step($user);
                    }
                }
                PHP,
            '/virtual/B.php' => <<<'PHP'
                <?php
                namespace App;
                class B {
                    public function __construct(private A $a) {}
                    public function step($user): void {
                        $this->a->step($user);
                    }
                }
                PHP,
        ]);

        $rule = new InterproceduralLoopJoinRule($index);
        $outer = $this->findFirstForeach($index, 'App\\A', 'process');

        self::assertNull($rule->check($outer, []));
    }

    public function testSuppressesWhenTheInnerCollectionInAnotherClassIsASmallFixedArray(): void
    {
        $index = $this->buildIndex([
            '/virtual/UserService.php' => <<<'PHP'
                <?php
                namespace App;
                class UserService {
                    public function __construct(private StatusMatcher $matcher) {}
                    public function process(array $users): void {
                        foreach ($users as $user) {
                            $this->matcher->matchAll($user);
                        }
                    }
                }
                PHP,
            '/virtual/StatusMatcher.php' => <<<'PHP'
                <?php
                namespace App;
                class StatusMatcher {
                    private array $statuses = ['a', 'b'];
                    public function matchAll($user): void {
                        foreach ($this->statuses as $status) {
                            if ($user->getId() === $status->getId()) {
                                // match
                            }
                        }
                    }
                }
                PHP,
        ]);

        $rule = new InterproceduralLoopJoinRule($index);
        $outer = $this->findFirstForeach($index, 'App\\UserService', 'process');

        self::assertNull($rule->check($outer, []));
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
