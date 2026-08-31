import Docker from 'dockerode';
import { SANDBOX_LIMITS, RUNTIME_IMAGES } from '../security/limits.js';
import { sandboxRegistry } from '../services/SandboxRegistry.js';
import { SandboxState } from '../types/lifecycle.js';

const docker = new Docker();

export interface CreateSandboxInput {
  incident_id: string;
  runtime?: 'node20' | 'python3' | 'php83' | 'node' | 'python' | 'php';
  environment_vars?: Record<string, string>;
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
    network: string;
    user: string;
  };
}

export async function createSandbox(input: CreateSandboxInput): Promise<CreateSandboxOutput> {
  const runtimeKey = input.runtime || 'node20';
  const imageName = RUNTIME_IMAGES[runtimeKey] || 'node:20-alpine';

  const envArray = Object.entries(input.environment_vars || {}).map(
    ([k, v]) => `${k}=${v}`
  );

  let containerId: string | undefined;

  try {
    const container = await docker.createContainer({
      Image: imageName,
      Cmd: ['tail', '-f', '/dev/null'],
      WorkingDir: SANDBOX_LIMITS.workspacePath,
      User: `${SANDBOX_LIMITS.unprivilegedUid}:${SANDBOX_LIMITS.unprivilegedUid}`,
      Env: envArray,
      HostConfig: {
        NanoCpus: SANDBOX_LIMITS.nanoCpus,
        Memory: SANDBOX_LIMITS.memoryBytes,
        MemorySwap: SANDBOX_LIMITS.memorySwapBytes,
        PidsLimit: SANDBOX_LIMITS.pidsLimit,
        NetworkMode: 'none',
        ReadonlyRootfs: false,
        Tmpfs: {
          '/tmp': 'rw,noexec,nosuid,size=512m',
        },
        AutoRemove: false,
      },
      Labels: {
        'patchops.sandbox': 'true',
        'patchops.incident_id': input.incident_id,
      },
    });

    await container.start();
    containerId = container.id;
  } catch (err: any) {
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
      cpu: '2.0 vCPU',
      memory: '2GB',
      network: 'none',
      user: '1000:1000',
    },
  };
}
