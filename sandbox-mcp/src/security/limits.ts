/**
 * PatchOps Sandbox Resource & Execution Hard Limits
 *
 * Enforces cgroups and kernel quotas per container instance to prevent
 * DoS, fork bombs, memory leaks, and host resource starvation.
 */

export interface SandboxResourceLimits {
  nanoCpus: number;       // 2.0 vCPU = 2 * 10^9 nanoCPUs
  memoryBytes: number;    // 2 GB = 2 * 1024 * 1024 * 1024
  memorySwapBytes: number;// 2 GB (no additional swap)
  pidsLimit: number;      // Max 100 concurrent processes
  tmpfsBytes: number;     // 512 MB tmpfs mounted at /tmp
  defaultTimeoutSec: number; // 180 seconds default execution timeout
  maxTimeoutSec: number;     // 600 seconds absolute ceiling
  unprivilegedUid: number;   // 1000:1000
  workspacePath: string;     // /app
}

export const SANDBOX_LIMITS: SandboxResourceLimits = {
  nanoCpus: 2.0 * 1e9,
  memoryBytes: 2 * 1024 * 1024 * 1024,
  memorySwapBytes: 2 * 1024 * 1024 * 1024,
  pidsLimit: 100,
  tmpfsBytes: 512 * 1024 * 1024,
  defaultTimeoutSec: 180,
  maxTimeoutSec: 600,
  unprivilegedUid: 1000,
  workspacePath: '/app',
};

export const RUNTIME_IMAGES: Record<string, string> = {
  node20: 'node:20-alpine',
  python3: 'python:3.11-alpine',
  php83: 'php:8.3-cli-alpine',
  node: 'node:20-alpine',
  python: 'python:3.11-alpine',
  php: 'php:8.3-cli-alpine',
};
