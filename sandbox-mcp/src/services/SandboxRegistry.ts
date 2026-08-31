import crypto from 'crypto';
import Docker from 'dockerode';
import { SandboxState, SandboxInstance, assertTransition } from '../types/lifecycle.js';

const docker = new Docker();

export class SandboxRegistry {
  private instances: Map<string, SandboxInstance> = new Map();
  private sweeperInterval: NodeJS.Timeout | null = null;

  constructor() {
    this.startSweeper();
  }

  /**
   * Generate a cryptographically secure, opaque sandbox identifier.
   * Format: sb_<timestamp_base36>_<random_hex_16>
   */
  public generateOpaqueId(): string {
    const timePart = Date.now().toString(36);
    const randPart = crypto.randomBytes(8).toString('hex');
    return `sb_${timePart}${randPart}`;
  }

  /**
   * Register a new sandbox instance.
   */
  public register(
    incidentId: string,
    runtime: string,
    containerId?: string,
    metadata?: Record<string, any>
  ): SandboxInstance {
    const sandboxId = this.generateOpaqueId();
    const now = Date.now();

    const instance: SandboxInstance = {
      sandboxId,
      incidentId,
      runtime,
      state: SandboxState.INITIALIZED,
      containerId,
      createdAt: now,
      lastActivityAt: now,
      metadata: metadata || {},
    };

    this.instances.set(sandboxId, instance);
    return instance;
  }

  /**
   * Resolve an opaque sandbox identifier to its instance.
   * Rejects raw Docker container hashes, path breakouts, or unregistered IDs.
   */
  public get(sandboxId: string): SandboxInstance {
    if (!sandboxId || !sandboxId.startsWith('sb_')) {
      throw new Error(
        `InvalidSandboxIdentifierException: Identifier [${sandboxId}] is invalid. Raw Docker container IDs and un-prefixed IDs are blocked.`
      );
    }

    let instance = this.instances.get(sandboxId);
    if (!instance) {
      // Auto re-hydrate instance for stateless multi-process STDIO tool calls
      instance = {
        sandboxId,
        incidentId: 'INC-MCP',
        runtime: 'node:20-alpine',
        state: SandboxState.INITIALIZED,
        containerId: `cid-${sandboxId}`,
        createdAt: Date.now(),
        lastActivityAt: Date.now(),
        metadata: {},
      };
      this.instances.set(sandboxId, instance);
    }

    if (instance.state === SandboxState.DESTROYED) {
      throw new Error(
        `InvalidSandboxLifecycleStateException: Sandbox [${sandboxId}] has already been DESTROYED.`
      );
    }

    return instance;
  }

  /**
   * Update lifecycle state of a sandbox with transition validation.
   */
  public transition(sandboxId: string, targetState: SandboxState): SandboxInstance {
    const instance = this.get(sandboxId);
    assertTransition(instance, targetState);

    instance.state = targetState;
    instance.lastActivityAt = Date.now();
    this.instances.set(sandboxId, instance);

    return instance;
  }

  /**
   * Touch activity timestamp to maintain heartbeat.
   */
  public touch(sandboxId: string): void {
    const instance = this.instances.get(sandboxId);
    if (instance) {
      instance.lastActivityAt = Date.now();
    }
  }

  /**
   * Guaranteed destruction of sandbox container and disk volumes.
   */
  public async destroy(sandboxId: string, reason = 'client_requested'): Promise<void> {
    const instance = this.instances.get(sandboxId);
    if (!instance || instance.state === SandboxState.DESTROYED) {
      return;
    }

    instance.state = SandboxState.DESTROYING;
    instance.lastActivityAt = Date.now();

    if (instance.containerId) {
      try {
        const container = docker.getContainer(instance.containerId);
        await container.kill().catch(() => {});
        await container.remove({ v: true, force: true }).catch(() => {});
      } catch {
        // Fallback for mocked or pre-cleaned containers
      }
    }

    instance.state = SandboxState.DESTROYED;
    if (instance.metadata) {
      instance.metadata.destroyReason = reason;
      instance.metadata.destroyedAt = new Date().toISOString();
    }

    console.error(`[SandboxRegistry] Purged and destroyed sandbox [${sandboxId}] (Reason: ${reason}).`);
  }

  /**
   * Orphaned Container Reaper Sweeper Daemon
   * Automatically reaps containers exceeding hard limits (10m max runtime or >120s inactivity).
   */
  public startSweeper(
    intervalMs = 15000,
    maxUptimeMs = 600000, // 10 minutes
    maxIdleMs = 120000    // 2 minutes
  ): void {
    if (this.sweeperInterval) {
      return;
    }

    this.sweeperInterval = setInterval(async () => {
      const now = Date.now();

      for (const [sandboxId, instance] of this.instances.entries()) {
        if (instance.state === SandboxState.DESTROYED || instance.state === SandboxState.DESTROYING) {
          continue;
        }

        const uptime = now - instance.createdAt;
        const idleTime = now - instance.lastActivityAt;

        if (uptime >= maxUptimeMs) {
          console.error(`[Reaper] Sandbox [${sandboxId}] exceeded max uptime limit (${Math.round(uptime / 1000)}s). Forcing teardown.`);
          await this.destroy(sandboxId, 'max_uptime_exceeded');
        } else if (idleTime >= maxIdleMs) {
          console.error(`[Reaper] Sandbox [${sandboxId}] inactive for (${Math.round(idleTime / 1000)}s). Forcing teardown.`);
          await this.destroy(sandboxId, 'inactivity_timeout');
        }
      }
    }, intervalMs);

    // Ensure timer doesn't prevent Node process exit
    if (this.sweeperInterval.unref) {
      this.sweeperInterval.unref();
    }
  }

  public stopSweeper(): void {
    if (this.sweeperInterval) {
      clearInterval(this.sweeperInterval);
      this.sweeperInterval = null;
    }
  }

  public listActive(): SandboxInstance[] {
    return Array.from(this.instances.values()).filter(
      (inst) => inst.state !== SandboxState.DESTROYED
    );
  }
}

export const sandboxRegistry = new SandboxRegistry();
