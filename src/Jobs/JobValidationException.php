<?php

declare(strict_types=1);

namespace JetWP\Control\Jobs;

use InvalidArgumentException;

final class JobValidationException extends InvalidArgumentException
{
    /**
     * @param array<string, array<int, string>> $errors
     */
    public function __construct(
        public readonly array $errors,
        string $message = 'Validation failed.'
    ) {
        parent::__construct($message);
    }
}
