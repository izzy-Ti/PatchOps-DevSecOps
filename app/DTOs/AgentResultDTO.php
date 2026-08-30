<?php

namespace App\DTOs;

readonly class AgentResultDTO
{
    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public bool $success,
        public string $status,
        public array $data = [],
        public ?AgentErrorDTO $error = null,
        public array $metadata = [],
    ) {}

    /**
     * Create a successful agent result envelope.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $metadata
     */
    public static function success(array $data, array $metadata = []): self
    {
        return new self(
            success: true,
            status: 'completed',
            data: $data,
            error: null,
            metadata: $metadata,
        );
    }

    /**
     * Create a failed agent result envelope.
     *
     * @param  array<string, mixed>  $details
     * @param  array<string, mixed>  $metadata
     */
    public static function failure(
        string $code,
        string $message,
        array $details = [],
        array $metadata = [],
    ): self {
        return new self(
            success: false,
            status: 'failed',
            data: [],
            error: new AgentErrorDTO($code, $message, $details),
            metadata: $metadata,
        );
    }

    /**
     * Convert the result envelope to array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'status' => $this->status,
            'data' => $this->data,
            'error' => $this->error?->toArray(),
            'metadata' => $this->metadata,
        ];
    }
}
