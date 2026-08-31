export enum SandboxErrorCategory {
  INFRA_TRANSIENT = 'INFRA_TRANSIENT',
  INFRA_EXHAUSTED = 'INFRA_EXHAUSTED',
  SECURITY_REPRODUCED = 'SECURITY_REPRODUCED',
  SECURITY_NOT_REPRODUCED = 'SECURITY_NOT_REPRODUCED',
  USER_ERROR = 'USER_ERROR',
}

export class ErrorClassifier {
  private static readonly TRANSIENT_ERROR_PATTERNS = [
    /ETIMEDOUT/i,
    /ECONNRESET/i,
    /ECONNREFUSED/i,
    /socket hang up/i,
    /network timeout/i,
    /docker daemon/i,
    /503 Service Unavailable/i,
    /502 Bad Gateway/i,
    /504 Gateway Timeout/i,
    /rate limited/i,
  ];

  /**
   * Determine whether an error is a transient infrastructure issue suitable for automated retry.
   */
  public static isTransientInfraError(error: Error | string | any): boolean {
    if (!error) return false;
    const msg = typeof error === 'string' ? error : (error.message || '');

    return this.TRANSIENT_ERROR_PATTERNS.some((pattern) => pattern.test(msg));
  }

  /**
   * Classify execution outcome based on exit code, streams, timeouts, and system errors.
   */
  public static classifyExecution(
    exitCode: number,
    stdout: string = '',
    stderr: string = '',
    timedOut: boolean = false,
    isInfraFailure: boolean = false
  ): SandboxErrorCategory {
    if (isInfraFailure) {
      return this.isTransientInfraError(stderr)
        ? SandboxErrorCategory.INFRA_TRANSIENT
        : SandboxErrorCategory.INFRA_EXHAUSTED;
    }

    if (timedOut) {
      return SandboxErrorCategory.INFRA_EXHAUSTED;
    }

    const combinedOutput = `${stdout}\n${stderr}`;
    const exploitIndicators = [
      /VULNERABILITY_CONFIRMED/i,
      /EXPLOIT_SUCCESS/i,
      /VULNERABILITY DETECTED/i,
      /exploit verified/i,
    ];

    if (exploitIndicators.some((regex) => regex.test(combinedOutput))) {
      return SandboxErrorCategory.SECURITY_REPRODUCED;
    }

    if (exitCode === 0 || exitCode === 1) {
      return SandboxErrorCategory.SECURITY_NOT_REPRODUCED;
    }

    if (exitCode === 137) { // OOM Killer
      return SandboxErrorCategory.INFRA_EXHAUSTED;
    }

    return SandboxErrorCategory.USER_ERROR;
  }
}
