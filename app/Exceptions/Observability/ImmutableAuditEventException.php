<?php

namespace App\Exceptions\Observability;

use RuntimeException;

class ImmutableAuditEventException extends RuntimeException
{
    public function __construct(string $message = 'Audit events are strictly immutable and append-only. Updates and deletions are prohibited.')
    {
        parent::__construct($message);
    }
}
