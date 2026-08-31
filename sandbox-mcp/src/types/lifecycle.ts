/**
 * PatchOps Sandbox Lifecycle State Machine Definitions
 *
 * Deterministic sequential lifecycle states and transition constraints for
 * disposable containerized execution.
 */

export enum SandboxState {
  CREATING = 'CREATING',
  INITIALIZED = 'INITIALIZED',
  CLONING = 'CLONING',
  CLONED = 'CLONED',
  INSTALLING = 'INSTALLING',
  READY = 'READY',
  EXECUTING = 'EXECUTING',
  COLLECTING = 'COLLECTING',
  DESTROYING = 'DESTROYING',
  DESTROYED = 'DESTROYED',
  FAILED = 'FAILED',
}

export interface SandboxInstance {
  sandboxId: string;       // Opaque identifier: sb_01KABC...
  incidentId: string;      // Incident binding: INC-XXXXX
  runtime: string;         // node20 | python3 | php83
  state: SandboxState;
  containerId?: string;    // Internal host Docker container reference (never leaked to agent)
  createdAt: number;       // Unix epoch ms
  lastActivityAt: number;  // Heartbeat tracker for orphan sweeper
  metadata?: Record<string, any>;
}

/**
 * Valid state transition matrix
 */
export const ALLOWED_TRANSITIONS: Record<SandboxState, SandboxState[]> = {
  [SandboxState.CREATING]: [SandboxState.INITIALIZED, SandboxState.FAILED, SandboxState.DESTROYING],
  [SandboxState.INITIALIZED]: [SandboxState.CLONING, SandboxState.CLONED, SandboxState.INSTALLING, SandboxState.READY, SandboxState.EXECUTING, SandboxState.COLLECTING, SandboxState.DESTROYING, SandboxState.FAILED],
  [SandboxState.CLONING]: [SandboxState.CLONED, SandboxState.FAILED, SandboxState.DESTROYING],
  [SandboxState.CLONED]: [SandboxState.INSTALLING, SandboxState.READY, SandboxState.EXECUTING, SandboxState.COLLECTING, SandboxState.DESTROYING, SandboxState.FAILED],
  [SandboxState.INSTALLING]: [SandboxState.READY, SandboxState.FAILED, SandboxState.DESTROYING],
  [SandboxState.READY]: [SandboxState.EXECUTING, SandboxState.COLLECTING, SandboxState.INSTALLING, SandboxState.DESTROYING, SandboxState.FAILED],
  [SandboxState.EXECUTING]: [SandboxState.READY, SandboxState.COLLECTING, SandboxState.FAILED, SandboxState.DESTROYING],
  [SandboxState.COLLECTING]: [SandboxState.READY, SandboxState.DESTROYING, SandboxState.FAILED],
  [SandboxState.FAILED]: [SandboxState.COLLECTING, SandboxState.DESTROYING],
  [SandboxState.DESTROYING]: [SandboxState.DESTROYED],
  [SandboxState.DESTROYED]: [],
};

/**
 * Verify if transition from currentState to targetState is permitted.
 */
export function canTransition(current: SandboxState, target: SandboxState): boolean {
  if (current === target) {
    return true;
  }
  const allowed = ALLOWED_TRANSITIONS[current] || [];
  return allowed.includes(target);
}

/**
 * Assert state transition or throw a typed error.
 */
export function assertTransition(instance: SandboxInstance, targetState: SandboxState): void {
  if (!canTransition(instance.state, targetState)) {
    throw new Error(
      `Invalid Sandbox State Transition: Cannot move sandbox [${instance.sandboxId}] from [${instance.state}] to [${targetState}].`
    );
  }
}
