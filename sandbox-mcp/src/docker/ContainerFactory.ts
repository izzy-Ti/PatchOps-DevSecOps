import Docker from 'dockerode';
import { SandboxSecurityPolicy } from '../security/policy.js';

const docker = new Docker();

export class ContainerFactory {
  /**
   * Provision a hardened, disposable Docker container adhering to the SandboxSecurityPolicy.
   */
  public static async createContainer(options: Docker.ContainerCreateOptions): Promise<Docker.Container> {
    // 1. Assert mount safety
    const binds = (options.HostConfig?.Binds as string[]) || [];
    SandboxSecurityPolicy.assertSafeMounts(binds);

    // 2. Apply immutable hardware limits, user isolation, and security options
    const securedOptions = SandboxSecurityPolicy.apply(options);

    // 3. Create container via Docker API
    return await docker.createContainer(securedOptions);
  }
}
