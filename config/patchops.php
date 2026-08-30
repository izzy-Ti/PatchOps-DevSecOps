<?php

return [

    /*
    |--------------------------------------------------------------------------
    | PatchOps Execution Timeouts (in seconds)
    |--------------------------------------------------------------------------
    |
    | Strict ceilings on execution duration across agent queue jobs and sandbox
    | environments to prevent runaway processes and zombie worker threads.
    |
    */

    'timeouts' => [
        'triage_job' => (int) env('TIMEOUT_TRIAGE_JOB', 120),
        'reproduce_job' => (int) env('TIMEOUT_REPRODUCE_JOB', 600),
        'patch_job' => (int) env('TIMEOUT_PATCH_JOB', 300),
        'validate_job' => (int) env('TIMEOUT_VALIDATE_JOB', 900),
        'sandbox_command' => (int) env('TIMEOUT_SANDBOX_COMMAND', 180),
        'sandbox_idle' => (int) env('TIMEOUT_SANDBOX_IDLE', 60),
    ],

];
