<?php

declare(strict_types=1);

namespace Doloto\Big0nia\Tests\Config;

use Doloto\Big0nia\Config\AnalyzerConfig;
use Doloto\Big0nia\Config\ConfigException;
use Doloto\Big0nia\Config\ConfigLoader;
use PHPUnit\Framework\TestCase;

final class ConfigLoaderTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/big0nia-config-test-' . uniqid();
        mkdir($this->tempDir);
    }

    protected function tearDown(): void
    {
        $file = $this->tempDir . '/big0nia.neon';
        if (is_file($file)) {
            unlink($file);
        }

        rmdir($this->tempDir);
    }

    public function testReturnsEmptyConfigWhenFileDoesNotExist(): void
    {
        $config = (new ConfigLoader())->load($this->tempDir);

        self::assertEquals(new AnalyzerConfig([]), $config);
    }

    public function testReturnsIgnorePathsFromValidConfig(): void
    {
        $this->writeConfig("ignore_paths:\n    - vendor\n    - src/Legacy\n");

        $config = (new ConfigLoader())->load($this->tempDir);

        self::assertEquals(new AnalyzerConfig(['vendor', 'src/Legacy']), $config);
    }

    public function testReturnsEmptyConfigWhenFileHasNoIgnorePathsKey(): void
    {
        $this->writeConfig("some_other_key: 1\n");

        $config = (new ConfigLoader())->load($this->tempDir);

        self::assertEquals(new AnalyzerConfig([]), $config);
    }

    public function testReturnsEmptyConfigWhenFileIsEmpty(): void
    {
        $this->writeConfig('');

        $config = (new ConfigLoader())->load($this->tempDir);

        self::assertEquals(new AnalyzerConfig([]), $config);
    }

    public function testThrowsOnMalformedNeonSyntax(): void
    {
        $this->writeConfig("ignore_paths: [\n");

        $this->expectException(ConfigException::class);

        (new ConfigLoader())->load($this->tempDir);
    }

    public function testThrowsWhenIgnorePathsIsNotAnArray(): void
    {
        $this->writeConfig("ignore_paths: vendor\n");

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('must be a list of strings, got string');

        (new ConfigLoader())->load($this->tempDir);
    }

    public function testThrowsWhenIgnorePathsIsAMapNotAList(): void
    {
        $this->writeConfig("ignore_paths:\n    key: value\n");

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('must be a list of strings, got array');

        (new ConfigLoader())->load($this->tempDir);
    }

    public function testThrowsWhenIgnorePathsContainsANonStringItem(): void
    {
        $this->writeConfig("ignore_paths:\n    - vendor\n    - 42\n");

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('must contain only strings, found int');

        (new ConfigLoader())->load($this->tempDir);
    }

    private function writeConfig(string $contents): void
    {
        file_put_contents($this->tempDir . '/big0nia.neon', $contents);
    }
}
