import { StructuredLogger, TraceContext } from '../logger/structured_logger.js';

export interface JSONRPCRequest {
  jsonrpc: string;
  method: string;
  params?: {
    name?: string;
    arguments?: Record<string, any>;
    _meta?: {
      correlation_id?: string;
      incident_id?: string;
      agent_run_id?: string;
      sandbox_id?: string;
      agent_role?: string;
    };
  };
  id?: number | string;
}

export class TraceMiddleware {
  /**
   * Extract and normalize hierarchical trace context from incoming MCP JSON-RPC requests.
   */
  public static extractTraceContext(req: JSONRPCRequest): TraceContext {
    const meta = req.params?._meta || {};
    const args = req.params?.arguments || {};

    const trace: TraceContext = {
      correlationId: meta.correlation_id || `corr_${Date.now().toString(36)}`,
      incidentId: meta.incident_id || 'INC_UNKNOWN',
      agentRunId: meta.agent_run_id || 'RUN_UNKNOWN',
      sandboxId: meta.sandbox_id || args.sandbox_id || args.workspace_id || 'SB_UNKNOWN',
      agentRole: meta.agent_role || 'unknown',
    };

    StructuredLogger.info(`Invoking tool [${req.params?.name || req.method}]`, trace, {
      tool_name: req.params?.name,
      method: req.method,
    });

    return trace;
  }
}
