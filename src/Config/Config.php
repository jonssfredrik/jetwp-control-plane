<?php

declare(strict_types=1);

namespace JetWP\Control\Config;

use RuntimeException;

final class Config
{
    public function __construct(private readonly array $values)
    {
    }

    public static function load(string $path, ?string $fallbackPath = null): self
    {
        $resolvedPath = is_file($path) ? $path : $fallbackPath;

        if ($resolvedPath === null || !is_file($resolvedPath)) {
            throw new RuntimeException('Configuration file not found.');
        }

        $values = require $resolvedPath;
        if (!is_array($values)) {
            throw new RuntimeException(sprintf('Configuration file %s must return an array.', $resolvedPath));
        }

        return new self($values);
    }

    public function all(): array
    {
        return $this->values;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        if ($key === '') {
            return $default;
        }

        $segments = explode('.', $key);
        $value = $this->values;

        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }

            $value = $value[$segment];
        }

        return $value;
    }
}
