import { sandboxRegistry } from '../services/SandboxRegistry.js';
import { SandboxState } from '../types/lifecycle.js';

export interface DestroySandboxInput {
  sandbox_id: string;
  reason?: string;
}

export interface DestroySandboxOutput {
  success: boolean;
  sandbox_id: string;
  state: SandboxState;
  destroyed_at: string;
  message: string;
}

export async function destroySandbox(input: DestroySandboxInput): Promise<DestroySandboxOutput> {
  const reason = input.reason || 'client_requested';

  await sandboxRegistry.destroy(input.sandbox_id, reason);

  return {
    success: true,
    sandbox_id: input.sandbox_id,
    state: SandboxState.DESTROYED,
    destroyed_at: new Date().toISOString(),
    message: `Sandbox container [${input.sandbox_id}] terminated and pruned.`,
  };
}
