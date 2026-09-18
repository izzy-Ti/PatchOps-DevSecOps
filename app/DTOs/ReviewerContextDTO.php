<?php

namespace App\DTOs;

readonly class ReviewerContextDTO
{
    /**
     * @param  array<string, mixed>  $reproductionEvidence
     * @param  array<int, string>  $testSuites
     * @param  array<string, mixed>  $ecosystemInfo
     */
    public function __construct(
        public string $incidentId,
        public string $cveIdentifier,
        public string $repository,
        public string $baseCommitSha,
        public string $patchCandidateDiff,
        public array $reproductionEvidence = [],
        public array $testSuites = [],
        public array $ecosystemInfo = [],
        public ?string $regressionTestCommand = null,
        public ?string $sandboxId = null,
        public ?string $agentRunId = null,
    ) {}

    /**
     * Create a DTO instance from an associative array.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            incidentId: (string) ($data['incident_id'] ?? ''),
            cveIdentifier: (string) ($data['cve_identifier'] ?? ''),
            repository: (string) ($data['repository'] ?? ''),
            baseCommitSha: (string) ($data['base_commit_sha'] ?? ''),
            patchCandidateDiff: (string) ($data['patch_candidate_diff'] ?? ''),
            reproductionEvidence: (array) ($data['reproduction_evidence'] ?? []),
            testSuites: (array) ($data['test_suites'] ?? []),
            ecosystemInfo: (array) ($data['ecosystem_info'] ?? []),
            regressionTestCommand: isset($data['regression_test_command']) ? (string) $data['regression_test_command'] : ($data['test_suites'][0] ?? null),
            sandboxId: isset($data['sandbox_id']) ? (string) $data['sandbox_id'] : null,
            agentRunId: isset($data['agent_run_id']) ? (string) $data['agent_run_id'] : null,
        );
    }

    /**
     * Convert the DTO to an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'incident_id' => $this->incidentId,
            'cve_identifier' => $this->cveIdentifier,
            'repository' => $this->repository,
            'base_commit_sha' => $this->baseCommitSha,
            'patch_candidate_diff' => $this->patchCandidateDiff,
            'reproduction_evidence' => $this->reproductionEvidence,
            'test_suites' => $this->testSuites,
            'ecosystem_info' => $this->ecosystemInfo,
            'regression_test_command' => $this->regressionTestCommand,
            'sandbox_id' => $this->sandboxId,
            'agent_run_id' => $this->agentRunId,
        ];
    }
}
