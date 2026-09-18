<?php

namespace App\Services\Sandbox\Guards;

use App\Exceptions\MCP\ForbiddenHostCapabilityException;
use App\Models\Incident;

class SandboxNetworkEgressGuard
{
    /**
     * Subnets strictly prohibited from sandbox egress.
     * RFC 1918 Private ranges, loopback, link-local, and cloud metadata.
     */
    protected const BLOCKED_CIDRS = [
        '10.0.0.0/8',
        '172.16.0.0/12',
        '192.168.0.0/16',
        '127.0.0.0/8',
        '169.254.0.0/16', // Includes 169.254.169.254
        '0.0.0.0/8',
    ];

    /**
     * Blocked hostnames for cloud metadata services.
     */
    protected const BLOCKED_HOSTS = [
        '169.254.169.254',
        'metadata.google.internal',
        'metadata.goog',
        'instance-data',
        'localhost',
    ];

    /**
     * Approved external package repositories when dependency installation is allowed.
     */
    protected const APPROVED_REGISTRIES = [
        'packagist.org',
        'repo.packagist.org',
        'npmjs.org',
        'registry.npmjs.org',
        'pypi.org',
        'files.pythonhosted.org',
        'github.com',
        'api.github.com',
    ];

    /**
     * Validate an outbound network target (hostname, URL, or IP address).
     *
     * @throws ForbiddenHostCapabilityException
     */
    public function validateTarget(string $target, ?Incident $incident = null): void
    {
        $parsed = parse_url($target);
        $host = $parsed['host'] ?? $target;

        // Strip port if present
        if (str_contains($host, ':')) {
            $host = explode(':', $host)[0];
        }

        $lowerHost = strtolower(trim($host));

        // 1. Direct hostname check for metadata endpoints
        if (in_array($lowerHost, self::BLOCKED_HOSTS, true)) {
            throw new ForbiddenHostCapabilityException(
                capability: 'network_egress',
                reason: "Egress to cloud metadata or internal host [{$lowerHost}] is strictly blocked by sandbox firewall.",
                incident: $incident,
                violatingPayload: $target,
            );
        }

        // 2. Resolve IP and check CIDR boundaries
        $ip = filter_var($lowerHost, FILTER_VALIDATE_IP) ? $lowerHost : @gethostbyname($lowerHost);

        if ($ip && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            foreach (self::BLOCKED_CIDRS as $cidr) {
                if ($this->ipInCidr($ip, $cidr)) {
                    throw new ForbiddenHostCapabilityException(
                        capability: 'network_egress',
                        reason: "Outbound egress to private/metadata IP [{$ip}] in subnet [{$cidr}] is strictly prohibited.",
                        incident: $incident,
                        violatingPayload: $target,
                    );
                }
            }
        }
    }

    /**
     * Check if a given host is in the approved registry allowlist.
     */
    public function isApprovedRegistry(string $host): bool
    {
        $lowerHost = strtolower(trim($host));
        foreach (self::APPROVED_REGISTRIES as $approved) {
            if ($lowerHost === $approved || str_ends_with($lowerHost, '.'.$approved)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if an IPv4 address falls within a CIDR range.
     */
    protected function ipInCidr(string $ip, string $cidr): bool
    {
        [$subnet, $mask] = explode('/', $cidr);
        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);

        if ($ipLong === false || $subnetLong === false) {
            return false;
        }

        $maskBits = (int) $mask;
        $netmask = ~((1 << (32 - $maskBits)) - 1);

        return ($ipLong & $netmask) === ($subnetLong & $netmask);
    }
}
