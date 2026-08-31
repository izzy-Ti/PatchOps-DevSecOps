/**
 * PatchOps Sandbox Network Isolation Policy Controller
 *
 * Enforces phase-based network egress boundaries to prevent SSRF, data exfiltration,
 * DNS tunneling, and internal network pivots during untrusted code execution.
 */

export type SandboxLifecyclePhase =
  | 'CREATE'
  | 'INITIALIZE'
  | 'CLONE'
  | 'INSTALL'
  | 'EXECUTE'
  | 'COLLECT'
  | 'DESTROY';

export interface PhaseNetworkPolicy {
  phase: SandboxLifecyclePhase;
  networkMode: 'none' | 'bridge';
  networkDisabled: boolean;
  allowedDomains: string[];
  rationale: string;
}

export const PHASE_NETWORK_POLICIES: Record<SandboxLifecyclePhase, PhaseNetworkPolicy> = {
  CREATE: {
    phase: 'CREATE',
    networkMode: 'none',
    networkDisabled: true,
    allowedDomains: [],
    rationale: 'Initial container allocation is completely air-gapped',
  },
  INITIALIZE: {
    phase: 'INITIALIZE',
    networkMode: 'none',
    networkDisabled: true,
    allowedDomains: [],
    rationale: 'Workspace configuration runs without network exposure',
  },
  CLONE: {
    phase: 'CLONE',
    networkMode: 'none',
    networkDisabled: true,
    allowedDomains: ['github.com', 'api.github.com'],
    rationale: 'Git snapshot copy managed by gateway host; target container never touches raw tokens',
  },
  INSTALL: {
    phase: 'INSTALL',
    networkMode: 'none', // Default to none unless explicitly configured with restricted outbound package proxy
    networkDisabled: true,
    allowedDomains: ['registry.npmjs.org', 'packagist.org', 'pypi.org', 'proxy.golang.org'],
    rationale: 'Temporary package resolution phase under strict timeout ceilings',
  },
  EXECUTE: {
    phase: 'EXECUTE',
    networkMode: 'none',
    networkDisabled: true,
    allowedDomains: [],
    rationale: 'Untrusted reproduction and test execution is strictly air-gapped (--network=none)',
  },
  COLLECT: {
    phase: 'COLLECT',
    networkMode: 'none',
    networkDisabled: true,
    allowedDomains: [],
    rationale: 'Log extraction runs locally inside container buffer',
  },
  DESTROY: {
    phase: 'DESTROY',
    networkMode: 'none',
    networkDisabled: true,
    allowedDomains: [],
    rationale: 'Teardown and purge operations require zero network',
  },
};

/**
 * Resolve the mandatory network mode for a given sandbox lifecycle phase.
 */
export function getPhaseNetworkMode(phase: SandboxLifecyclePhase): 'none' | 'bridge' {
  return PHASE_NETWORK_POLICIES[phase]?.networkMode || 'none';
}

/**
 * Assert that execution phase is strictly air-gapped.
 */
export function assertExecutionAirGap(phase: SandboxLifecyclePhase, networkMode?: string): void {
  if (phase === 'EXECUTE' && networkMode && networkMode !== 'none') {
    throw new Error(
      `Security Violation: Execution phase must run under air-gapped '--network=none'. Detected unauthorized mode: [${networkMode}].`
    );
  }
}
