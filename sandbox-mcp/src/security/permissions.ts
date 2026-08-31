/**
 * PatchOps Sandbox Security Policy & Command Validation Guard
 *
 * Denies host system breakouts, privilege escalation, and destructive commands.
 */

const FORBIDDEN_COMMAND_PATTERNS: RegExp[] = [
  /\brm\s+-[a-zA-Z]*r[a-zA-Z]*f[a-zA-Z]*\s+[\/\~]/i, // rm -rf / or ~
  /\bmkfs\b/i,                                      // format disk
  /\bdd\s+if=/i,                                    // raw block writes
  /\b(shutdown|reboot|poweroff|init\s+0)\b/i,        // system power actions
  /\b(sudo|su|pkexec|doas)\b/i,                     // privilege escalation
  /\bchmod\s+([0-7]{3,4}\s+)?(\/|root)/i,          // root permissions alteration
  /\bchown\s+.*(\/|root)/i,
  /\/var\/run\/docker\.sock/i,                      // access host docker socket
  /\/proc\/sysrq-trigger/i,
  /\/dev\/(sd[a-z]|nvme|kmem|mem)/i,                // raw disk devices
  /:\(\)\s*\{\s*:\s*\|\s*:\s*&\s*\}\s*;\s*:/,       // classic fork bomb
];

export interface ValidationResult {
  allowed: boolean;
  reason?: string;
}

/**
 * Validate a requested execution command against the sandbox security policy.
 */
export function validateCommand(command: string): ValidationResult {
  const trimmed = command.trim();

  if (!trimmed) {
    return { allowed: false, reason: 'Command cannot be empty' };
  }

  for (const pattern of FORBIDDEN_COMMAND_PATTERNS) {
    if (pattern.test(trimmed)) {
      return {
        allowed: false,
        reason: `Command contains forbidden destructive or host-breakout pattern: ${pattern.toString()}`,
      };
    }
  }

  return { allowed: true };
}

/**
 * Sanitize and enforce workspace boundary path.
 */
export function sanitizePath(inputPath: string, basePath = '/app'): string {
  const normalized = inputPath.replace(/\\/g, '/');
  if (normalized.includes('..') || normalized.startsWith('/etc') || normalized.startsWith('/root')) {
    throw new Error(`Path traversal denied: [${inputPath}] is outside allowed workspace [${basePath}].`);
  }

  return normalized;
}
