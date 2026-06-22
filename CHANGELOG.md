# Changelog

All notable changes to `padosoft/askmydocs-connector-fabric` are documented here.
This file follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased]

## [1.1.0] — 2026-06-22

### Changed

- Adopt `padosoft/askmydocs-connector-base` v1.3 `resolveProjectKey()` for project binding. The connector now resolves the ingest project from the installation's explicit `project_key`, falling back to the host's `kb.ingest.default_project` config and finally the literal `default` — replacing the per-connector `connector-fabric` synthetic-project fallback. This enables multi-account / project-scoped adoption with a single source of truth across all connectors.

### Requires

- `padosoft/askmydocs-connector-base` `^1.3`.

## [1.0.0] - 2026-05-12

### Added

- Initial extraction from AskMyDocs v4.5/W4 inline connector framework.
- `FabricConnector` — API-key + workspace-id auth, cursor-paginated `/v2/notes` sync (full + incremental), health probe via `/v2/users/me`, deletion semantics deferred to the host until Fabric ships a deleted-state surface.
- OAuth2 path stubbed behind `connectors.providers.fabric.oauth_enabled` — flip to `true` when fabric.so ships OAuth2 GA upstream.
- `FabricServiceProvider` — auto-registered via Laravel package discovery; merges per-package config under `connectors.providers.fabric`, exposes `connector-fabric-config` + `connector-fabric-assets` publish tags.
- Composer `extra.askmydocs.connectors` discovery — the base package's `ConnectorRegistry` picks up `FabricConnector` automatically.
- Test matrix — PHP 8.3 / 8.4 / 8.5 × Laravel 12 / 13 on push and pull-request via GitHub Actions.
- Opt-in live test suite gated by `CONNECTOR_FABRIC_LIVE=1`.
