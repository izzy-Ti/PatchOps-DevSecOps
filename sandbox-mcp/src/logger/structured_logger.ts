export interface TraceContext {
  correlationId: string;
  incidentId: string;
  agentRunId?: string;
  sandboxId?: string;
  agentRole?: string;
}

export class StructuredLogger {
  public static log(level: 'INFO' | 'WARN' | 'ERROR' | 'DEBUG', message: string, trace?: Partial<TraceContext>, extra: Record<string, any> = {}): void {
    const payload = {
      timestamp: new Date().toISOString(),
      level,
      message,
      correlation_id: trace?.correlationId || 'corr_unknown',
      incident_id: trace?.incidentId || 'INC_UNKNOWN',
      agent_run_id: trace?.agentRunId || 'RUN_UNKNOWN',
      sandbox_id: trace?.sandboxId || 'SB_UNKNOWN',
      agent_role: trace?.agentRole || 'unknown',
      ...extra,
    };

    console.log(JSON.stringify(payload));
  }

  public static info(message: string, trace?: Partial<TraceContext>, extra?: Record<string, any>): void {
    this.log('INFO', message, trace, extra);
  }

  public static warn(message: string, trace?: Partial<TraceContext>, extra?: Record<string, any>): void {
    this.log('WARN', message, trace, extra);
  }

  public static error(message: string, trace?: Partial<TraceContext>, extra?: Record<string, any>): void {
    this.log('ERROR', message, trace, extra);
  }
}
