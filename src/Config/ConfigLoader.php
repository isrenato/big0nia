<?php

declare(strict_types=1);

namespace Doloto\Big0nia\Config;

use Nette\Neon\Exception as NeonException;
use Nette\Neon\Neon;

final class ConfigLoader
{
    private const FILENAME = 'big0nia.neon';

    public function load(string $cwd): AnalyzerConfig
    {
        $path = rtrim($cwd, '/') . '/' . self::FILENAME;

        if (!is_file($path)) {
            return new AnalyzerConfig([]);
        }

        try {
            $data = Neon::decodeFile($path);
        } catch (NeonException $e) {
            throw new ConfigException(sprintf('Malformed config file %s: %s', $path, $e->getMessage()), 0, $e);
        }

        if ($data === null) {
            return new AnalyzerConfig([]);
        }

        if (!is_array($data)) {
            throw new ConfigException(sprintf(
                'Config file %s must decode to a map, got %s.',
                $path,
                get_debug_type($data)
            ));
        }

        if (!array_key_exists('ignore_paths', $data)) {
            return new AnalyzerConfig([]);
        }

        return new AnalyzerConfig($this->validateIgnorePaths($data['ignore_paths'], $path));
    }

    /**
     * @return string[]
     */
    private function validateIgnorePaths(mixed $ignorePaths, string $path): array
    {
        if (!is_array($ignorePaths) || !array_is_list($ignorePaths)) {
            throw new ConfigException(sprintf(
                'Config key "ignore_paths" in %s must be a list of strings, got %s.',
                $path,
                get_debug_type($ignorePaths)
            ));
        }

        foreach ($ignorePaths as $entry) {
            if (!is_string($entry)) {
                throw new ConfigException(sprintf(
                    'Config key "ignore_paths" in %s must contain only strings, found %s.',
                    $path,
                    get_debug_type($entry)
                ));
            }

            if ($entry === '') {
                throw new ConfigException(sprintf(
                    'Config key "ignore_paths" in %s must not contain empty strings.',
                    $path
                ));
            }
        }

        return $ignorePaths;
    }
}
