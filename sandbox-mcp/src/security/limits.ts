/**
 * PatchOps Sandbox Resource & Hardware Limits
 *
 * Enforces strict cgroup quotas, storage boundaries, and execution ceilings.
 */

export interface SandboxResourceLimits {
  CPU_CORES: number;          // 2.0 Cores (--cpus="2.0")
  NanoCpus: number;           // 2.0 * 10^9 nanoCPUs
  MEMORY_BYTES: number;       // 2 GB RAM (--memory="2g")
  MEMORY_SWAP_BYTES: number;  // 2 GB Swap (no additional swap expansion)
  DISK_QUOTA_BYTES: number;   // 5 GB max rootfs quota
  DISK_QUOTA_STR: string;     // '5G' for Docker StorageOpt
  MAX_PIDS: number;           // 100 concurrent PIDs (--pids-limit=100)
  TMPFS_BYTES: number;        // 512 MB tmpfs mounted at /tmp
  DEFAULT_TIMEOUT_SEC: number;// 180 seconds default execution timeout
  MAX_TIMEOUT_SECONDS: number;// 600 seconds (10 min wall-clock timer)
  UNPRIVILEGED_UID: number;   // 1000:1000
  WORKSPACE_PATH: string;     // /app
}

export const SANDBOX_LIMITS: SandboxResourceLimits = {
  CPU_CORES: 2.0,
  NanoCpus: 2.0 * 1e9,
  MEMORY_BYTES: 2 * 1024 * 1024 * 1024,
  MEMORY_SWAP_BYTES: 2 * 1024 * 1024 * 1024,
  DISK_QUOTA_BYTES: 5 * 1024 * 1024 * 1024,
  DISK_QUOTA_STR: '5G',
  MAX_PIDS: 100,
  TMPFS_BYTES: 512 * 1024 * 1024,
  DEFAULT_TIMEOUT_SEC: 180,
  MAX_TIMEOUT_SECONDS: 600,
  UNPRIVILEGED_UID: 1000,
  WORKSPACE_PATH: '/app',
};

export const RUNTIME_IMAGES: Record<string, string> = {
  node20: 'node:20-alpine',
  python3: 'python:3.11-alpine',
  php83: 'php:8.3-cli-alpine',
  node: 'node:20-alpine',
  python: 'python:3.11-alpine',
  php: 'php:8.3-cli-alpine',
  go: 'golang:1.22-alpine',
};
