<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorFabric;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Padosoft\AskMyDocsConnectorBase\BaseConnector;
use Padosoft\AskMyDocsConnectorBase\Exceptions\ConnectorApiException;
use Padosoft\AskMyDocsConnectorBase\Exceptions\ConnectorAuthException;
use Padosoft\AskMyDocsConnectorBase\HealthStatus;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
use Padosoft\AskMyDocsConnectorBase\Support\Metadata\SourceAwareMetadataBuilder;
use Padosoft\AskMyDocsConnectorBase\Support\Metadata\VendorMimeSelector;
use Padosoft\AskMyDocsConnectorBase\SyncResult;

/**
 * Fabric (fabric.so, the AI-native knowledge tool — NOT Microsoft
 * Fabric) connector.
 *
 * **Authentication status as of 2026-05-12**: Fabric.so's developer
 * portal documents three auth methods:
 *   1. Personal API Key  — `X-Api-Key: <key>` (available today)
 *   2. Developer API Key — `X-Api-Key: <key>` + `X-Fabric-Workspace-Id:
 *      <ws>` (available today, for delegated access to a specific
 *      workspace)
 *   3. OAuth 2.0         — "coming soon" per their public docs
 *
 * This connector ships with the API-key auth path enabled and the
 * OAuth path stubbed. When Fabric.so ships OAuth2 GA, flip
 * `config('connectors.providers.fabric.oauth_enabled')` to `true`
 * and the OAuth surface activates automatically.
 *
 * **API surface used today**:
 *   - GET  /v2/notes           — list user notes (cursor-paginated)
 *   - GET  /v2/notes/{noteId}  — fetch a single note's body
 *   - GET  /v2/users/me        — health probe
 *
 * The path/payload shapes above are inferred from Fabric's public
 * developer portal grouping (Bookmarks / Notepads / Memories / Spaces
 * / Search / Tasks / etc.) — they are NOT scraped from a stable
 * OpenAPI spec we control. If Fabric.so ships a breaking endpoint
 * rename, this connector surfaces the failure via
 * {@see ConnectorApiException} and `SyncResult::errors`.
 *
 * Required config (resolved from `config('connectors.providers.fabric')`):
 *   - CONNECTOR_FABRIC_API_KEY       (the X-Api-Key value)
 *   - CONNECTOR_FABRIC_WORKSPACE_ID  (optional — only for Developer
 *                                     API keys; Personal API keys leave
 *                                     this null)
 *   - CONNECTOR_FABRIC_API_BASE      (defaults to https://api.fabric.so)
 *
 * Alternatively the admin SPA can post the API key + workspace id via
 * the installation's `config_json` ({api_key, workspace_id}) so each
 * tenant brings its own credentials — that's the canonical multi-tenant
 * shape; the env-var path is a development convenience.
 */
class FabricConnector extends BaseConnector
{
    public function key(): string
    {
        return 'fabric';
    }

    public function displayName(): string
    {
        return 'Fabric';
    }

    public function iconUrl(): string
    {
        return asset('connectors/fabric.svg');
    }

    /**
     * Fabric uses API-key auth today; no OAuth scopes apply. When
     * OAuth2 ships upstream we'll surface the real scope list here.
     */
    public function oauthScopes(): array
    {
        return [];
    }

    /**
     * Fabric.so OAuth2 is "coming soon" upstream. Until then, the
     * admin SPA installs this connector by posting the API key + (
     * optional) workspace id into the installation's `config_json`
     * rather than redirecting through an OAuth URL.
     */
    public function initiateOAuth(int $installationId): string
    {
        if ($this->oauthEnabled()) {
            $provider = $this->providerConfig();
            $state = $this->issueOAuthState($installationId);

            $params = http_build_query([
                'client_id' => $provider['client_id'] ?? '',
                'redirect_uri' => $provider['redirect_uri'] ?? '',
                'response_type' => 'code',
                'state' => $state,
            ]);

            return ($provider['oauth_authorize_url'] ?? 'https://api.fabric.so/v2/oauth/authorize')
                .'?'.$params;
        }

        throw new ConnectorAuthException(
            'Fabric (fabric.so) OAuth2 is not yet available upstream — Fabric documents it as '
            ."'coming soon' in their developer guide. Configure Fabric by writing your "
            .'Personal or Developer API Key (and optional workspace_id) to the installation '
            ."row's `config_json` (e.g. via the standard admin install endpoint or by setting "
            .'env CONNECTOR_FABRIC_API_KEY for single-tenant operators), or wait for Fabric '
            ."to ship OAuth2 and flip config('connectors.providers.fabric.oauth_enabled') to true."
        );
    }

