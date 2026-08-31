# Multi-stage Docker build for PatchOps Sandbox MCP Server
FROM node:20-alpine AS builder

WORKDIR /app

COPY package.json tsconfig.json ./
RUN npm install

COPY src/ ./src/
RUN npm run build

# Production runtime image
FROM node:20-alpine AS runner

WORKDIR /app

# Install Docker CLI to communicate with host Docker daemon socket
RUN apk add --no-cache docker-cli git

COPY package.json ./
RUN npm install --omit=dev

COPY --from=builder /app/dist ./dist

ENV NODE_ENV=production
ENV DOCKER_HOST=unix:///var/run/docker.sock

# Connect over STDIO for JSON-RPC 2.0 MCP Protocol
ENTRYPOINT ["node", "dist/server.js"]
