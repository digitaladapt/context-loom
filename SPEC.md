# Context Loom — Specification (Draft)

- **Status:** DRAFT v0.2 — for iteration, not implementation yet
- **Date:** 2026-09-12
- **Repo:** `context-loom` (public, self-hosted)
- **Stack:** PHP 8.4+, Symfony 8, `mcp/sdk` (official PHP MCP SDK, `mcp/sdk` ^0.8, Apache-2.0)
- **Predecessor:** `mcp-server` (Python/FastAPI) — domain knowledge carries over, code does not

---

## 1. What this is

**Context Loom is a self-hosted MCP server that exposes everyday backend systems
as model tools, designed so that long-running or chatty work can stream back to
the model instead of blocking.**

The name is the argument: TaskWeaver decides *what* to do; Context Loom is the
loom where the weaving actually happens. It supplies **context to the model**
(`context-loom` = the context layer, literally the C in MCP) and it is the
**pipe** through which tool output flows.

It is a **generic, provider-based** server. It does not own any domain (no
database, no business logic). It adapts protocols:

| Domain | Protocols (read) | Protocols (write) |
|---|---|---|
| Calendar | iCal (ICS), CalDAV | CalDAV |
| Email | IMAP | SMTP |
| Notifications | — | ntfy, Discord webhooks, (future: generic webhook, Slack) |
| Generic commands / our own services | shell scripts / binaries / HTTP | same |
| Registry entries are **native tools** — no `cmd_` prefix. `type: internal|process|http` all register as first-class tools (`vital_pulse_list_records`, not `cmd_vital-pulse-list-records`). |

### 1.1 Positioning (important)

This is a **public** project: public GitHub repo, public Docker Hub images
(`digitaladapt/context-loom`), exposed on the internet. Consequences:

- Zero secrets in the repo. All configuration via environment variables / `.env` (never committed).
- Every tool is opt-in per deployment — an unconfigured tool must simply not exist.
- Names and tool schemas are part of the public API surface; changes are semver-sensitive.
- It deliberately **does not re-implement domains that already have official MCP servers**
  (e.g. Gitea has its own official MCP server — out of scope). We cover the generics:
  calendar, email, notifications — the things that almost never have good MCP servers.

### 1.2 Non-goals (v1)

