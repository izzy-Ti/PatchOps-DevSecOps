<?php

namespace App\Services\Normalizers;

use App\Contracts\VulnerabilityNormalizerInterface;
use App\DTOs\NormalizedVulnerabilityData;
use App\Enums\VulnerabilitySeverity;
use App\Enums\VulnerabilitySource;
use Carbon\Carbon;

class SnykNormalizer implements VulnerabilityNormalizerInterface
{
    /**
     * Determine if this normalizer supports the given vulnerability source.
     */
    public function supports(string $source): bool
    {
        $normalized = strtolower(trim($source));

        return in_array($normalized, [
            VulnerabilitySource::SNYK->value,
            'snyk_security',
            'snyk_webhook',
        ], true);
    }

    /**
     * Normalize the raw Snyk webhook payload.
     *
     * @param  array<string, mixed>  $payload
     */
    public function normalize(array $payload): NormalizedVulnerabilityData
    {
        $issue = $payload['issue'] ?? $payload;
        $project = $payload['project'] ?? [];
        $identifiers = $issue['identifiers'] ?? [];

        $sourceId = $issue['id'] ?? $payload['id'] ?? $payload['vulnId'] ?? 'snyk-unknown';

        $cveId = $issue['cve']
            ?? (is_array($identifiers['CVE'] ?? null) ? $identifiers['CVE'][0] : ($identifiers['CVE'] ?? null))
            ?? $payload['cve_id']
            ?? null;

        $title = $issue['title'] ?? $payload['title'] ?? 'Snyk Security Advisory';
        $description = $issue['description'] ?? $payload['description'] ?? null;

        $rawSeverity = strtolower((string) ($issue['severity'] ?? $payload['severity'] ?? 'medium'));
        $severity = match ($rawSeverity) {
            'critical' => VulnerabilitySeverity::CRITICAL,
            'high' => VulnerabilitySeverity::HIGH,
            'low' => VulnerabilitySeverity::LOW,
            default => VulnerabilitySeverity::MEDIUM,
        };

        $packageName = $issue['package'] ?? $payload['package'] ?? $payload['package_name'] ?? 'unknown';
        $affectedVersion = $issue['version'] ?? $payload['version'] ?? $payload['affected_version'] ?? null;

        $fixedVersion = null;
        if (isset($issue['fixedIn']) && is_array($issue['fixedIn']) && ! empty($issue['fixedIn'])) {
            $fixedVersion = (string) $issue['fixedIn'][0];
        } elseif (! empty($payload['fixed_version'])) {
            $fixedVersion = (string) $payload['fixed_version'];
        }

        $repository = $project['name'] ?? $payload['repository'] ?? 'unknown/unknown';
        $referenceUrl = $issue['url'] ?? $payload['url'] ?? $payload['reference_url'] ?? null;

        $firstDetectedAt = null;
        if (! empty($issue['created'])) {
            $firstDetectedAt = Carbon::parse($issue['created']);
        } elseif (! empty($payload['first_detected_at'])) {
            $firstDetectedAt = Carbon::parse($payload['first_detected_at']);
        }

        return new NormalizedVulnerabilityData(
            source: VulnerabilitySource::SNYK,
            sourceId: (string) $sourceId,
            cveId: $cveId,
            title: $title,
            description: $description,
            severity: $severity,
            packageName: $packageName,
            affectedVersion: $affectedVersion,
            fixedVersion: $fixedVersion,
            repository: $repository,
            referenceUrl: $referenceUrl,
            rawData: $payload,
            firstDetectedAt: $firstDetectedAt,
        );
    }
}