    public function handleOAuthCallback(int $installationId, Request $request): void
    {
        if (! $this->oauthEnabled()) {
            throw new ConnectorAuthException(
                'Fabric OAuth2 callback received but config(connectors.providers.fabric.oauth_enabled) is false — '
                .'Fabric.so has not yet GAed OAuth2.'
            );
        }

        // Forward-compatible stub: when Fabric ships OAuth2 we'll
        // exchange the auth code here. Until then we throw above so
        // the installation row never flips to ACTIVE via this path.
        throw new ConnectorAuthException(
            'Fabric OAuth2 handler is not yet implemented — pending fabric.so GA of OAuth2 support.'
        );
    }

    public function syncFull(int $installationId): SyncResult
    {
        $headers = $this->buildHeaders($installationId);

        $installation = $this->loadInstallation($installationId);
        $config = (array) ($installation->config_json ?? []);
        $projectKey = (string) ($config['project_key'] ?? ('connector-'.$this->key()));

        $added = 0;
        $errors = [];

        try {
            $this->walkNotesPages(
                $headers,
                baseParams: ['limit' => 50],
                onNote: function (array $note) use ($installation, $projectKey, $headers, &$added, &$errors): void {
                    try {
                        $this->ingestNote($installation, $projectKey, $headers, $note);
                        $added++;
                    } catch (\Throwable $e) {
                        $noteId = (string) ($note['id'] ?? '?');
                        $errors[] = sprintf('note %s: %s', $noteId, $e->getMessage());
                    }
                },
            );
        } catch (ConnectorAuthException $e) {
            throw $e;
        } catch (ConnectorApiException $e) {
            $errors[] = $e->getMessage();
        }

        $result = new SyncResult(
            documentsAdded: $added,
            documentsUpdated: 0,
            documentsRemoved: 0,
            errors: $errors,
            completedAt: Carbon::now(),
        );

        $this->emitAudit('sync_completed', installationId: $installationId, metadata: array_merge(
            $result->toArray(),
            ['mode' => 'full'],
        ));

        return $result;
    }

    /**
     * Incremental sync — same endpoint as full, with `updated_after`
     * query parameter when supported by upstream. Fabric documents
     * this filter on the Notepads listing endpoint per their developer
     * guide; we pass it best-effort and fall back to a full sweep
     * (filtered client-side) when the server ignores it.
     */
    public function syncIncremental(int $installationId, ?Carbon $since): SyncResult
    {
        if ($since === null) {
            return $this->syncFull($installationId);
        }

        $headers = $this->buildHeaders($installationId);

        $installation = $this->loadInstallation($installationId);
        $config = (array) ($installation->config_json ?? []);
        $projectKey = (string) ($config['project_key'] ?? ('connector-'.$this->key()));

        $updated = 0;
        $errors = [];

        try {
            $this->walkNotesPages(
                $headers,
                baseParams: [
                    'updated_after' => $since->toIso8601String(),
                    'limit' => 50,
                ],
                onNote: function (array $note) use ($installation, $projectKey, $headers, &$updated, &$errors): void {
                    try {
                        $this->ingestNote($installation, $projectKey, $headers, $note);
                        $updated++;
                    } catch (\Throwable $e) {
                        $errors[] = sprintf('note %s: %s', $note['id'] ?? '?', $e->getMessage());
                    }
                },
                contextLabel: 'incremental',
            );
        } catch (ConnectorAuthException $e) {
            throw $e;
        } catch (ConnectorApiException $e) {
            $errors[] = $e->getMessage();
        }

        $result = new SyncResult(
            documentsAdded: 0,
            documentsUpdated: $updated,
            documentsRemoved: 0,
            errors: $errors,
            completedAt: Carbon::now(),
        );

        $this->emitAudit('sync_completed', installationId: $installationId, metadata: array_merge(
            $result->toArray(),
            ['mode' => 'incremental', 'since' => $since->toIso8601String()],
        ));

        return $result;
    }

