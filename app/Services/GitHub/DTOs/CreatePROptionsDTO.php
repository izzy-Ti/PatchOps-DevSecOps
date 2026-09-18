<?php

namespace App\Services\GitHub\DTOs;

class CreatePROptionsDTO
{
    public function __construct(
        public readonly string $repository,
        public readonly string $branch,
        public readonly string $commitMessage,
        public readonly string $title,
        public readonly string $body,
        public readonly string $baseBranch = 'main',
    ) {}
}
