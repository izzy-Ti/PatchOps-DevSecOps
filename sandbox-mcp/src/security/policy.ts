import Docker from 'dockerode';

export class SandboxSecurityPolicy {
  public static apply(config: Docker.ContainerCreateOptions): Docker.ContainerCreateOptions {
    return {
      ...config,
      // Enforce non-root user
      User: '1000:1000',
      Tty: false,
      OpenStdin: false,
      NetworkDisabled: true, // Air-gapped network disablement
      HostConfig: {
        ...config.HostConfig,
        // Prohibit privilege escalation and drop all Linux capabilities
        Privileged: false,
        CapDrop: ['ALL'],
        SecurityOpt: ['no-new-privileges:true'],
        // Isolate namespaces
        NetworkMode: 'none',
        PidMode: 'container',
        IpcMode: 'private',
        // Read-only root filesystem with ephemeral memory scratchpad
        ReadonlyRootfs: true,
        Tmpfs: {
          '/tmp': 'rw,noexec,nosuid,size=512m',
          '/workspace/tmp': 'rw,size=256m',
        },
        // Enforce hard cgroup bounds
        NanoCpus: 2 * 1e9, // 2.0 Cores
        Memory: 2 * 1024 * 1024 * 1024, // 2 GB RAM
        MemorySwap: 2 * 1024 * 1024 * 1024, // Swap disabled
        PidsLimit: 100, // Max 100 processes
        StorageOpt: {
          size: '5G', // 5 GB disk quota
        },
      },
    };
  }

  public static assertSafeMounts(binds: string[] = []): void {
    const prohibitedPaths = [
      '/var/run/docker.sock',
      'docker.sock',
      '/etc',
      '/root',
      '/home',
      '/proc',
      '/sys',
    ];

    for (const bind of binds) {
      const lower = bind.toLowerCase();
      if (prohibitedPaths.some((p) => lower.includes(p))) {
        throw new Error(`SECURITY POLICY VIOLATION: Mount path "${bind}" is strictly denied.`);
      }
    }
  }
}