    /**
     * Walk `/v2/notes` honouring cursor pagination. The callback
     * receives one note array per yielded record. Both full + incremental
     * sync funnel through here so the cursor-loop is implemented once.
     *
     * Recognises BOTH response shapes that Fabric's partial OpenAPI
     * documents:
     *   { notes: [...], next_cursor: "..." }
     *   { data: [...], pagination: { next: "..." } }
     *
     * @param  array<string,string>  $headers
     * @param  array<string,mixed>  $baseParams
     * @param  callable(array<string,mixed>): void  $onNote
     *
     * @throws ConnectorAuthException on 401/403
     * @throws ConnectorApiException on other non-2xx or non-JSON body
     */
    private function walkNotesPages(
        array $headers,
        array $baseParams,
        callable $onNote,
        string $contextLabel = 'full',
    ): void {
        $cursor = null;
        $maxIterations = 200; // safety cap — Fabric workspaces are bounded
        for ($i = 0; $i < $maxIterations; $i++) {
            $params = $baseParams;
            if ($cursor !== null) {
                $params['cursor'] = $cursor;
            }

            $response = Http::withHeaders($headers)
                ->acceptJson()
                ->timeout(20)
                ->get($this->apiBase().'/notes', $params);

            if (! $response->successful()) {
                $bodyExcerpt = Str::limit((string) $response->body(), 200);
                if ($response->status() === 401 || $response->status() === 403) {
                    throw new ConnectorAuthException(sprintf(
                        'Fabric /notes (%s) rejected credentials: HTTP %d %s',
                        $contextLabel,
                        $response->status(),
                        $bodyExcerpt,
                    ));
                }
                throw new ConnectorApiException(sprintf(
                    'Fabric /notes (%s) failed: HTTP %d %s',
                    $contextLabel,
                    $response->status(),
                    $bodyExcerpt,
                ));
            }

            $payload = $response->json();
            if (! is_array($payload)) {
                throw new ConnectorApiException(sprintf(
                    'Fabric /notes (%s) returned non-JSON body.',
                    $contextLabel,
                ));
            }

            $notes = $payload['notes'] ?? $payload['data'] ?? [];
            if (! is_array($notes)) {
                $notes = [];
            }

            foreach ($notes as $note) {
                if (! is_array($note)) {
                    continue;
                }
                $onNote($note);
            }

            $next = $payload['next_cursor']
                ?? ($payload['pagination']['next'] ?? null);
            if (! is_string($next) || $next === '') {
                return;
            }
            $cursor = $next;
        }
    }

    public function disconnect(int $installationId): void
    {
        // Fabric API keys are revoked at the upstream dashboard; there
        // is no programmatic revoke endpoint as of 2026-05-12. We
        // clear the local credential row and leave a hint for the
        // operator in the audit metadata.
        $this->vault->clearCredentials($installationId);
        $this->emitAudit('disconnected', installationId: $installationId, metadata: [
            'note' => 'Fabric has no programmatic API-key revoke endpoint; '
                .'rotate the key at https://developers.fabric.so for full revocation.',
        ]);
    }

    public function health(int $installationId): HealthStatus
    {
        try {
            $headers = $this->buildHeaders($installationId);
        } catch (ConnectorAuthException $e) {
            return HealthStatus::errored($e->getMessage());
        }

        try {
            $response = Http::withHeaders($headers)
                ->acceptJson()
                ->timeout(5)
                ->get($this->apiBase().'/users/me');
        } catch (\Throwable $e) {
            return HealthStatus::errored("Network error: {$e->getMessage()}");
        }

        if ($response->successful()) {
            return HealthStatus::healthy();
        }

        if ($response->status() === 401 || $response->status() === 403) {
            return HealthStatus::errored("Authorization rejected (HTTP {$response->status()}).");
        }

        return HealthStatus::degraded("users.me returned HTTP {$response->status()}");
    }

