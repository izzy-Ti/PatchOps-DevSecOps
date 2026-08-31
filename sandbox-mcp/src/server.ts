import { Server } from '@modelcontextprotocol/sdk/server/index.js';
import { StdioServerTransport } from '@modelcontextprotocol/sdk/server/stdio.js';
import {
  CallToolRequestSchema,
  ListToolsRequestSchema,
} from '@modelcontextprotocol/sdk/types.js';

import { createSandbox } from './tools/create_sandbox.js';
import { cloneRepository } from './tools/clone_repository.js';
import { installDependencies } from './tools/install_dependencies.js';
import { executeCommand } from './tools/execute_command.js';
import { collectLogs } from './tools/collect_logs.js';
import { destroySandbox } from './tools/destroy_sandbox.js';

/**
 * PatchOps Sandbox MCP Server
 *
 * Exposes isolated, disposable Docker container lifecycle operations to LLM agents
 * over the standard Model Context Protocol (MCP) JSON-RPC 2.0 transport.
 */
const server = new Server(
  {
    name: 'patchops-sandbox-mcp',
    version: '1.0.0',
  },
  {
    capabilities: {
      tools: {},
    },
  }
);

// 1. Register Available Sandbox Tools List
server.setRequestHandler(ListToolsRequestSchema, async () => {
  return {
    tools: [
      {
        name: 'sandbox.create_sandbox',
        description: 'Provision a hardened, disposable containerized sandbox environment with strict cgroup limits and network isolation.',
        inputSchema: {
          type: 'object',
          properties: {
            incident_id: {
              type: 'string',
              description: 'Target incident ID for container metadata binding.',
            },
            runtime: {
              type: 'string',
              enum: ['node20', 'python3', 'php83', 'node', 'python', 'php'],
              description: 'Base runtime image ecosystem.',
              default: 'node20',
            },
            environment_vars: {
              type: 'object',
              description: 'Non-sensitive key-value pairs to inject as environment variables.',
            },
          },
          required: ['incident_id'],
        },
      },
      {
        name: 'sandbox.clone_repository',
        description: 'Mount or clone target repository snapshot into the isolated container workspace (/app).',
        inputSchema: {
          type: 'object',
          properties: {
            sandbox_id: {
              type: 'string',
              description: 'Target sandbox container ID.',
            },
            repository_url: {
              type: 'string',
              description: 'Target repository git URL or identifier.',
            },
            ref: {
              type: 'string',
              description: 'Branch, commit SHA, or tag to checkout.',
              default: 'main',
            },
          },
          required: ['sandbox_id', 'repository_url'],
        },
      },
      {
        name: 'sandbox.install_dependencies',
        description: 'Execute bounded package dependency installation inside the container workspace.',
        inputSchema: {
          type: 'object',
          properties: {
            sandbox_id: {
              type: 'string',
              description: 'Target sandbox container ID.',
            },
            package_manager: {
              type: 'string',
              enum: ['npm', 'composer', 'pip', 'yarn', 'pnpm'],
              description: 'Ecosystem package manager to invoke.',
            },
            flags: {
              type: 'array',
              items: { type: 'string' },
              description: 'Optional CLI flags for dependency installation.',
            },
          },
          required: ['sandbox_id', 'package_manager'],
        },
      },
      {
        name: 'sandbox.execute_command',
        description: 'Run reproduction script or test suite inside the container workspace with timeout and security boundaries.',
        inputSchema: {
          type: 'object',
          properties: {
            sandbox_id: {
              type: 'string',
              description: 'Target sandbox container ID.',
            },
            command: {
              type: 'string',
              description: 'Shell command or test runner execution string.',
            },
            timeout_seconds: {
              type: 'number',
              description: 'Execution timeout ceiling in seconds (max 600s).',
              default: 180,
            },
            working_dir: {
              type: 'string',
              description: 'Working directory path (defaults to /app).',
            },
          },
          required: ['sandbox_id', 'command'],
        },
      },
      {
        name: 'sandbox.collect_logs',
        description: 'Retrieve aggregated output logs and performance metrics from the container workspace.',
        inputSchema: {
          type: 'object',
          properties: {
            sandbox_id: {
              type: 'string',
              description: 'Target sandbox container ID.',
            },
            tail_lines: {
              type: 'number',
              description: 'Number of tail log lines to retrieve.',
              default: 100,
            },
          },
          required: ['sandbox_id'],
        },
      },
      {
        name: 'sandbox.destroy_sandbox',
        description: 'Immediately terminate and prune disposable container processes and workspace volumes.',
        inputSchema: {
          type: 'object',
          properties: {
            sandbox_id: {
              type: 'string',
              description: 'Target sandbox container ID to terminate and delete.',
            },
          },
          required: ['sandbox_id'],
        },
      },
    ],
  };
});

// 2. Dispatch Tool Invocation Requests
server.setRequestHandler(CallToolRequestSchema, async (request: any) => {
  const { name, arguments: args = {} } = request.params;

  try {
    let result: any;

    switch (name) {
      case 'sandbox.create_sandbox':
      case 'sandbox.create_environment':
        result = await createSandbox(args as any);
        break;

      case 'sandbox.clone_repository':
        result = await cloneRepository(args as any);
        break;

      case 'sandbox.install_dependencies':
        result = await installDependencies(args as any);
        break;

      case 'sandbox.execute_command':
      case 'sandbox.execute':
        result = await executeCommand(args as any);
        break;

      case 'sandbox.collect_logs':
      case 'sandbox.read_output':
        result = await collectLogs(args as any);
        break;

      case 'sandbox.destroy_sandbox':
      case 'sandbox.destroy_environment':
        result = await destroySandbox(args as any);
        break;

      default:
        throw new Error(`Unknown sandbox tool method: ${name}`);
    }

    return {
      content: [
        {
          type: 'text',
          text: JSON.stringify(result, null, 2),
        },
      ],
    };
  } catch (error: any) {
    return {
      content: [
        {
          type: 'text',
          text: JSON.stringify({
            success: false,
            error: error.message || 'Sandbox execution error',
          }),
        },
      ],
      isError: true,
    };
  }
});

// 3. Connect to STDIO transport
async function main(): Promise<void> {
  const transport = new StdioServerTransport();
  await server.connect(transport);
  console.error('[PatchOps] Sandbox MCP Server initialized over STDIO transport.');
}

main().catch((err: unknown) => {
  console.error('[PatchOps] Fatal error starting Sandbox MCP Server:', err);
  process.exit(1);
});
