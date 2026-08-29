<h1 align="center">askmydocs-connector-fabric</h1>

<p align="center">
  <strong>Fabric (fabric.so) connector for AskMyDocs — API-key + workspace-id auth, cursor-paginated note sync. OAuth2 path stubbed pending fabric.so GA.</strong><br/>
  Drop-in Laravel package. <code>composer require</code> it from any AskMyDocs install and the Fabric connector appears in the admin UI on the next request.
</p>

<p align="center">
  <a href="https://github.com/padosoft/askmydocs-connector-fabric/actions/workflows/tests.yml"><img alt="CI status" src="https://img.shields.io/github/actions/workflow/status/padosoft/askmydocs-connector-fabric/tests.yml?branch=main&label=tests"></a>
  <a href="https://packagist.org/packages/padosoft/askmydocs-connector-fabric"><img alt="Packagist version" src="https://img.shields.io/packagist/v/padosoft/askmydocs-connector-fabric.svg?label=packagist"></a>
  <a href="https://packagist.org/packages/padosoft/askmydocs-connector-fabric"><img alt="Total downloads" src="https://img.shields.io/packagist/dt/padosoft/askmydocs-connector-fabric.svg?label=downloads"></a>
  <a href="LICENSE"><img alt="License" src="https://img.shields.io/badge/license-Apache--2.0-blue.svg"></a>
  <img alt="PHP version" src="https://img.shields.io/badge/php-8.3%20%7C%208.4%20%7C%208.5-777BB4">
  <img alt="Laravel version" src="https://img.shields.io/badge/laravel-12%20%7C%2013-FF2D20">
</p>

---

> ⚠️ **Note**: this is the Fabric.so connector — the AI-native knowledge tool at <https://fabric.so>. It is **not** the Microsoft Fabric data-platform connector.

## Table of contents

