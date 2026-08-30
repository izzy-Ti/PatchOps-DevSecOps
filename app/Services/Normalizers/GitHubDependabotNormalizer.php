<?php

namespace App\Services\Normalizers;

use App\Contracts\VulnerabilityNormalizerInterface;
use App\DTOs\NormalizedVulnerabilityData;
use App\Enums\VulnerabilitySeverity;
use App\Enums\VulnerabilitySource;
use Carbon\Carbon;

class GitHubDependabotNormalizer implements VulnerabilityNormalizerInterface
{
    /**
     * Determine if this normalizer supports the given vulnerability source.
     */
    public function supports(string $source): bool
    {
        $normalized = strtolower(trim($source));

        return in_array($normalized, [
            VulnerabilitySource::DEPENDABOT->value,
            'github',
            'github_dependabot',
        ], true);
    }

    /**
     * Normalize the raw GitHub Dependabot webhook payload.
     *
     * @param  array<string, mixed>  $payload
     */
    public function normalize(array $payload): NormalizedVulnerabilityData
    {
        $alert = $payload['alert'] ?? [];
        $advisory = $alert['security_advisory'] ?? [];
        $vulnerability = $alert['security_vulnerability'] ?? [];
        $dependency = $alert['dependency'] ?? [];
        $repository = $payload['repository'] ?? [];

        $sourceId = $advisory['ghsa_id'] ?? (isset($alert['number']) ? (string) $alert['number'] : ($payload['id'] ?? 'unknown'));
        $cveId = $advisory['cve_id'] ?? $payload['cve_id'] ?? null;
        $title = $advisory['summary'] ?? $payload['title'] ?? 'GitHub Dependabot Advisory';
        $description = $advisory['description'] ?? $payload['description'] ?? null;

        $rawSeverity = strtolower((string) ($advisory['severity'] ?? $vulnerability['severity'] ?? $payload['severity'] ?? 'medium'));
        $severity = match ($rawSeverity) {
            'critical' => VulnerabilitySeverity::CRITICAL,
            'high' => VulnerabilitySeverity::HIGH,
            'low' => VulnerabilitySeverity::LOW,
            default => VulnerabilitySeverity::MEDIUM,
        };

        $packageName = $dependency['package']['name'] ?? $payload['package_name'] ?? 'unknown';
        $affectedVersion = $vulnerability['vulnerable_version_range'] ?? $payload['affected_version'] ?? null;
        $fixedVersion = $vulnerability['first_patched_version']['identifier'] ?? $payload['fixed_version'] ?? null;
        $repoFullName = $repository['full_name'] ?? $payload['repository'] ?? 'unknown/unknown';
        $referenceUrl = $alert['html_url'] ?? $payload['reference_url'] ?? null;

        $firstDetectedAt = null;
        if (! empty($alert['created_at'])) {
            $firstDetectedAt = Carbon::parse($alert['created_at']);
        } elseif (! empty($payload['first_detected_at'])) {
            $firstDetectedAt = Carbon::parse($payload['first_detected_at']);
        }

        return new NormalizedVulnerabilityData(
            source: VulnerabilitySource::DEPENDABOT,
            sourceId: (string) $sourceId,
            cveId: $cveId,
            title: $title,
            description: $description,
            severity: $severity,
            packageName: $packageName,
            affectedVersion: $affectedVersion,
            fixedVersion: $fixedVersion,
            repository: $repoFullName,
            referenceUrl: $referenceUrl,
            rawData: $payload,
            firstDetectedAt: $firstDetectedAt,
        );
    }
}
