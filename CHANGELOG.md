# Changelog

All notable changes to `padosoft/askmydocs-connector-fabric` are documented here.
This file follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased]

## [1.0.0] - 2026-05-12

### Added

- Initial extraction from AskMyDocs v4.5/W4 inline connector framework.
- `FabricConnector` — API-key + workspace-id auth, cursor-paginated `/v2/notes` sync (full + incremental), health probe via `/v2/users/me`, deletion semantics deferred to the host until Fabric ships a deleted-state surface.
- OAuth2 path stubbed behind `connectors.providers.fabric.oauth_enabled` — flip to `true` when fabric.so ships OAuth2 GA upstream.
- `FabricServiceProvider` — auto-registered via Laravel package discovery; merges per-package config under `connectors.providers.fabric`, exposes `connector-fabric-config` + `connector-fabric-assets` publish tags.
- Composer `extra.askmydocs.connectors` discovery — the base package's `ConnectorRegistry` picks up `FabricConnector` automatically.
- Test matrix — PHP 8.3 / 8.4 / 8.5 × Laravel 12 / 13 on push and pull-request via GitHub Actions.
- Opt-in live test suite gated by `CONNECTOR_FABRIC_LIVE=1`.
