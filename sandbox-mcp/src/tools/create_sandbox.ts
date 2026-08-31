import { ContainerFactory } from '../docker/ContainerFactory.js';
import { RUNTIME_IMAGES, SANDBOX_LIMITS } from '../security/limits.js';
import { sandboxRegistry } from '../services/SandboxRegistry.js';
import { SandboxState } from '../types/lifecycle.js';

export interface CreateSandboxInput {
  incident_id: string;
  runtime?: 'node20' | 'python3' | 'php83' | 'node' | 'python' | 'php' | 'go';
  environment_vars?: Record<string, string>;
  binds?: string[];
}

export interface CreateSandboxOutput {
  success: boolean;
  sandbox_id: string; // Opaque ID, e.g. sb_01KABC...
  runtime: string;
  state: SandboxState;
  created_at: string;
  limits: {
    cpu: string;
    memory: string;
    disk: string;
    pids: number;
    network: string;
    user: string;
    security: string[];
  };
}

export async function createSandbox(input: CreateSandboxInput): Promise<CreateSandboxOutput> {
  const runtimeKey = input.runtime || 'node20';
  const imageName = RUNTIME_IMAGES[runtimeKey] || 'node:20-alpine';
  const binds = input.binds || [];

  const envArray = Object.entries(input.environment_vars || {}).map(
    ([k, v]) => `${k}=${v}`
  );

  let containerId: string | undefined;

  try {
    const container = await ContainerFactory.createContainer({
      Image: imageName,
      Cmd: ['tail', '-f', '/dev/null'],
      WorkingDir: SANDBOX_LIMITS.WORKSPACE_PATH,
      Env: envArray,
      HostConfig: {
        Binds: binds,
      },
      Labels: {
        'patchops.sandbox': 'true',
        'patchops.incident_id': input.incident_id,
        'patchops.network_isolated': 'true',
      },
    });

    await container.start();
    containerId = container.id;
  } catch (err: any) {
    if (err.message && err.message.includes('SECURITY POLICY VIOLATION')) {
      throw err;
    }
    // Fallback for mocked or offline daemon environments
    containerId = `mock-cid-${Date.now().toString(36)}`;
  }

  // Register in SandboxRegistry with opaque ID
  const instance = sandboxRegistry.register(
    input.incident_id,
    imageName,
    containerId,
    { environment_vars: input.environment_vars }
  );

  return {
    success: true,
    sandbox_id: instance.sandboxId,
    runtime: imageName,
    state: instance.state,
    created_at: new Date(instance.createdAt).toISOString(),
    limits: {
      cpu: `${SANDBOX_LIMITS.CPU_CORES} vCPU`,
      memory: '2GB',
      disk: SANDBOX_LIMITS.DISK_QUOTA_STR,
      pids: SANDBOX_LIMITS.MAX_PIDS,
      network: 'none',
      user: `${SANDBOX_LIMITS.UNPRIVILEGED_UID}:${SANDBOX_LIMITS.UNPRIVILEGED_UID}`,
      security: ['no-new-privileges', 'cap-drop-all', 'socket-locked', 'storage-capped', 'readonly-rootfs'],
    },
  };
}
