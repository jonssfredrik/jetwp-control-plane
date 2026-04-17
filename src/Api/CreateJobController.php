<?php

declare(strict_types=1);

namespace JetWP\Control\Api;

use JetWP\Control\Jobs\JobFactory;
use JetWP\Control\Jobs\JobValidator;
use JetWP\Control\Models\Job;

final class CreateJobController
{
    public function __construct(
        private readonly JobValidator $validator,
        private readonly JobFactory $factory
    ) {
    }

    public function handle(array $payload): Job
    {
        $attributes = $this->validator->validateForCreate($payload);

        return $this->factory->create($attributes);
    }
}
