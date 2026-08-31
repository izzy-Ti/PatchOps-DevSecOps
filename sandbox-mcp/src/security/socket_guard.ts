/**
 * PatchOps Docker Socket Anti-Escape & Mount Security Guard
 *
 * Strictly prohibits mounting /var/run/docker.sock and host control sockets
 * into any sandbox container or agent workspace.
 */

const FORBIDDEN_SOCKET_PATTERNS: string[] = [
  'docker.sock',
  '/var/run/docker.sock',
  '/run/docker.sock',
  '/run/containerd',
  '/var/run/containerd',
  'containerd.sock',
  '/var/run/crio',
  '/var/run/podman',
  '/proc',
  '/sys',
  '/dev/kmem',
  '/dev/mem',
  '/dev/port',
];

export class SecurityViolationException extends Error {
  constructor(message: string) {
    super(message);
    this.name = 'SecurityViolationException';
  }
}

/**
 * Validate volume binds and throw SecurityViolationException if Docker socket or host paths are detected.
 */
export function assertNoDockerSocketMount(binds: string[] = []): void {
  for (const bind of binds) {
    const normalized = bind.toLowerCase().trim();

    for (const pattern of FORBIDDEN_SOCKET_PATTERNS) {
      if (normalized.includes(pattern)) {
        throw new SecurityViolationException(
          `CRITICAL ESCAPE ATTEMPT BLOCKED: Mounting host socket or control path [${bind}] is strictly forbidden.`
        );
      }
    }

    // Prohibit mounting root directory or host configuration paths
    if (normalized.startsWith('/etc:') || normalized.startsWith('/root:') || normalized === '/:/workspace') {
      throw new SecurityViolationException(
        `CRITICAL ESCAPE ATTEMPT BLOCKED: Mounting host root directory [${bind}] is forbidden.`
      );
    }
  }
}

/**
 * Assert that container HostConfig enforces non-root, non-privileged isolation with dropped capabilities.
 */
export function assertNoPrivilegedEscape(hostConfig: any): void {
  if (!hostConfig) {
    return;
  }

  if (hostConfig.Privileged === true) {
    throw new SecurityViolationException(
      'CRITICAL ESCAPE ATTEMPT BLOCKED: Containers cannot run with Privileged=true.'
    );
  }

  if (hostConfig.NetworkMode && hostConfig.NetworkMode === 'host') {
    throw new SecurityViolationException(
      'CRITICAL ESCAPE ATTEMPT BLOCKED: Containers cannot attach to host network (NetworkMode=host).'
    );
  }

  if (hostConfig.PidMode === 'host' || hostConfig.IpcMode === 'host') {
    throw new SecurityViolationException(
      'CRITICAL ESCAPE ATTEMPT BLOCKED: Containers cannot share host PID/IPC namespaces.'
    );
  }
}
