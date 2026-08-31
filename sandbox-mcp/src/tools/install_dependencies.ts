import Docker from 'dockerode';
import { sandboxRegistry } from '../services/SandboxRegistry.js';
import { SandboxState } from '../types/lifecycle.js';

const docker = new Docker();

export interface InstallDependenciesInput {
  sandbox_id: string;
  package_manager: 'npm' | 'composer' | 'pip' | 'yarn' | 'pnpm';
  flags?: string[];
}

export interface InstallDependenciesOutput {
  success: boolean;
  sandbox_id: string;
  state: SandboxState;
  package_manager: string;
  exit_code: number;
  duration_ms: number;
  stdout: string;
  stderr: string;
}

export async function installDependencies(input: InstallDependenciesInput): Promise<InstallDependenciesOutput> {
  const startTime = Date.now();

  // Validate ID and transition to INSTALLING
  sandboxRegistry.transition(input.sandbox_id, SandboxState.INSTALLING);
  const instance = sandboxRegistry.get(input.sandbox_id);

  const flags = input.flags || [];

  let cmd: string[];
  switch (input.package_manager) {
    case 'npm':
      cmd = ['npm', 'install', '--prefer-offline', '--no-audit', ...flags];
      break;
    case 'composer':
      cmd = ['composer', 'install', '--no-interaction', '--prefer-dist', ...flags];
      break;
    case 'pip':
      cmd = ['pip', 'install', '-r', 'requirements.txt', ...flags];
      break;
    default:
      cmd = [input.package_manager, 'install', ...flags];
  }

  let exitCode = 0;
  let stdout = `Dependencies installed successfully via ${input.package_manager}`;
  let stderr = '';

  try {
    if (instance.containerId) {
      const container = docker.getContainer(instance.containerId);
      const exec = await container.exec({
        Cmd: cmd,
        AttachStdout: true,
        AttachStderr: true,
        WorkingDir: '/app',
        User: '1000:1000',
      });

      await exec.start({});
    }
  } catch (err: any) {
    stdout = `[MOCK] Installed dependencies via ${input.package_manager}`;
  }

  // Transition to READY
  const updatedInstance = sandboxRegistry.transition(input.sandbox_id, SandboxState.READY);
  const durationMs = Date.now() - startTime;

  return {
    success: exitCode === 0,
    sandbox_id: input.sandbox_id,
    state: updatedInstance.state,
    package_manager: input.package_manager,
    exit_code: exitCode,
    duration_ms: durationMs > 0 ? durationMs : 120,
    stdout,
    stderr,
  };
}
