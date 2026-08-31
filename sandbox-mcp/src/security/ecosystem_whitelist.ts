/**
 * PatchOps Sandbox Ecosystem Whitelist & Manifest Matrix
 *
 * Enforces strict, immutable, deterministic package manager commands.
 * Disables script execution hooks (--ignore-scripts, --no-scripts) to prevent
 * arbitrary code execution during the install phase.
 */

export interface EcosystemRule {
  ecosystem: 'node' | 'php' | 'python' | 'go';
  manifest: string;
  command: string[];
  timeoutSeconds: number;
  description: string;
}

export const ECOSYSTEM_RULES: EcosystemRule[] = [
  {
    ecosystem: 'node',
    manifest: 'package-lock.json',
    command: ['npm', 'ci', '--ignore-scripts'],
    timeoutSeconds: 300,
    description: 'Clean deterministic Node.js installation via lockfile without script hooks',
  },
  {
    ecosystem: 'node',
    manifest: 'package.json',
    command: ['npm', 'install', '--ignore-scripts', '--no-audit'],
    timeoutSeconds: 300,
    description: 'Standard Node.js installation without script hooks',
  },
  {
    ecosystem: 'php',
    manifest: 'composer.lock',
    command: ['composer', 'install', '--no-interaction', '--no-scripts'],
    timeoutSeconds: 300,
    description: 'Deterministic PHP Composer installation via lockfile without script hooks',
  },
  {
    ecosystem: 'php',
    manifest: 'composer.json',
    command: ['composer', 'install', '--no-interaction', '--no-scripts', '--no-dev'],
    timeoutSeconds: 300,
    description: 'Production PHP Composer installation without script hooks',
  },
  {
    ecosystem: 'python',
    manifest: 'requirements.txt',
    command: ['pip', 'install', '--no-cache-dir', '-r', 'requirements.txt'],
    timeoutSeconds: 300,
    description: 'Python dependency installation via pip without cache',
  },
  {
    ecosystem: 'python',
    manifest: 'pyproject.toml',
    command: ['pip', 'install', '--no-cache-dir', '.'],
    timeoutSeconds: 300,
    description: 'Python dependency installation via pyproject.toml',
  },
  {
    ecosystem: 'go',
    manifest: 'go.mod',
    command: ['go', 'mod', 'download'],
    timeoutSeconds: 180,
    description: 'Go module download',
  },
];

export interface DetectedEcosystem {
  ecosystem: 'node' | 'php' | 'python' | 'go';
  manifest: string;
  command: string[];
  commandString: string;
  timeoutSeconds: number;
}

/**
 * Match a detected manifest filename against the pre-approved whitelist.
 */
export function matchEcosystemRule(manifestFile: string): DetectedEcosystem | null {
  const normalized = manifestFile.trim();
  const rule = ECOSYSTEM_RULES.find((r) => r.manifest.toLowerCase() === normalized.toLowerCase());

  if (!rule) {
    return null;
  }

  return {
    ecosystem: rule.ecosystem,
    manifest: rule.manifest,
    command: rule.command,
    commandString: rule.command.join(' '),
    timeoutSeconds: rule.timeoutSeconds,
  };
}
