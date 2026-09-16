# Context Loom

A self-hosted MCP (Model Context Protocol) server that exposes everyday backend systems as model-native tools. Designed so that long-running or chatty work can stream back to the model instead of blocking.

## Quickstart

```bash
# Clone
git clone https://github.com/digitaladapt/context-loom.git
cd context-loom

# Configure
cp .env.example .env
# Edit .env → set CONTEXT_LOOM_API_KEY, NTFY_URL, etc.

# Dev server
docker compose up --build

# Or locally:
composer install
php bin/console contextloom:serve
```

MCP endpoint: `POST http://localhost:8080/mcp`  
Health endpoint: `GET http://localhost:8080/health`  
API auth: `Authorization: Bearer <CONTEXT_LOOM_API_KEY>`

## Docker

```bash
docker buildx build -t digitaladapt/context-loom:latest \
  --platform linux/amd64,linux/arm64 \
  --build-arg APP_VERSION=v0.1.0 \
  -f Dockerfile .
docker push digitaladapt/context-loom:latest
```

## Registry

Tools are declared as YAML files in `registry/`. Adding a tool = adding a file. No code changes needed.

Example (`registry/notify_send.yaml`):

```yaml
name: notify_send
title: Send notification
description: Send notifications via ntfy + Discord.
domain: notify
type: internal
```

## Available tools (v0.1)

| Tool | Domain | Type |
|------|--------|------|
| `contextloom_health` | Server | Built-in |
| `notify_send` | Notify | Internal |
| `vital_pulse_list_records` | Health | HTTP |
| `penny_list_transactions` | Finance | HTTP |

## CLI

```bash
php bin/console contextloom:validate     # Static validation
php bin/console contextloom:probe        # Run connectivity probes
php bin/console contextloom:serve        # Run HTTP server
```

## Configuration

| Variable | Default | Description |
|----------|---------|-------------|
| `CONTEXT_LOOM_AUTH` | `apikey` | Auth mode: `apikey` or `oauth` (v2.0) |
| `CONTEXT_LOOM_API_KEY` | *required* | API key for auth |
| `CONTEXT_LOOM_ALLOWED_HOSTS` | localhost | Comma-separated hostnames for DNS-rebinding protection |
| `NTFY_URL` | `https://ntfy.sh` | ntfy server URL |
| `NTFY_TOPIC` | `general` | Default ntfy topic |
| `NTFY_TOKEN` | — | ntfy access token (required when the server disallows anonymous publishing) |
| `PROBE_INTERVAL` | `60` | Probe interval in seconds |
| `PROBE_TIMEOUT` | `3` | Probe timeout in seconds |
| `PROBE_ON_BOOT` | `true` | Run probes on boot |

## Architecture

- **Registry-driven** — every tool is a YAML declaration
- **Provider abstraction** — protocols (HTTP, ntfy, Discord) implement common interfaces
- **Stream-native** — tools produce `ToolRun` streams, not blocking results
- **Probe-based availability** — soft connectivity checks, never block boot

## License

Apache-2.0 (follows `mcp/sdk` license)

## Status

v0.1.0 — MVP complete. See `spec-v0.1.md` for the release gate definition.
