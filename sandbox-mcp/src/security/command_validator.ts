/**
 * PatchOps Command Validation Gate & Whitelist Enforcer
 *
 * Prevents command injection, shell chaining, subshells, and unauthorized binary execution.
 */

import { SecurityViolationException } from './socket_guard.js';

export class CommandValidator {
  /**
   * Pre-approved test runner prefixes and script execution patterns
   */
  private static ALLOWED_PREFIXES: string[] = [
    'npm test',
    'npm run',
    'npx jest',
    'npx mocha',
    'node ',
    'composer test',
    './vendor/bin/phpunit',
    'vendor/bin/phpunit',
    'php ',
    'pytest',
    'python -m unittest',
    'python ',
    'python3 ',
    'go test',
  ];

  /**
   * Prohibited shell chaining operators, subshells, and forbidden binaries
   */
  private static FORBIDDEN_TOKENS: string[] = [
    '&&',
    '||',
    ';',
    '|',
    '>',
    '<',
    '`',
    '$(',
    '${',
    'sudo',
    'su ',
    'curl',
    'wget',
    'nc',
    'netcat',
    'chmod',
    'chown',
    'sh -c',
    'bash -c',
    '/bin/sh',
    '/bin/bash',
  ];

  /**
   * Validate command against the strict security whitelist.
   *
   * @throws SecurityViolationException
   */
  public static validate(command: string): void {
    const trimmed = (command || '').trim();

    if (!trimmed) {
      throw new SecurityViolationException('Command cannot be empty.');
    }

    // 1. Assert no forbidden shell operators, chaining, or dangerous binaries
    for (const token of this.FORBIDDEN_TOKENS) {
      if (trimmed.includes(token)) {
        throw new SecurityViolationException(
          `Command contains prohibited shell token or banned binary: "${token}"`
        );
      }
    }

    // 2. Assert command starts with an approved test runner prefix
    const isAllowed = this.ALLOWED_PREFIXES.some((prefix) =>
      trimmed === prefix.trim() || trimmed.startsWith(prefix)
    );

    if (!isAllowed) {
      throw new SecurityViolationException(
        `Command "${trimmed}" is not in the approved test execution whitelist. Only standard test runners (npm test, phpunit, pytest, go test, node, php, python) are permitted.`
      );
    }
  }
}