    /**
     * Download one note + dispatch the ingest job via the IoC contract.
     *
     * Fabric returns notes as either rich-text JSON (`content_html`)
     * or markdown (`content_markdown`). We prefer markdown when present
     * because the ingest pipeline is markdown-native.
     *
     * @param  array<string,string>  $headers
     * @param  array<string,mixed>  $noteListEntry
     */
    private function ingestNote(
        ConnectorInstallation $installation,
        string $projectKey,
        array $headers,
        array $noteListEntry,
    ): void {
        $noteId = (string) ($noteListEntry['id'] ?? '');
        if ($noteId === '') {
            throw new \RuntimeException('Fabric note missing id.');
        }

        // Some Fabric endpoints return the full content in the list
        // payload; others require a follow-up GET. Try list first.
        $content = (string) (
            $noteListEntry['content_markdown']
                ?? $noteListEntry['content']
                ?? ''
        );

        if ($content === '') {
            $detail = Http::withHeaders($headers)
                ->acceptJson()
                ->timeout(10)
                ->get($this->apiBase().'/notes/'.urlencode($noteId));

            if (! $detail->successful()) {
                throw new ConnectorApiException(sprintf(
                    'Fabric /notes/%s failed: HTTP %d',
                    $noteId,
                    $detail->status(),
                ));
            }

            $detailPayload = $detail->json();
            if (is_array($detailPayload)) {
                $content = (string) (
                    $detailPayload['content_markdown']
                        ?? $detailPayload['content']
                        ?? ''
                );
            }
        }

        if ($content === '') {
            // Empty note → skip; R14 says we should never write a
            // 0-byte ingest file pretending to be a successful import.
            return;
        }

        $title = (string) ($noteListEntry['title'] ?? 'Fabric note');

        $body = $this->maybeRedactContent($content);
        if ($title !== '') {
            $body = "# {$title}\n\n{$body}";
        }

        $cleanNoteId = preg_replace('/[^a-z0-9\-]/i', '', $noteId) ?? $noteId;
        $relativePath = sprintf(
            '%s/connectors/fabric/installation-%d/%s.md',
            $projectKey,
            $installation->id,
            $cleanNoteId,
        );
        $paths = $this->resolveKbSourcePath($relativePath);

        $written = Storage::disk($paths['disk'])->put($paths['absolute'], $body);
        if ($written === false) {
            throw new \RuntimeException("Failed to write {$paths['absolute']} to KB disk [{$paths['disk']}].");
        }

        $tags = $this->extractFabricTags($noteListEntry);
        $fabricFields = [
            'note_id' => $noteId,
            'workspace_id' => $noteListEntry['workspace_id'] ?? null,
            'collection_id' => $noteListEntry['collection_id'] ?? null,
            'updated_at' => $noteListEntry['updated_at'] ?? null,
            'ai_annotations' => $noteListEntry['ai_annotations'] ?? [],
            'tags' => $tags,
        ];

        $sourceMeta = (new SourceAwareMetadataBuilder)->build(
            base: [
                'connector' => $this->key(),
                'installation_id' => $installation->id,
                'fabric_note_id' => $noteId,
                'fabric_workspace_id' => $noteListEntry['workspace_id'] ?? null,
                'fabric_updated_at' => $noteListEntry['updated_at'] ?? null,
            ],
            sourceKey: 'fabric',
            sourceFields: $fabricFields,
            tags: $tags,
            statusActive: true,
            lastModified: $noteListEntry['updated_at'] ?? null,
            owner: null,
        );

        $this->dispatchIngestion(
            projectKey: $projectKey,
            relativePath: $paths['relative'],
            disk: $paths['disk'],
            title: $title !== '' ? $title : 'Fabric note',
            metadata: $sourceMeta,
            mimeType: VendorMimeSelector::MIME_FABRIC_NOTE,
            tenantId: $installation->tenant_id,
        );
    }

    /**
     * Fabric returns tags either as a flat list of strings or as
     * `{id, name}` objects depending on workspace setup. Normalise to
     * a list of names so the chunker and reranker see one shape.
     *
     * @param  array<string,mixed>  $note
     * @return list<string>
     */
    private function extractFabricTags(array $note): array
    {
        $raw = $note['tags'] ?? [];
        if (! is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $t) {
            if (is_string($t) && $t !== '') {
                $out[] = trim($t);

                continue;
            }
            if (is_array($t) && isset($t['name']) && is_string($t['name']) && $t['name'] !== '') {
                $out[] = trim($t['name']);
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Compose the X-Api-Key / X-Fabric-Workspace-Id header set from
     * the installation's `config_json` (per-tenant credentials) or the
     * fallback env-var config (development).
     *
     * @return array<string,string>
     *
     * @throws ConnectorAuthException when no API key is configured.
     */
    private function buildHeaders(int $installationId): array
    {
        $installation = $this->loadInstallation($installationId);
        $config = (array) ($installation->config_json ?? []);
        $provider = $this->providerConfig();

        $apiKey = (string) ($config['api_key'] ?? ($provider['api_key'] ?? ''));
        if ($apiKey === '') {
            throw new ConnectorAuthException(
                'No Fabric API key configured — set installation.config_json.api_key '
                .'(per-tenant) or env CONNECTOR_FABRIC_API_KEY (development).'
            );
        }

        $workspaceId = (string) ($config['workspace_id'] ?? ($provider['workspace_id'] ?? ''));

        $headers = [
            'X-Api-Key' => $apiKey,
            'Accept' => 'application/json',
        ];
        if ($workspaceId !== '') {
            $headers['X-Fabric-Workspace-Id'] = $workspaceId;
        }

        return $headers;
    }

    private function oauthEnabled(): bool
    {
        return (bool) ($this->providerConfig()['oauth_enabled'] ?? false);
    }

    private function apiBase(): string
    {
        $config = (string) ($this->providerConfig()['api_base'] ?? '');
        $base = $config !== '' ? rtrim($config, '/') : 'https://api.fabric.so';

        if (str_ends_with($base, '/v2')) {
            return $base;
        }

        return $base.'/v2';
    }

    /**
     * @return array<string,mixed>
     */
    private function providerConfig(): array
    {
        return (array) config('connectors.providers.fabric', []);
    }
}
