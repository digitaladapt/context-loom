# Context Loom

Declarative MCP + OpenAPI tool server. Context Loom is the loom TaskWeaver
weaves on: registry-driven tools, served once over MCP Streamable HTTP and
REST/OpenAPI.

> **Status: Phase 0 skeleton.** This is the foundation commit — the Symfony
> kernel, the `mcp/sdk` 0.8.x server over Streamable HTTP, the
> `contextloom_health` tool, API-key auth, `/health`, and the three CLI
> commands (serve / validate / probe). Registry, providers, streaming, and
> packaging land in later phases per `SPEC.md` §10/§11.

## Quickstart (dev)

```bash
composer install
cp .env.example .env          # set CONTEXT_LOOM_API_KEY
php bin/console contextloom:serve --port 8080
```

Then:

```bash
# REST health (unauthenticated — the single health surface)
curl http://127.0.0.1:8080/health

# MCP: initialize -> session -> tools/list -> tools/call
curl -X POST http://127.0.0.1:8080/mcp \
  -H 'Content-Type: application/json' \
  -H 'Authorization: Bearer <CONTEXT_LOOM_API_KEY>' \
  -H 'MCP-Protocol-Version: 2025-06-18' \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"curl","version":"0.1"}}}'
```

## CLI

| Command | Purpose |
|---|---|
| `contextloom:serve` | Run the HTTP server (`/mcp` + `/health` on one port) |
| `contextloom:validate` | Validate registry config (stub in Phase 0) |
| `contextloom:probe` | Probe backend connectivity (stub in Phase 0) |

## Tests

```bash
vendor/bin/phpunit
```

## Notes

- **Transport:** `mcp/sdk` Streamable HTTP only (POST `/mcp`; the legacy
  two-endpoint HTTP+SSE pair was deprecated 2025-03-26 — we never implement
  it). STDIO is a dev convenience, not a v1 goal.
- **Sessions:** file-based (`var/mcp-sessions`) so per-request PHP workers
  survive the handshake; modern-era (2026-07-28) clients use the stateless
  dispatcher.
- See `SPEC.md` for the full architecture and the §11 `v0.1.0` release gate.