- Not a job queue / task orchestrator (that's TaskWeaver's domain).
- No built-in web UI (CLI + MCP + optional health endpoint only).
- No multi-tenant auth model. Auth = single-deployment API key **or** OAuth 2.1 proxy mode (never both, and never per-user identities in v1 — that's the v4.0 multi-user goal).
- **No requirement that *every* tool supports streaming from day one.**
  The architecture must be *stream-native* from day one; *which* tools actually
  stream is a per-tool configuration and rollout choice.

---

## 2. Design pillars

1. **Registry-driven.** Every tool is a declarative entry (YAML in `registry/`),
   not code. Adding a tool to a deployment = adding a file, no code changes.
2. **Provider abstraction.** Protocols (CalDAV, iCal, IMAP, SMTP, ntfy, Discord,
   process) implement a common contract. The MCP layer never knows the protocol.
3. **Stream-native execution.** Every execution produces a *stream of chunks*
   internally. Blocking tools just consume the stream at the end. Streaming tools
   push chunks to the client as they happen. Same code path — no two versions.
4. **Probe-based availability.** Tools are only *offered* when their configuration
   is sound. Connectivity is probed *softly* — a temporarily down service must
   never prevent Context Loom from booting.
5. **Fail loudly at config time, quietly at runtime.** A broken registry entry or
   missing env var is a configuration error (excluded tool + clear log + CLI
   validation). A flaky backend is a runtime concern (degraded status, clean
   error responses).
6. **The server is a pipe.** When a tool invokes `scripts/backup.sh`, Context
   Loom is not the tool — it is the pipe between the script and the model. First
   line of output should be able to reach the model while the script is still
   running.

---

## 3. Architecture

FIXME: folder structure is wrong.. we'll follow established standard for Symfony projects.
```
context-loom/
├── bin/                       # entrypoints (console, serve)
├── config/                    # Symfony config (services, packages)
├── src/
│   └── ContextLoom/
│       ├── Domain/            # pure models, no I/O
│       │   ├── RegistryEntry.php
│       │   ├── InputSpec.php
│       │   ├── OutputSpec.php
│       │   ├── ProbeSpec.php
│       │   ├── ProbeResult.php
│       │   ├── ToolRun.php    # stream of Chunk
│       │   └── Chunk.php
│       ├── Application/
│       │   ├── Registry.php           # load/validate registry YAML
│       │   ├── Health/HealthRegistry.php  # soft probe state, cache
│       │   ├── Executor/ToolExecutor.php # runs any entry -> ToolRun stream
│       │   └── ToolNameFactory.php
│       ├── Infrastructure/
│       │   ├── Process/ProcessRunner.php        # subprocess + process-group kill
│       │   ├── Process/StreamReader.php         # line/chunk reader with backpressure
│       │   ├── Calendar/CalDavClient.php        # PROPFIND/REPORT (sabre/vobject)
│       │   ├── Calendar/IcalClient.php          # fetch + parse ICS
│       │   ├── Email/ImapClient.php             # read mail (pure-PHP IMAP client)
│       │   ├── Email/SmtpClient.php             # send mail
│       │   ├── Notify/NtfyClient.php
│       │   ├── Notify/DiscordClient.php
│       │   └── Probe/  # per-protocol connectivity probes
│       ├── Mcp/
│       │   ├── ContextLoomServer.php    # wires mcp/sdk, registers tools
│       │   ├── ToolFactory.php          # RegistryEntry -> SDK tool (signature, schema)
│       │   └── StreamSink.php           # ToolRun chunks -> CallToolResult / progress
│       └── Console/
│           ├── ValidateCommand.php      # static registry validation
│           ├── ProbeCommand.php         # run connectivity probes now
│           └── ServeCommand.php         # run the MCP server (stdio/http)
├── registry/                  # declarative tool definitions (YAML)
├── tests/
├── Dockerfile                 # multi-arch (amd64+arm64), like task-weaver
├── .env.example
└── composer.json
```

### 3.1 MCP transport

- Use the official `mcp/sdk` server builder: `Mcp\Server\Server` + `Mcp\Server\Transport\StdioTransport`
  (default) and HTTP transport via the SDK (streamable HTTP).
- **Two official transports (D8):** MCP streamable HTTP + OpenAPI/REST. Same
  registry entry is the single source of truth for tool definitions and REST
  endpoints. `context-loom serve` runs one process serving MCP (`/mcp`), REST
  (`/api/{prefix}/{resource}`), and health (`/health`) under one router/port
  (D18). REST is a first-class citizen from Phase 1, not an afterthought.
- Tool registration is **declarative**: each `RegistryEntry` is converted into an
  SDK tool (`Mcp\Capability\Registry\ToolReference` + `ToolHandlerInterface`,
  or the `#[McpTool]` attribute for hand-written tools) whose input schema is
  generated from `InputSpec`s.
- Server identity: `name: context-loom`, `version: <semver>`.
- Auth: API key bearer, same philosophy as `mcp-server` (`CONTEXT_LOOM_API_KEY`);
  optional OAuth 2.1 proxy mode via SDK middleware — see §9.2.

### 3.2 Concurrency

- Each tool call runs in its own task/worker. Long-running tools must never
  block the event loop or other tools (process group isolation + per-run state).
- Tool calls should be cancellable by the client (disconnect → kill process group,
  cancel pending HTTP).

---

## 4. Registry system (the heart of this spec)

### 4.1 Entry lifecycle

FIXME: a tool with a valid configuration is always added to the tool definitions.. health status is for the health endpoint.
```
YAML file ──▶ parse ──▶ static validation ──▶ [required config present?]
                                                     │
                                      yes ──▶ registry holds a "live" entry
                                                     │
                                                     └─ no ──▶ excluded (logged, never registered)
        │
        └── at boot (optional, non-blocking): connectivity probe
                    │
                    ├── probe ok      → status: ok
                    ├── probe fail    → status: degraded/down (tool still registered by default)
                    └── probe skipped → status: unknown
```

Rules:

- **Static validation is a hard gate.** Bad YAML, unsupported `type`, missing
  required env, import errors → entry is **excluded** and the server still boots.
  (Matches `mcp-server`'s "skip invalid registry file, stay healthy" behavior,
  plus we add a CLI/validate command for pre-deploy checks.)
- **Connectivity probing is a soft gate.** It never prevents boot. It feeds a
  `HealthRegistry` that can, per entry, choose to hide the tool (`requires_healthy`)
  or merely annotate it (`offered but degraded`).
- **Probing is cached and re-run on a schedule** (not just once at boot), so a
  service that comes back online re-advertises without a restart.
- **Tool definitions are cached client-side.** The LLM fetches `tools/list` once
  and does not re-read definitions between calls. Therefore **live health state
  must never be smuggled into tool descriptions** (that would be stale within
  seconds and actively misleading). Health is surfaced only through a dedicated
  `contextloom_health` tool (see §6.4) and (v3.0, optional) explicit status
  updates via TaskWeaver v3.0 / MCP list-changed notifications.

### 4.2 Entry schema (draft)

The YAML schema is the contract. Draft:

```yaml
# registry/calendar.yaml
name: calendar_create_event          # stable public tool name (snake_case)
title: Create calendar event
description: Create a new event on the editable CalDAV calendar.
domain: calendar                     # calendar | email | notify | command | misc
type: internal                       # internal | process | http
handler: CalendarService::createEvent  # internal only (service id + method)
requires:                            # env conditions (port of mcp-server `requires`)
  - "CALDAV_URL"
  - "CALDAV_USERNAME != ''"
inputs:
  summary:      { type: string,   required: true }
  start:        { type: datetime, required: true }
  end:          { type: datetime, required: true }
  description:  { type: string }
  all_day:      { type: bool, default: false }
  alarms:       { type: json,     required: false }
output:
  mode: block                       # block | stream
  format: json
probe:
  level: connectivity               # static | connectivity | none
  method: PROPFIND                  # overridden by protocol default
  timeout: 3
  expects_any: [207]
streaming:
  enabled: false
```

Process/command entries:

```yaml
# registry/backup.yaml
name: run_backup
title: Run backup script
description: Runs backups/backup.sh and streams its progress.
domain: command
type: process
executable: scripts/backup.sh        # resolved relative to project root
args:
  - name: --target
    type: string
    default: /data
    field_name: target
    required: false
  - name: --dry-run
    type: flag
    field_name: dry_run
  - name: source                      # positional after flags
    type: string
    required: true
requires:
  - "APP_ENV != dev"
output:
  mode: stream
  chunks: line                        # line | byte | json
  max_chunks: 5000
  max_bytes: 1048576                  # 1 MiB hard cap
timeout: 300                          # seconds (per-run)
probe:
  level: static
  check: executable-exists
streaming:
  enabled: true
```

HTTP entries (new first-class `type: http`):

```yaml
# registry/vital_pulse_list_records.yaml
name: vital_pulse_list_records
title: List vital-pulse readings
description: List health log readings (blood pressure, heart rate, weight) from vital-pulse for an inclusive date range.
domain: health    # service-specific; no forced `cmd_` namespace
type: http
http:
  method: GET
  url: "${VITAL_PULSE_URL}/api/v1/logs"   # template vars: URL from env, rest from args
  headers:
    Authorization: "Bearer ${VITAL_PULSE_API_KEY}"
  body: null
  params: [from, to]                       # query params; if args have same name, map automatically
  auth: api_key                            # none | api_key | basic | bearer | oauth_client_credentials
  api_key_header: Authorization
  api_key_in: header                       # header | query
  api_key_prefix: Bearer
requires:
  - "VITAL_PULSE_URL"
  - "VITAL_PULSE_API_KEY"
args:
  - name: from
    field_name: from_date                   # LLM sees from_date; HTTP uses name/field_name
    type: string
    required: true
    help: Start of the date range (ISO 8601, inclusive), e.g. 2025-01-01.
  - name: to
    field_name: to_date
    type: string
    required: true
    help: End of the date range (ISO 8601, inclusive), e.g. 2025-01-31.
output:
  mode: block
  format: json
probe:
  level: connectivity
  method: GET
  url: "${VITAL_PULSE_URL}/health"
  timeout: 3
streaming:
  enabled: false
```

**`type: http` is the biggest registry change (per boss, #8).** It lets a tool be
a declared HTTP endpoint (method, URL template, headers, auth, params, body) with
one YAML — a native tool, not a script wrapper. The `http:` block maps cleanly to
the REST/OpenAPI route for the same tool (single source). Penny/vital entries
become `http` (their APIs are HTTP+token), and generic third-party HTTP APIs in
the future are just another entry.

### 4.3 Why `output`/`streaming` are separate from `type`

- `type` = *how work is performed* (internal service, subprocess, HTTP API).
- `output` = *what the produced data looks like* (block vs stream, chunk unit,
  caps).
- `streaming` = *how the transport forwards it* (progress notifications vs
  streamable tool result vs none).

Separating them means a CalDAV `REPORT` can be block (normal) while a process
can stream; and if a client doesn't support a given channel, the same entry
degrades without changing the YAML.

### 4.4 Registry CLI

- `php bin/console contextloom:validate [--strict]` → static validation report
  (exit codes like Python `app.validate`: 0 ok, 1 errors, 2 missing dir).
- `php bin/console contextloom:probe [--all|--domain=calendar]` → run connectivity
  probes on demand, print status table.
- `php bin/console contextloom:tools` → list currently live tools + health.

### 4.5 Naming & API surface (decided)

**Naming is now NO-PREFIX and native.** Registry entries are tools, not
"commands" — `vital_pulse_list_records`, `run_backup`, `penny_list_transactions`,
not `cmd_vital-pulse-list-records`. The word a user/LLM types is the name of the
thing. The old `cmd_` prefix (and `prefix{_}`-forced namespace) is **dropped**.

Why (boss's instinct, and the right one):
- LLMs read natural language; prefixed names bury the verb and make the tool feel
  like a plumbing artifact rather than a capability.
- `cmd_` was an artifact of the old mcp-server inherited naming; it added noise
  with no namespace benefit — prefixes do exist but are the domain words
  (`vital_pulse`, `penny`, `calendar`), not a generic `cmd_`.
- HTTP/process/internal entries all become genuinely first-class: a model calls
  `vital_pulse_list_records` with the same ergonomics as `calendar_list_events`.
- REST path derives from the same name: `/{tool_name_with_underscores_to_slashes}`
  — deterministic, no separate prefix mapping to maintain.

| Entry (YAML `name`) | MCP tool name | REST/OpenAPI path |
|---|---|---|
| `calendar_list_events` | `calendar_list_events` | `/calendar/list/events` → `/calendar/events` |
| `calendar_create_event` | `calendar_create_event` | `/calendar/events` (POST) |
| `email_list_messages` | `email_list_messages` | `/email/messages` |
| `notify_send` | `notify_send` | `/notify/send` |
| `run_backup` | `run_backup` | `/run/backup` |
| `vital_pulse_list_records` | `vital_pulse_list_records` | `/vital-pulse/list-records` |
| `penny_list_transactions` | `penny_list_transactions` | `/penny/list-transactions` |

Rules:

- Tool names: `{domain}_{verb}_{resource}` or, for service tools,
  `{service}_{verb}_{resource}` — verbs stay tiny (`list`, `get`, `create`,
  `update`, `delete`, `send`, `run`, `probe`).
- **No `cmd_` prefix, ever.** Generic commands (`run_backup`, `log_read`) are
  named for what they do.
- **No bare single-word names that collide across domains** — the domain word
  is always present so `list_records` can't exist twice.
- **REST path is not manually invented**: derived from the tool name by
  `_` → `/` (`notify_send` → `/notify/send`), with a small alias table for
  prettier paths where needed (`/calendar/events` over `/calendar/list/events`).
- A registry entry is **the single source of truth** for both surfaces: `name`
  derives the MCP tool name and the API path. One YAML → one tool → one route.
- Keep names **short and natural**: `vital_pulse_list_records`, not
  `vital_pulse_health_log_list_readings`.

---

## 5. Streaming — "the server is a pipe"

### 5.1 The core contract (protocol-independent)

Every tool execution returns a **`ToolRun`** — a stream of `Chunk` objects:

```php
final class Chunk {
    public function __construct(
        public readonly ChunkType $type,   // stdout|stderr|progress|meta|done
        public readonly string $data,
        public readonly ?int $sequence = null,
        public readonly ?float $emittedAt = null,
    ) {}
}
```

`ToolExecutor::execute(RegistryEntry $entry, array $args): \Generator<Chunk>`

One code path. Two sinks inside the MCP layer:

- **Block sink:** consumer drains the generator, concatenates, returns a single
  `CallToolResult` (exactly `mcp-server`'s behavior today).
- **Stream sink:** consumer forwards chunks to the client as they arrive (see
  §5.2), then the final `CallToolResult` with the full/truncated output + meta
  (`tool_run_id`, truncation flag, exit code).

Because chunks are protocol-independent, we can later add an SSE/REST transport
or a different MCP streaming shape without touching the executor.

### 5.2 Streaming over the wire — verified against `mcp/sdk` 0.8.x

Facts we checked in the SDK source (2026-09-12):

- **There is no `StreamableToolResult` / streamable tool result type.** A tool
  handler must return one `CallToolResult` (`Mcp\Schema\Result\CallToolResult`).
- **`Mcp\Server\ClientGateway::progress(float $progress, ?float $total, ?string $message)`
  is the sanctioned out-of-band channel.** It sends a `ProgressNotification`
  (`notifications/progress`) tied to the active request's `progressToken` — the
  client must have included `_meta.progressToken` in its `CallToolRequest`, else
  the SDK no-ops. `$message` can carry a chunk of output text.
- **MCP `logging` notifications are deprecated** (SEP-2577, protocol 2026-07-28,
  removal 2027-07-28) — `ClientGateway::log()` triggers a deprecation. **We must
  NOT use log notifications as the streaming fallback.** (My earlier draft said
  we would — this replaces that.)
- **`CallbackStream`** (PSR-7 stream that runs `echo + flush()`) exists for true
  SSE at the transport/response level. Useful for a *raw* streaming endpoint, not
  for tool results.

**Consequence — v1 streaming strategy (middle path):**

1. **Always** build `ToolRun`/`Chunk` internally (stream-native executor).
2. **Block tools:** drain at the end. Standard `CallToolResult`.
3. **Streaming-capable tools (opt-in per entry):** during execution, call
   `$gateway->progress(...)` for each chunk (or a debounced batch) using the
   request's `progressToken`. Progress value: incrementing counter with
   unknown total, or bytes/total when known; `message` = the line/chunk text.
   After completion, return the final `CallToolResult` (full or truncated).
   This is honest: the model sees early output *while* the tool is still running,
   on every client that requests progress — which is most production clients.
4. **True streamable tool results:** not in SDK 0.8.x. Parked as a follow-up —
   track upstream (`mcp/sdk` roadmap / spec SEP); if it lands, `StreamSink` gets
   a third sink with zero changes to the executor. Until then we do NOT fake it.
5. Unsupported clients (no progressToken): degrade silently to block behavior —
   same entry, same YAML.

Because the pipeline is `ToolRun → (block | progress | future streamable)`,
"streaming all the way through" is an architecture property, not a per-tool
feature that has to be retrofitted later.

### 5.3a What the SDK does and doesn't stream (clarified this round)

Everything above is verified against `mcp/sdk` 0.8.x. In one table:

| SDK cap | What it does | For us |
|---|---|---|
| `StreamableHttpTransport` | POST `/mcp` + SSE response stream + session | ✅ the transport |
| `ClientGateway::progress()` | progress frames on an active request | ✅ v1 liveness |
| `Protocol::sendNotification()` | server→client notifications on a session | ✅ future, once our client consumes |
| `CallbackStream` | PSR-7 stream that echoes+flushes for SSE | low-level, useful for a raw REST stream |
| `StreamableToolResult` | **tool result itself streams** | ❌ not in SDK 0.8.x — parked (D5) |

So: "the SDK has streaming" is true for the *transport* and for *progress*
notifications, but **not** for the *tool result* — that's the missing piece and
it's upstream (SEP). We architect for it, don't fake it.

### 5.3b Inbound events / push (the "callback" shape)

**The event model is separate from tool streaming.** A tool is *invoked by the
model*; an **event** watches a resource and *pushes* when it changes,
without the model asking. For v1, we define one event source (D23):

- **Email arrival (IMAP IDLE)** — an `internal` registry entry with a
  background worker (`ImapIdleListener`). On new mail, it builds a structured
  event payload and **delivers it to an outbound handler**.

The outbound handler is **not** an MCP notification (TaskWeaver's client is
request/response only — verified). It is one of the callback transports in
**Open Q#2** (TaskWeaver webhook / task creation / long-poll), with the goal
being TaskWeaver *begins acting* near-real-time.

```
┌─────────────┐   IMAP IDLE    ┌──────────────────┐   outbound HTTP   ┌──────────┐
│  mail server │ ──────────────▶ │ context-loom      │ ────────────────▶ │ TaskWeaver │
│              │                │ ImapIdleListener  │   (webhook/callback)│           │
└─────────────┘                └──────────────────┘                     └──────────┘
```

The event entry is `type: internal` (it's our code + IDLE), and it is **not
callable as a tool** — it's a push source. (A declarative `type: webhook`/`push`
registry type is parked for later; `type: http` remains for *declared outbound
REST calls to third parties*, D13.)

This is intentionally *not* "server→client MCP notification" — that requires
TaskWeaver to consume server→client notifications, which it doesn't yet. That's
the future ideal, explicitly kept separate. See §9.3b.

### 5.3c Streaming a subprocess (the pipe)

- Start process with `proc_open` (or `Symfony Process`) **in its own process group**.
  PHP runtime image confirmed to have `posix` + `pcntl` + `sockets`.
- Read stdout/stderr **line-wise incrementally** (non-blocking, `stream_select` /
  async reader). Each complete line becomes a `Chunk` immediately.
- **First byte flies while the process still runs** — that's the win: a
  `sleep 30 && echo done` script surfaces `done` before the tool would otherwise
  finish (and in streaming mode the model sees progress *during* generation).
- Backpressure: `max_chunks`/`max_bytes` caps; debounce (e.g. ≥100ms between
  progress notifications); on cap → emit truncation meta, kill the process group,
  return the partial result as an error-flagged result.
- **Process-group kill is mandatory** (port of the Python executor's
  `start_new_session` + `os.killpg` behavior): on timeout or client disconnect,
  kill the whole group — not just the direct child — so grandchildren
  (curl, rsync, etc.) can't orphan and hold the pipe open. Requires
  `setsid`/`exec` wrapper + `posix_kill(-pid, SIGKILL)` (verified feasible;
  document `posix` ext as a hard Docker requirement).
- Timeout: per-entry `timeout`, default 30s, with the same "read whatever
  was produced, then fail" semantics as Python.

### 5.4 Streaming an HTTP/SSE endpoint

Future: `type: http` + `output.mode: stream` + `chunks: line` reads an SSE/NDJSON
body incrementally. Not v1, but the contract already supports it — and
`CallbackStream` is the transport hook when we get there.

---

## 6. Probing: "don't advertise what isn't working"

### 6.1 Philosophy

- **Static probe (level 1, boot, hard):** configuration exists and is well-formed —
  env set, URL parses, credentials present, executable on disk, handler resolvable.
  Failure → tool excluded. Classic `mcp-server` behavior, kept.
- **Connectivity probe (level 2, optional, soft):** actually establish
  communication — TCP/TLS up, auth handshake succeeds, endpoint responds to a
  safe request. **This must never block boot.** Flat env checks (URL + key) can
  say "configured" when the service is unreachable or the key is wrong; a probe
  catches that pre-emptively.

### 6.2 Boot policy (decided)

```
boot sequence:
  1. load registry, static-validate ALL entries (hard)
  2. register tools whose static validation passed
  3. spawn connectivity probes in the background (async, bounded)
     - each probe: timeout 3s, concurrency limited (e.g. 4)
     - probe failures NEVER abort boot; they only write HealthRegistry state
  4. server is ready immediately; health may say "unknown" for first ~3s
```

Config knobs:

- `PROBE_MODE = static|connectivity` (default **connectivity** but non-blocking;
  `static` for people who hate any runtime outbound traffic at boot).
- `PROBE_INTERVAL` (default 60s; 0 = probe once, no schedule).
- `PROBE_TIMEOUT` (default 3s per target).
- `PROBE_ON_BOOT = true|false`.
- Per-entry `probe: none` to opt out entirely (e.g. a webhook endpoint that
  cannot be safely pinged without side effects — like Discord, see below).

### 6.3 Per-protocol probes (draft)

| Protocol | Static check | Connectivity check | Side effects? |
|---|---|---|---|
| CalDAV | URL + user + pass/ token | `PROPFIND Depth:0` on calendar home, expect 207; then `REPORT calendar-query` on a known calendar → 207 | none (safe) |
| iCal | URL set | `GET` with `Range`/HEAD-ish, expect 2xx, parse first event | none (safe) |
| IMAP | host/port/ssl/user/pass | TCP-TLS + `LOGIN` (or `AUTHENTICATE`); if `LOGIN` disabled, `CAPABILITY` + `STARTTLS` + `NOOP` | none (safe) |
| SMTP | host/port/user/pass | connect + `EHLO` + (optionally `AUTH`) | none (safe) |
| ntfy | URL + topic(s) | `GET /v1/health` (or `GET /<topic>/json?since=0` with `poll=1` head) | none (safe) |
| Discord webhook | URL shape (`https://discord.com/api/webhooks/…`) | **no safe probe** — GET/POST would 404/400 at best, or send a test message (side effect) → default `probe: none`, only static URL validation; optional `probe: test-message` opt-in that sends a verification message | side effect if opted in |
| Process | executable exists + shebang/mode; `--help`/`--version` only if declared | none (don't run user scripts at boot!) | **never run at boot** |

Rule of thumb encoded in the schema: **never execute anything with side effects
during a boot probe.** Probes are read-only by construction. Process entries are
static-only unless the entry author explicitly declares a `--version` probe.

### 6.4 Health states & behavior

- `ok` — probed, reachable.
- `unknown` — not probed yet / probe disabled.
- `degraded` — reachable but something off (e.g. CalDAV root OK, one calendar
  unreadable; IMAP login OK, mailbox list partial).
- `down` — probe failed (timeout, auth rejected, connection refused).

Registration policy (decided): by default all four states stay **registered** —
a temporarily down backend means calls return a clean, structured "backend
unreachable (`down`)" tool error, never a missing tool. Per-entry opt-in
`requires_healthy: true` flips to hide-if-not-ok.

**How the model learns about health (revised):** because tool definitions are
cached by the client, we do **not** mutate tool descriptions with health status
(that would be stale almost immediately — the model won't re-fetch). Instead:

- The **`contextloom_health` tool** reports live state per domain (status, last
  probe, error, config presence — no secrets) on every call. The model can check
  it *when* it needs to know why a tool might fail, which is exactly when the
  information is fresh.
- Probe failures also emit structured logs/tracing via OpenTelemetry (the
  SEP-2577 replacement path).
- **v3.0 optional (parked):** when TaskWeaver v3.0 supports status updates /
  push semantics, Context Loom can emit `tools/list_changed`-style notifications
  so clients invalidate cached definitions. Until then the health *tool* is the
  only live-signal channel. (Conceptually great, not in v1 — exactly as agreed.)

---

## 7. Provider matrix & tool surface (v1)

### 7.1 Calendar (iCal + CalDAV)

- CalDAV: read/write, **read-write parity with `mcp-server`** (events + tasks:
  list/get/create/update/delete, alarms, recurring expansion, timezone
  preservation — the hard-won fixes from `mcp-server` must carry over as
  requirements, not be re-learned).
- iCal: read-only, merged into unified calendar listing, dedupe by `(uid, start)`.
- Providers register via env (`CALDAV_URL`, `ICAL_URLS` …) and are discovered at
  boot like `mcp-server`'s `ProviderRegistry`.

Tools: `calendar_list_events`, `calendar_get_event`, `calendar_list_calendars`,
`calendar_create_event`, `calendar_update_event`, `calendar_delete_event`,
`calendar_list_tasks`, `calendar_get_task`, `calendar_create_task`,
`calendar_update_task`, `calendar_delete_task`.

### 7.2 Email (IMAP + SMTP)

- IMAP read: list mailboxes, list messages (recent/since/unseen), get message
  (RFC822 / parsed text+attachments), mark read/unread, move/delete (configurable).
  Pure-PHP IMAP client (no `ext-imap` dependency) or well-maintained library —
  **open decision** (§9).
- SMTP send: send to addresses (text + HTML, maybe attachments), via
  Symfony Mailer or PSR-18.
- Probes per §6.3.

Tools: `email_list_mailboxes`, `email_list_messages`, `email_get_message`,
`email_mark_read`, `email_move`, `email_send`.

### 7.3 Notify (ntfy + Discord + webhooks)

Port `notify_service.py` behavior: level-aware routing (info/notice/critical/
emergency), per-level webhook/topic fallback, chunking (Discord 4096 limit),
color/tag mapping. Add generic webhook provider (configurable method + payload
template) — small, high value.

Tools: `notify_send` (channels param). Health is NOT duplicated — see §7.5
(single `contextloom_health`).

### 7.4 Registry commands — including our own services (decided)

Keep the `mcp-server` registry command pattern (YAML → tool), but with the
streaming/probe extensions above. **This is where "the pipe" earns its keep.**

- **No `cmd_` namespace anymore (D12).** Shell/process entries are named for what
  they do (`run_backup`, `log_read`) — they're tools, not "commands".
- **`type: http` is first-class (D13, the big one).** Registry entries can be
  declared HTTP endpoints: method, URL template (env + args interpolation),
  headers, auth mode, params, body. One YAML → one native tool → one REST route.
- **Our own services use the registry:** **penny-track** and **vital-pulse** are
  **`type: http`** entries (as decided) — port their existing YAMLs from
  `mcp-server` (`registry/penny_transactions.yaml`, `registry/vital_readings.yaml`)
  and convert to `http` blocks. They are not core generics but they ship in the
  same repo, one file each, no code, and they exercise the HTTP type from day one.
- `type: http` also unlocks arbitrary third-party HTTP APIs as tools (future).

### 7.5 Health / meta

- **`contextloom_health` — single health surface.** One tool, per-provider detail
  inside its response: `{ domain: { status, last_probe, error, config_present } }`
  (no secrets). Also the `GET /health` endpoint on the REST/OpenAPI side returns
  the same shape. Per-domain health tools (`notify_health`, etc.) are NOT a thing
  in v1 — one surface, less tool clutter, one way for the model to ask "what's up?".
- Misconfiguration reporting: when an entry fails static validation (e.g. someone
  adds a bad CalDAV env), it is **logged at server level** (structured warning)
  AND surfaced by `contextloom_health`/`/health` as `config: invalid` with the
  reason — so operators see it without digging through logs, and users learn why
  a tool is missing.

---

## 8. Configuration model

- One `.env` (deployment-config) via Symfony Dotenv; `CONTEXT_LOOM_` prefix for
  server config; provider vars plain (`CALDAV_URL`, `IMAP_HOST`, `NTFY_URL`,
  `DISCORD_*_HOOK`, …).
- `requires:` in registry YAML reuses the `mcp-server` condition mini-language
  (`ENV_VAR`, `ENV_VAR == x`, `ENV_VAR != x`) — proven, keep it.
- Probe knobs (§6.2) all live under `CONTEXT_LOOM_PROBE_*` or plain `PROBE_*` —
  decide (§9).
- `.env.example` documents every variable; `contextloom:validate` checks that
  live tools have all required vars (fast CI check).

---

## 9. Decisions & open questions

### Decided (this round)

| # | Topic | Decision |
|---|---|---|
| D1 | Tool naming | Native, no prefix: `{domain}_{verb}_{resource}` (e.g. `vital_pulse_list_records`). No `cmd_`. REST path derives from the tool name. See §4.5. |
| D2 | Health in tool descriptions | **No** — tool defs are cached; stale annotation would mislead. Health only via `contextloom_health` tool; push/status updates parked as v3.0 optional (TaskWeaver v3.0). |
| D3 | penny-track / vital-pulse | Use the registry — port their YAMLs; not hand-coded tools. |
| D4 | Streaming fallback | MCP log notifications are **deprecated** (SEP-2577) — do not use. Use `ClientGateway::progress()` (progressToken) + final `CallToolResult`. |
| D5 | Streamable tool results | Not in `mcp/sdk` 0.8.x (verified) — architect for it, don't fake it; track upstream. |
| D6 | Process-group kill | Feasible (`posix`/`pcntl`/`sockets` present in runtime image) — required. |
| D7 | Verb-first naming | Confirmed: `{verb}_{resource}` order — verbs first because LLMs read natural language (`calendar_list_events`, not `calendar_events_list`). Now folded into D1/D12 (no prefix). |
| D8 | OpenAPI + MCP | **Both** in the official support matrix: MCP streamable HTTP + OpenAPI/REST, same registry entry as single source. Phase 1 includes the OpenAPI surface. |
| D9 | `requires_healthy` default | **Registered by default** — tool definitions must not change on reboot when config hasn't changed. Bad config = server-level structured log + health endpoint reports `config: invalid` with reason. |
| D10 | Health surface | **Single** `contextloom_health` (MCP) / `GET /health` (REST), per-provider detail in the response. No per-domain health tools. |
| D11 | OpenTelemetry | Pattern now (structured PSR-3 + manual spans), exporter later (optional dependency, env-gated). No OTel infra required for v1. See §9.1. |
| D12 | No `cmd_` prefix | Dropped. Registry entries are native tools: `vital_pulse_list_records`, `run_backup`. Naming = `{domain}_{verb}_{resource}`; REST path derives from name. See §4.5. |
| D13 | `type: http` | First-class registry type: method, URL template, headers, auth, params, body. One YAML → tool + REST route. Penny/vital are `http` entries. |
| D14 | Auth mode | **(b)** API-key + optional OAuth 2.1 proxy (`CONTEXT_LOOM_AUTH=apikey|oauth`), SDK middleware. See §9.2. |
| D15 | OpenAPI parity | **Full parity** — every tool in the registry has an MCP tool AND an OpenAPI operation. REST is not trimmed; it mirrors the registry 1:1 from Phase 1. |
| D16 | IMAP client | **ImapEngine** (`directorytree/imap-engine`): pure PHP (no ext-imap — removed in PHP 8.4), IMAP IDLE, fluent OO, active, good test coverage. Rejected: ddeboer (needs ext-imap), dg/imap (no IDLE/OAuth/SEARCH), webklex (24 deps + Symfony/Laravel gravity). See §9.3. |
| D17 | CalDAV client | **sabre/dav + sabre/vobject** — no hand-rolled DAV. |
| D18 | Serve shape | **Single process, single port** — `/mcp` + `/api` + `/health` under one router (Symfony HTTP kernel + SDK PSR-7 bridge). |
| D19 | Multi-account | Single user for v1 (1 CalDAV + N iCal, 1 IMAP/SMTP). Multi-user per-account customization parked as v4.0 (OAuth + UI). Explicit non-goal now. |
| D20 | Docker naming/versioning | Family conventions: `digitaladapt/context-loom`, `latest`/`{VERSION}`, amd64+arm64, `develop` tags on develop. Mirror task-weaver CI. |
| D21 | Scopes | **Domain-level** for OAuth (`calendar:read`/`calendar:write`, `email:read`, `notify:send`...). Tool-level granularity is a later refinement; API-key mode is all-or-nothing. |
| D22 | HTTP `auth: oauth_client_credentials` | **Future capacity** (not this round). `http` entries ship `none`/`api_key`/`bearer`/`basic` now; client-credentials flow will be added only when a real consumer needs it. No YAML schema change — it's additive. |
| D23 | Inbound events (email → TaskWeaver) | **Email arrival is an `internal` registry tool that acts as a push/`notify` source.** It is *not* a manually-invoked tool. TaskWeaver's MCP client is request/response-only today (no notification consumer) — so v1 push must go over **TaskWeaver's own HTTP webhook/inbound surface**, not over MCP notifications. MCP `notifications/...` from server→client stays a future enhancement once TaskWeaver learns to consume them. |


### Still open

1. **Streamable tool results path** — do we (a) wait for `mcp/sdk` to add
   `StreamableToolResult`/SEP support, (b) propose it upstream ourselves, or (c)
   build a custom SSE/`CallbackStream` endpoint that serves raw chunk streams to
   MCP Apps / REST clients in the meantime? (v1 uses progress notifications
   either way; this decides when "true" tool streaming lands. Still open by
   design — it's upstream-and-you; not blocking. The full SDK-vs-tool-streaming
   breakdown is in §5.3a.)
2. **Inbound push mechanism (D23 detail)** — the exact transport for the
   email-entry → TaskWeaver callback. Candidates: (a) TaskWeaver webhook,
   (b) `PUT /task` task creation (webhook triggers), (c) long-poll HTTP. Need to
   inspect TaskWeaver's inbound surface in Phase 1 to pick one that lets
   TaskWeaver *begin acting* (not just log).
3. **IMAP idle → how it feeds event detection** — ImapEngine `idle()` is blocking
   and must run in a worker loop, not the MCP request path. Do we run one
   dedicated idle worker per account (mirroring mcp-server's background task),
   or poll? Architecture detail to spike in Phase 1.
4. **Nice REST path alias rule** — the small alias table for prettier paths
   (`/calendar/events` vs `/calendar/list/events`). Nail down the exact rule
   (deterministic conversion vs curated alias map) during Phase 1.

### 9.3a The streaming conversation, clarified (this round)

**Where the SDK's "streaming" is (and the gap):**

| Capability | SDK | What it does | Status |
|---|---|---|---|
| Streamable HTTP transport | `StreamableHttpTransport` | POST `/mcp`, SSE response stream; `initialize` handshake + session | ✅ ships |
| Progress notifications | `ClientGateway::progress()` (ProgressNotification) | tool handler can emit progress frames to the client mid-call | ✅ ships |
| Server→client notifications | `Protocol::sendNotification()` | serves `resources/updated`, `notifications/progress`, etc. | ✅ ships (server side) |
| CallbackStream | `CallbackStream` | PSR-7 stream whose read triggers `echo+flush()` — used for SSE | ✅ ships (low-level) |
| **StreamableToolResult** | ❌ not in 0.8.x | upstream/SEP — the *tool result itself* streams | ❌ gap |

**The gap in plain English:** the SDK can *send* things to a connected client (progress
notifications, resource updates) and supports the HTTP streaming *transport*
(SSE). What it *cannot* yet do — and what "streaming tool results" means — is
the **`tools/call` response being a stream of chunks** (a `StreamableToolResult`
that yields partial content, rather than one final JSON-RPC result). So:

- What we *can* do now: `ClientGateway::progress()` — a long-running tool
  (`run_backup`, `log_read`) emits progress frames while it runs, then a final
  `CallToolResult`. That's the v1 streaming deliverable.
- What we *cannot* do yet: the final tool result itself being chunked/streamable.
  That's upstream `StreamableToolResult` — parked as D5, tracked upstream.

**What this means for streaming UX:** use progress for *liveness*, and either
(a) the final JSON `CallToolResult` (standard), or (b) a future StreamableToolResult
for true chunking. Never fake it by abusing deprecated log notifications.

---

### 9.3b Inbound events context (this round)

**The goal (from boss):** *"an email comes in — Context Loom gets that event in
basically real time, and either streams it to TaskWeaver, or — for lack of a
better term — does a 'callback' so TaskWeaver could receive the event in near
real time and begin acting on it right away."*

**Reality check (verified):** TaskWeaver's `HttpMcpClient` is request/response
only — it does `tools/list` and `tools/call`, and it reads the SSE stream *until
the JSON-RPC result frame* and disconnects. It has **no notification consumer**
(`notifications/...` in, or persistent session). So TaskWeaver, today, cannot
receive a server→client MCP notification and act on it. Similarly, Context Loom
can't *push* a new MCP notification to TaskWeaver because TaskWeaver isn't
listening for one.

**So D23 is: inbound events are a `type: internal` registry entry (an event
source), not a callable tool.** Two sub-parts:

1. **Detection (Context Loom side).** Email arrival is detected via IMAP IDLE per
   account (worker loop). This is an `internal` entry whose `probe` is the idle
   listener. After detection, Context Loom **invokes an outbound action** — a
   `webhook`/`callback` to TaskWeaver. It is *not* a tool the model calls.
2. **Delivery (TaskWeaver side).** Usually one of:
   - **TaskWeaver HTTP webhook / inbound endpoint** — Context Loom POSTs the
     event (`webhook/payload`). This is the natural "callback" and lets
     TaskWeaver *create a task / trigger a flow* immediately. **Open Q#2**.
   - **Task creation via TaskWeaver REST** — `POST /api/task` (or equivalent) with
     the event as task context; TaskWeaver scheduler/worker picks it up and
     begins.
   - Future: MCP `notifications/...` (a real time streaming push) — only once
     TaskWeaver's client can consume server→client notifications. That's the
     long-term ideal: context-loom pushes `notifications/...`/resource-updated
     to TaskWeaver, which then calls back into Context Loom tools to act. But
     **this is explicitly future** — TaskWeaver is not there yet.

**Why this is the right shape:** it honors the *process* boundary. Context Loom
is a resource server and event *source*; TaskWeaver is the orchestrator. The
handoff is an outbound HTTP call (or task creation), which is architecturally
simple and debuggable, and it does not require either project to grow MCP
notification-consumption now. TaskWeaver can begin acting immediately because
the event arrives as a normal inbound request.

**Naming note:** the inbound entry is `type: internal` (it's context-loom-internal
code), not `type: http` (which exists for *declared external REST calls*). A
future `type: webhook`/`type: push` may be a distinct registry type if we want
inbound events to be declarative too — parked.

---

### 9.4 The registry and streaming (D13/D15/D23 interplay)

- `type: http` (D13) — *declared outbound REST call.* One YAML → tool + route.
  Auth modes: `none`/`api_key`/`bearer`/`basic` now, `oauth_client_credentials`
  future (D22).
- `type: internal` — *our service code* (CalendarService, ImapIdleListener, etc.).
  The inbound event entry (D23) is `internal`.
- `type: process` — *subprocess.*
- Streaming is **per-tool, not per-type**: any tool can be marked
  `streaming.enabled` and use `ClientGateway::progress()` for liveness (D4),
  even if its final result is a standard `CallToolResult`. True
  `StreamableToolResult` is parked (D5).

### 9.1 OpenTelemetry — review & recommendation (decision pending boss review)

**Context:** we don't currently use OTel anywhere in the personal stack, but want
to be as future-facing as possible on observability. SEP-2577 (protocol 2026-07-28)
pushes MCP logging toward OTel, so it's not a speculative choice — it's where the
ecosystem is heading.

**How it works (verified):**
- `open-telemetry/sdk` + `open-telemetry/exporter-otlp` (Composer, no PHP extension
  required for manual instrumentation).
- Optional `open-telemetry/opentelemetry-auto-symfony` (v1.2.0) auto-instruments
  Symfony; `opentelemetry.so` extension gives deeper auto-instrumentation (both
  optional).
- OTLP HTTP/protobuf export over `OTEL_EXPORTER_OTLP_*` env vars; batching via
  `OTEL_BSP_*`; sampling via `OTEL_TRACES_SAMPLER`.

**Pros of adopting now:**
- Zero rework later: spans/trace IDs are the natural way to correlate a tool call
  with subprocess, probe, or provider request — that mapping is *painful* to add
  after the fact (this is exactly the Python codebase's debugging pain with
  backends).
- Structured, env-driven, no infra required until you want it: default = no exporter
  (cheap no-op), enables with `OTEL_EXPORTER_OTLP_ENDPOINT` when you're ready.
- SEP-2577 direction: MCP `logging` is deprecated → logs-to-OTel is the modern wire.
- If self-hosting later: Grafana Tempo/Loki, SigNoz, Uptrace, or a bare collector
  — all speak OTLP; none lock us in.
- Public project: OTel in a public repo makes it credible + integrable for others.

**Cons / costs:**
- New dependency surface (SDK + exporter), a few more env vars to document.
- Auto-instrumentation quality varies; manual spans for our hot paths (ToolExecutor,
  probes, providers) are where the value is — that's our code to write either way.
- No existing infra: someone has to provision a collector/backend before telemetry
  actually flows. Not a code blocker (exporters are pluggable), but a deployment
  reality.

**Recommendation:** *Adopt the pattern now, wire the exporter later.*
- From day one: every log is PSR-3 with structured context (tool name, run id,
  backend, duration). That alone keeps us OTel-ready.
- Phase 3+: emit spans on tool execution, subprocess runs, probes (the hot paths).
- Ship `open-telemetry/sdk` + `exporter-otlp` as **optional** (or `suggest` in
  composer; not hard require). When `OTEL_EXPORTER_OTLP_ENDPOINT` is set → export;
  unset → no-op single-file span processor (near-zero cost).
- **No OTel infra required for v1.** It stays an option, a convention, and a
  README page — not a deployment dependency. This is the "future-facing" middle
  ground you asked for.

### 9.2 Auth — OAuth 2.1 vs API key (NEW CRITICAL QUESTION)

**From the spec:** since 2025-06-18, remote MCP servers using Streamable HTTP are
OAuth 2.1 **resource servers** — they must validate tokens issued by an external
authorization server; the core spec says remote servers are *strongly recommended*
to implement it (and real clients — Cursor, Claude Code, etc. — expect the OAuth
handshake for remote servers). API-key bearer is fine for stdio/local/trusted use
but is a recognized deviation for a public remote server.

**Good news (verified in the SDK):** `mcp/sdk` 0.8.x ships the whole OAuth server
side out of the box — `OAuthProxyMiddleware` (delegates `/authorize`, `/token`,
`/.well-known/oauth-authorization-server` to an upstream IdP — Entra, Keycloak,
Auth0, Okta, league/oauth2-server), `AuthorizationMiddleware`,
`ClientRegistrationMiddleware` (RFC 7591 DCR), `ProtectedResourceMetadataMiddleware`.
We do NOT have to build an auth server; we point at one.

**DECIDED (boss, this round): (b) — API-key + OAuth both supported (D14).**

- `CONTEXT_LOOM_AUTH=apikey` (default): `Authorization: Bearer <CONTEXT_LOOM_API_KEY>`
  — zero config, same as `mcp-server`, used for stdio / local / TaskWeaver.
- `CONTEXT_LOOM_AUTH=oauth`: OAuth 2.1 via SDK middleware. **We are not an auth
  server.** We validate tokens (AuthorizationMiddleware + a
  `AuthorizationTokenValidatorInterface` impl) and, optionally, proxy the
  `/authorize` + `/token` + `/.well-known/oauth-authorization-server` flows to an
  upstream IdP (Entra, Keycloak, Auth0, Okta, league/oauth2-server) via the SDK's
  OAuthProxyMiddleware. Client registration (RFC 7591 DCR) via
  ClientRegistrationMiddleware — optional, off by default, for third-party
  clients that need to self-register.
- The REST/OpenAPI surface honors the same auth mode (API key or OAuth bearer; a
  single `authorization` resolver fronting both transports).
- **Scope mapping (D21): domain-level.** For OAuth, MCP scopes map to domains
  (`calendar:read`/`calendar:write`, `email:read`, `notify:send`, ...).
  Tool-level granularity is a later refinement. API-key mode = all-or-nothing.
- When OAuth is enabled, the health/status surface and the well-known metadata
  are public (they're needed for discovery); tool execution is protected.

### 9.3 IMAP client — decision

**DECIDED (D16): `DirectoryTree/ImapEngine` (`directorytree/imap-engine`).**

Criteria from boss: (1) IMAP IDLE support, (2) full test coverage, (3) Symfony
8 support would be amazing, (4) properly object-oriented.

**Why ImapEngine:**
- Pure PHP — **no `ext-imap`** (PHP 8.4 removed it from core; ddeboer requires
  it → dockable pain, out).
- `idle()` real-time monitoring (IMAP IDLE); fluent OO (`Mailbox` → `inbox()` →
  `messages()->get()`); 570+ stars; CI badge + PHPUnit + phpstan; active (719
  commits); Laravel News featured.
- API: `$inbox->idle(fn(Message $m) => ..., timeout: 300)` — exactly what we
  need for `email_wait_for_new` later. Blocking by design → run in worker/loop.

**Rejected:**
- `ddeboer/imap` — needs `ext-imap`, which PHP 8.4 unbundled. Out.
- `dg/imap` — zero-dependency pure PHP but explicitly **no IDLE, no OAuth, no
  SEARCH, no flags** (only `\Deleted`). Out for our needs.
- `webklex/php-imap` — works (IDLE + OAuth, no ext), **but pulls ~24 packages
  including Symfony/Laravel gravity** and is a heavier legacy wrapper. Fallback
  if ImapEngine proves insufficient.
- Symfony-bundle IMAP options: no maintained/active Symfony-8 IMAP bundle found
  (the one you spotted is 2y stale, no Symfony 8 support). Not a gap — we
  integrate ImapEngine with our own thin service.

**Integration notes:** wrap in `ImapClient` service (config from env, connection
lazy), expose only what tools need (mailboxes, messages, search, flags, idle).
Use `client_credentials`/OAuth where the provider supports it; basic auth for
others. Spike in Phase 0 to confirm IDLE + proxy env + test connectivity.

---

## 10. Phases

**Phase 0 — Skeleton (small, demonstrable)**
- Symfony app skeleton, `mcp/sdk` hello-world server over STDIO + HTTP.
- `ContextLoomServer` with 1 hand-rolled tool (`contextloom_health`), API-key auth.
- One `serve` command wiring MCP `/mcp` + health `/health` behind one router.
- `contextloom:validate`/`probe` commands stubbed.
- Spike answers §9: IMAP lib (ImapEngine), CalDAV lib (sabre), process execution
  approach, OAuth middleware, and **TaskWeaver's inbound surface** (webhook vs
  task-create vs long-poll) to pick the event-push transport (Open Q#2).

**Phase 1 — Registry core + OpenAPI (the API contract)**
- `RegistryEntry`/`InputSpec`/`OutputSpec`/`ProbeSpec` models + YAML loader +
  static validation + env `requires`.
- `ToolNameFactory` (tool name → MCP name + REST path per §4.5; no prefix).
- Internal `ToolExecutor` (block mode) + `ToolFactory` generating SDK tool
  signatures from YAML.
- **OpenAPI surface (full parity, D15):** every registry entry → one REST route
  + one OpenAPI operation. Routes derived from tool name; alias table for pretty
  paths. OpenAPI spec generated (e.g. `nelmio/api-doc-bundle` or custom generator)
  covering the whole registry; `GET /health` first.
- **`type: http` executor (D13):** HTTP tool runner — method, URL template
  (env+args), headers, auth modes (`api_key`/`bearer`/`basic`/none), params,
  body; response mapping to `OutputSpec`. **Port penny-track & vital-pulse as
  `type: http` entries** (their APIs are HTTP+token) — this is the first real
  consumer and validates the type end-to-end.
- Unit tests: load, condition filtering, arg validation, path generation, HTTP
  runner (with mocked PSR-18), port `mcp-server` unit-test ideas.

**Phase 2 — Providers (the generics)**
- Notify (ntfy, Discord) — simplest, ports directly from `mcp-server`.
- Calendar (iCal read, CalDAV read/write via sabre) — heaviest, carry over
  recurrence + timezone lessons.
- Email (IMAP read/write via `ImapClient` service + SMTP send). IMAP IDLE
  **worker per account** (`ImapIdleListener`, §5.3b/§9.3b) + event delivery to
  TaskWeaver via the chosen transport from Open Q#2.

**Phase 3 — Streaming (the pipe)**
- `ToolRun`/`Chunk` contract + `StreamReader` (line-buffered subprocess reading).
- `StreamSink` block sink → final `CallToolResult`; progress sink →
  `ClientGateway::progress()` (progressToken-gated, debounced).
- Process-group kill semantics + timeout + caps + cancellation on disconnect.
- If upstream `StreamableToolResult` has landed by then → third sink, no executor
  changes. (Open question #1 may push this to v1.1; progress sink is the v1
  deliverable.)

**Phase 4 — Probing & health**
- `ProbeSpec` implementations per protocol; background probe runner with
  interval/cache; `HealthRegistry`; `requires_healthy` opt-in;
  `contextloom_health` tool; OTel structured events (or stderr, per §9.1 —
  pattern now, exporter later).

**Phase 5 — Packaging & release**
- Dockerfile (multi-stage, amd64+arm64), compose, CI (GitHub Actions/Gitea
  Actions unify), public README, license, first release `v0.1.0`.

---

## 11. What we keep from `mcp-server` (as requirements, not code)

- Registry YAML + `requires` condition mini-language.
- Registry load resilience (bad file → skip, don't crash).
- `run_command` semantics for process entries: arg validation, typing, choices, hidden args,
  field_name aliasing, process-group kill on timeout.
- Provider discovery from env (`from_env` pattern), read-only vs editable split.
- Notify level fallback + Discord chunking + ntfy JSON publish + color mapping.
- CalDAV/ICS correctness details: recurring events, timezone offset preservation,
  dedupe by `(uid, start)`, task due-date clearing.
- One-namespace rule (here: MCP tool names + REST paths share the tool name; no `cmd_` prefix).
- Packaged tooling habits: `.env.example`, CI, multi-arch Docker, semver tags.