1. [Why this package](#why-this-package)
2. [Features](#features)
3. [AI vibe-coding pack included](#-ai-vibe-coding-pack-included)
4. [Architecture at a glance](#architecture-at-a-glance)
5. [Installation](#installation)
6. [Credential setup (junior-proof, step by step)](#credential-setup-junior-proof-step-by-step)
7. [Activation inside AskMyDocs](#activation-inside-askmydocs)
8. [Sync semantics](#sync-semantics)
9. [What gets ingested](#what-gets-ingested)
10. [Testing](#testing)
11. [Live testsuite](#live-testsuite)
12. [Troubleshooting](#troubleshooting)
13. [Roadmap](#roadmap)
14. [License](#license)

---

## Why this package

[AskMyDocs](https://github.com/lopadova/AskMyDocs) is an enterprise-grade RAG + canonical knowledge compilation system. Out of the box it ingests markdown from disk, the chat UI, an HTTP API, and a Git-driven workflow — but a lot of personal-knowledge work increasingly lives in Fabric.so.

This package is the smallest possible surface for shipping that integration:

- A `FabricConnector` that implements `Padosoft\AskMyDocsConnectorBase\ConnectorInterface`.
- API-key + optional workspace-id auth — works with both Personal API Keys and Developer API Keys today.
- Cursor-paginated note sync recognising both `next_cursor` and `pagination.next` response envelopes Fabric documents.
- OAuth2 surface stubbed behind a config flag — when fabric.so ships OAuth2 GA, flip `oauth_enabled = true` and the OAuth path activates automatically.
- A composer.json that auto-registers via `extra.askmydocs.connectors`. Zero edits to your host app's config required.

> **`composer require padosoft/askmydocs-connector-fabric`. Done.**

## Features

- 🔌 **Zero-config installation** — composer-extra discovery auto-registers the connector at boot.
- 🔑 **API-key auth today, OAuth2-ready tomorrow** — supports both Personal + Developer API Keys with optional `X-Fabric-Workspace-Id` header. OAuth2 path stubbed; flip a config flag when fabric.so GAs OAuth.
- ♻️ **Incremental sync** — Fabric's `updated_after` filter (when honoured by the listing endpoint); falls back to full sweep client-side.
- 🚦 **Cursor pagination** — walks `next_cursor` AND `pagination.next` envelopes per Fabric's partial OpenAPI.
- 🧠 **Source-aware metadata** — note id, workspace id, collection id, AI annotations, tags all surface to the host's reranker via `SourceAwareMetadataBuilder`.
- 🚦 **Failure-loud exception taxonomy** — 401 / 403 → `ConnectorAuthException`, other non-2xx → `ConnectorApiException`. Non-JSON bodies and missing credentials raise typed errors instead of silent skips.
- 🏢 **Per-tenant isolated** — every credential read and ingestion dispatch is scoped to the active `TenantContext`. Per-tenant credentials live in the installation's `config_json`.
- 🧪 **Test-friendly** — `Http::fake()` feature tests against the spy ingestion contract; opt-in live test that hits real `api.fabric.so` when `CONNECTOR_FABRIC_LIVE=1`.

- **Provenance declaration** — implements `DeclaresProvenance` (connector-base ^1.5), labelling ingested content `TrustedInternal`: a Fabric workspace the organisation administers, so whoever wrote a document had to be granted the ability to write it. A statement about *authorship*, not about correctness — see the IMAP connector for the contrasting case.

## 🚀 AI vibe-coding pack included

This package was built with a vibe-coding pack of Claude Code skills and rules (`.claude/` directory in the parent AskMyDocs repo) that codify the architectural invariants — the IoC contract that keeps this package standalone-agnostic, Fabric's dual-envelope pagination quirk, the failure-loud exception taxonomy, the API-key vs OAuth dual surface.

If you're using Claude Code to fork or extend this package, point the agent at the parent repo's `.claude/` pack and it stays inside the invariants automatically. No tribal-knowledge drift.

## Architecture at a glance

```
                ┌──────────────────────────────┐
Composer        │ padosoft/askmydocs-          │
require ───────▶│ connector-fabric             │
                │ (this package)               │
                └────────────┬─────────────────┘
                             │
                             │ auto-registered via composer
                             │ extra.askmydocs.connectors
                             ▼
                ┌──────────────────────────────┐
                │ padosoft/askmydocs-connector-│
                │ base v1.1.1+                 │
                │ ConnectorRegistry            │
                └────────────┬─────────────────┘
                             │
                             │ resolves FabricConnector
                             ▼
                ┌──────────────────────────────┐
                │ FabricConnector::syncFull    │
                │  • GET /v2/notes (cursor)    │
                │  • GET /v2/notes/{id}        │
                │  • SourceAwareMetadata       │
                └────────────┬─────────────────┘
                             │
                             │ ConnectorIngestionContract
                             │ (IoC bridge — host implements)
                             ▼
                ┌──────────────────────────────┐
                │ Host app (AskMyDocs):        │
                │  • Storage::put → KB disk    │
                │  • IngestDocumentJob         │
                │  • kb_canonical_audit row    │
                │  • PII redactor at boundary  │
                └──────────────────────────────┘
```

The IoC bridge is the key design decision: this package never imports any host class. It dispatches every host-side concern through `Padosoft\AskMyDocsConnectorBase\Contracts\ConnectorIngestionContract`. The host binds its own implementation in a service provider; this package stays standalone-agnostic.

## Installation

```bash
composer require padosoft/askmydocs-connector-fabric
```

The package follows Laravel's auto-discovery convention. After install:

```bash
php artisan vendor:publish --tag=connector-fabric-config   # optional — for env-var overrides
php artisan vendor:publish --tag=connector-fabric-assets   # optional — copies fabric.svg to public/connectors
```

The `connector-base` migrations ship in the parent package and auto-load via its service provider; no extra `migrate` step is needed.

## Credential setup (junior-proof, step by step)

Fabric.so uses API-key auth today. The flow is much shorter than OAuth-based connectors — there's no callback URL to register and no "share with integration" step.

### 1. Sign in to Fabric.so

1. Open <https://fabric.so> in your browser. Create an account or sign in.
2. From your workspace home, click your avatar (top-right) → **Settings** → **Developers** (or visit <https://developers.fabric.so>).

### 2. Generate an API key

Pick the key type that matches your use case:

- **Personal API Key** — grants access to YOUR notes. Use this for single-tenant deployments or when an admin is ingesting their own knowledge base.
- **Developer API Key** — grants delegated access to a specific workspace (requires a workspace id). Use this for multi-tenant deployments where each tenant brings their own workspace.

Click **"Generate API Key"** → label it `AskMyDocs ingest` → confirm. Copy the key value (it will only display once).

### 3. (Optional) Capture the workspace id

If you generated a **Developer API Key**, also copy the **Workspace ID** displayed alongside it. Personal API Keys do not need this field.

### 4. Write credentials to `.env`

In your AskMyDocs host app's `.env`:

```dotenv
CONNECTOR_FABRIC_API_KEY=<your-personal-or-developer-api-key>
# Only needed for Developer API Keys:
# CONNECTOR_FABRIC_WORKSPACE_ID=<your-workspace-id>

# Override only if Fabric ships a regional API base; defaults to https://api.fabric.so:
# CONNECTOR_FABRIC_API_BASE=https://api.fabric.so
```

For multi-tenant deployments, prefer the admin UI's **Install** flow — it lets each tenant supply their own API key in the installation's `config_json` instead of leaking a single env-level key across tenants.

### 5. Verify (curl)

```bash
curl -s https://api.fabric.so/v2/users/me \
  -H "X-Api-Key: <your-api-key>" \
  -H "Accept: application/json"
```

Expected: `200 OK` with a JSON user payload. If you see `401 unauthorized` → re-copy the key from step 2 (it might have been truncated). If you see `403 forbidden` → the key is for a different workspace; pass the right `X-Fabric-Workspace-Id`.

### 6. Common errors

- `401 unauthorized` — Key invalid, revoked, or copy-paste error (Fabric keys are long; verify no whitespace was clipped).
- `403 forbidden` — Developer API key without a matching workspace-id header. Add `CONNECTOR_FABRIC_WORKSPACE_ID`.
- `429 rate_limited` — Fabric enforces per-key rate limits; the connector retries with exponential back-off via Laravel's HTTP client defaults.

## Activation inside AskMyDocs

After `composer require` + the env vars above:

1. Run the host app's admin UI.
2. Navigate to **Settings → Connectors**.
3. The **Fabric** card appears with an **Install** button.
4. Click **Install** → the admin prompts for the API key (and optional workspace id) → status flips to `active`.
5. The first full sync fires within the cadence window. To trigger immediately, click **Sync now**.

## Sync semantics

- **Full sync** — `GET /v2/notes?limit=50`, walks `next_cursor` / `pagination.next` until empty. Each note is fetched (list payload first, follow-up `GET /v2/notes/{id}` when the list doesn't carry the body), `content_markdown` preferred over `content`, dispatched via the IoC contract. Safety cap at 200 pagination iterations.
- **Incremental sync** — same listing with `updated_after=<ISO8601>` query parameter. When the watermark is `null`, falls back to a full sync.
- **Deletion reconciliation** — Fabric's API does NOT yet expose a deleted-state surface on the listing endpoint as of v2026-05-12. When upstream adds one, the connector will route deletions through `ConnectorIngestionContract::softDeleteByRemoteId('fabric_note_id', ...)`. Until then, deletions are not automatically reconciled — operators run a full sync to detect orphans.
- **Disconnect** — clears local credentials only. Fabric has no programmatic API-key revoke endpoint; operators rotate the key at <https://developers.fabric.so> for full revocation. The audit metadata carries that hint.

## What gets ingested

For every Fabric note the integration can see:

- **Markdown body** — `content_markdown` when present, otherwise `content` plain-text fallback. Title prepended as `# Title` so the host's chunker indexes it.
- **Frontmatter / metadata** captured under `metadata.converter_hints.fabric`:
  - `note_id`, `workspace_id`, `collection_id`
  - `updated_at` (ISO timestamp)
  - `ai_annotations` (the Fabric-generated AI summaries / extractions)
  - `tags` — normalised to a flat list of names regardless of upstream shape (string or object).
- **`_derived` reranker signals** under `metadata.converter_hints._derived`:
  - `search_tags`, `recency_bucket`

The synthetic MIME `application/vnd.fabric.note+json` routes the document to the host's Fabric-aware chunker when one is installed.

## Testing

```bash
composer install
vendor/bin/phpunit
```

The suite has two flavours:

| Suite     | What it covers                                                                                  | Network |
|-----------|-------------------------------------------------------------------------------------------------|---------|
| Feature   | `FabricConnector` against `Http::fake()` and the spy ingestion contract — ~18 scenarios incl. pagination envelopes, auth failures, empty content, tag normalisation, OAuth-stub error. | None    |
| Live      | Opt-in — actually hits api.fabric.so. Skipped unless `CONNECTOR_FABRIC_LIVE=1`.                  | Real    |

CI runs Default (Feature) against PHP 8.3 / 8.4 / 8.5 × Laravel 12 / 13.

## Live testsuite

```bash
export CONNECTOR_FABRIC_LIVE=1
export CONNECTOR_FABRIC_API_KEY=<your-api-key>
# Optional — only for Developer API Keys:
# export CONNECTOR_FABRIC_WORKSPACE_ID=<your-workspace-id>
vendor/bin/phpunit --testsuite=Live
```

This calls `/v2/users/me` once to validate credentials. The `/v2/notes` listing surface is exercised by the Feature suite with `Http::fake()`.

## Troubleshooting

| Symptom | Likely cause | Fix |
|---|---|---|
| `No Fabric API key configured` | No `api_key` in installation's `config_json` or `CONNECTOR_FABRIC_API_KEY` env | Re-install through the admin UI OR set the env var |
| `401 unauthorized` during sync | Key revoked or invalid | Rotate at <https://developers.fabric.so> and re-install |
| `403 forbidden` | Developer API key without workspace-id header | Set `CONNECTOR_FABRIC_WORKSPACE_ID` or pass `workspace_id` in `config_json` |
| Sync truncates with `maxIterations` warning | Workspace has >10 000 notes (200 pages × 50 limit) | Raise the cap in a future release or trigger another sync |
| OAuth install hangs | `oauth_enabled=true` but Fabric hasn't shipped OAuth GA | Set `CONNECTOR_FABRIC_OAUTH_ENABLED=false` until upstream GA |
| Deletions not appearing | Fabric API doesn't yet surface deleted-state on list endpoints | Run a periodic full sync (re-ingest detects orphans the host's prune job then sweeps) |

## Roadmap

- **OAuth2 surface activation** — flip `oauth_enabled` to default true when fabric.so ships OAuth2 GA upstream.
- **Deletion reconciliation** — wire `softDeleteByRemoteId('fabric_note_id', ...)` once Fabric exposes a deleted-state on the list endpoint.
- **Bookmarks / Memories / Spaces** — today this connector only walks `/v2/notes`. Future versions add the other Fabric resource types behind a config flag.

## License

Apache-2.0 — see [LICENSE](LICENSE).

Built and maintained by [Padosoft](https://padosoft.com/). Part of the AskMyDocs connector ecosystem.
