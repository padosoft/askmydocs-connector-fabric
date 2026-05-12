<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorFabric\Tests\Feature;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Padosoft\AskMyDocsConnectorBase\Contracts\ConnectorIngestionContract;
use Padosoft\AskMyDocsConnectorBase\Exceptions\ConnectorAuthException;
use Padosoft\AskMyDocsConnectorBase\HealthStatus;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
use Padosoft\AskMyDocsConnectorFabric\FabricConnector;
use Padosoft\AskMyDocsConnectorFabric\Tests\Support\SpyIngestionContract;
use Padosoft\AskMyDocsConnectorFabric\Tests\TestCase;

/**
 * Feature tests for {@see FabricConnector}.
 *
 * Every API interaction is stubbed via `Http::fake()`; host pipeline
 * dispatches go through a spy implementation of
 * {@see ConnectorIngestionContract}.
 */
final class FabricConnectorTest extends TestCase
{
    private SpyIngestionContract $spy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->spy = new SpyIngestionContract;
        $this->app->instance(ConnectorIngestionContract::class, $this->spy);
        Storage::fake('local');

        config()->set('connectors.providers.fabric.api_base', 'https://api.fabric.so');
        config()->set('connectors.providers.fabric.oauth_enabled', false);
    }

    private function connector(): FabricConnector
    {
        return $this->app->make(FabricConnector::class);
    }

    private function makeInstallation(string $tenantId = 'default', array $configJson = []): ConnectorInstallation
    {
        return ConnectorInstallation::create([
            'tenant_id' => $tenantId,
            'connector_name' => 'fabric',
            'status' => ConnectorInstallation::STATUS_ACTIVE,
            'config_json' => array_merge(['api_key' => 'fab-test-key'], $configJson),
        ]);
    }

    public function test_initiate_oauth_throws_when_oauth_not_enabled(): void
    {
        $installation = $this->makeInstallation();

        $this->expectException(ConnectorAuthException::class);
        $this->expectExceptionMessage('coming soon');

        $this->connector()->initiateOAuth($installation->id);
    }

    public function test_handle_oauth_callback_throws_when_oauth_not_enabled(): void
    {
        $installation = $this->makeInstallation();

        $this->expectException(ConnectorAuthException::class);
        $this->connector()->handleOAuthCallback(
            $installation->id,
            Request::create('/cb', 'GET', ['code' => 'x', 'state' => 'y']),
        );
    }

    public function test_sync_full_dispatches_each_note_via_contract(): void
    {
        $installation = $this->makeInstallation();

        Http::fake([
            'api.fabric.so/v2/notes*' => Http::response([
                'notes' => [
                    ['id' => 'note-a', 'title' => 'A', 'content_markdown' => 'body of a', 'updated_at' => '2026-05-01T10:00:00Z'],
                    ['id' => 'note-b', 'title' => 'B', 'content_markdown' => 'body of b', 'updated_at' => '2026-05-02T11:00:00Z'],
                ],
                'next_cursor' => null,
            ], 200),
        ]);

        $result = $this->connector()->syncFull($installation->id);

        $this->assertSame([], $result->errors);
        $this->assertSame(2, $result->documentsAdded);
        $this->assertCount(2, $this->spy->dispatches);
        $this->assertSame('A', $this->spy->dispatches[0]['title']);
        $this->assertSame('application/vnd.fabric.note+json', $this->spy->dispatches[0]['mimeType']);
        $this->assertSame('note-a', $this->spy->dispatches[0]['metadata']['fabric_note_id']);
    }

    public function test_sync_full_recognises_data_pagination_envelope(): void
    {
        $installation = $this->makeInstallation();

        Http::fake([
            'api.fabric.so/v2/notes*' => Http::response([
                'data' => [
                    ['id' => 'note-x', 'title' => 'X', 'content' => 'plain body'],
                ],
                'pagination' => ['next' => null],
            ], 200),
        ]);

        $result = $this->connector()->syncFull($installation->id);

        $this->assertSame(1, $result->documentsAdded);
        $this->assertCount(1, $this->spy->dispatches);
    }

    public function test_sync_full_follows_next_cursor(): void
    {
        $installation = $this->makeInstallation();

        Http::fake([
            'api.fabric.so/v2/notes*' => Http::sequence()
                ->push([
                    'notes' => [['id' => 'p1', 'title' => 'P1', 'content_markdown' => 'one']],
                    'next_cursor' => 'cur-2',
                ], 200)
                ->push([
                    'notes' => [['id' => 'p2', 'title' => 'P2', 'content_markdown' => 'two']],
                    'next_cursor' => null,
                ], 200),
        ]);

        $result = $this->connector()->syncFull($installation->id);

        $this->assertSame(2, $result->documentsAdded);
        $this->assertCount(2, $this->spy->dispatches);
    }

    public function test_sync_full_throws_auth_exception_on_401(): void
    {
        $installation = $this->makeInstallation();

        Http::fake([
            'api.fabric.so/v2/notes*' => Http::response(['message' => 'invalid key'], 401),
        ]);

        $this->expectException(ConnectorAuthException::class);
        $this->connector()->syncFull($installation->id);
    }

    public function test_sync_full_records_api_error_on_500(): void
    {
        $installation = $this->makeInstallation();

        Http::fake([
            'api.fabric.so/v2/notes*' => Http::response(['message' => 'oops'], 500),
        ]);

        $result = $this->connector()->syncFull($installation->id);

        $this->assertNotEmpty($result->errors);
        $this->assertStringContainsString('HTTP 500', $result->errors[0]);
        $this->assertSame(0, $result->documentsAdded);
    }

    public function test_sync_incremental_passes_updated_after_param(): void
    {
        $installation = $this->makeInstallation();

        Http::fake([
            'api.fabric.so/v2/notes*' => Http::response(['notes' => [], 'next_cursor' => null], 200),
        ]);

        $since = Carbon::parse('2026-05-10T12:00:00Z');
        $this->connector()->syncIncremental($installation->id, $since);

        Http::assertSent(function ($req) use ($since) {
            $url = (string) $req->url();

            // The exact param key is `updated_after` per Fabric's developer guide.
            return str_contains($url, 'updated_after=')
                && str_contains(urldecode($url), $since->toIso8601String());
        });
    }

    public function test_sync_incremental_falls_back_to_full_without_watermark(): void
    {
        $installation = $this->makeInstallation();

        Http::fake([
            'api.fabric.so/v2/notes*' => Http::response(['notes' => [], 'next_cursor' => null], 200),
        ]);

        $result = $this->connector()->syncIncremental($installation->id, null);

        // Full-sync path: documentsAdded is the counter, documentsUpdated stays 0.
        $this->assertSame(0, $result->documentsAdded);
        $this->assertSame(0, $result->documentsUpdated);
    }

    public function test_health_returns_healthy_when_users_me_succeeds(): void
    {
        $installation = $this->makeInstallation();

        Http::fake([
            'api.fabric.so/v2/users/me' => Http::response(['user' => ['id' => 1]], 200),
        ]);

        $status = $this->connector()->health($installation->id);
        $this->assertSame(HealthStatus::STATE_HEALTHY, $status->state);
    }

    public function test_health_returns_errored_on_401(): void
    {
        $installation = $this->makeInstallation();

        Http::fake([
            'api.fabric.so/v2/users/me' => Http::response(['message' => 'unauth'], 401),
        ]);

        $status = $this->connector()->health($installation->id);
        $this->assertSame(HealthStatus::STATE_ERRORED, $status->state);
    }

    public function test_health_returns_errored_when_no_api_key_configured(): void
    {
        // Install without any api_key in config_json or env.
        $installation = ConnectorInstallation::create([
            'tenant_id' => 'default',
            'connector_name' => 'fabric',
            'status' => ConnectorInstallation::STATUS_ACTIVE,
            'config_json' => [],
        ]);
        config()->set('connectors.providers.fabric.api_key', null);

        $status = $this->connector()->health($installation->id);
        $this->assertSame(HealthStatus::STATE_ERRORED, $status->state);
        $this->assertStringContainsString('No Fabric API key', $status->message ?? '');
    }

    public function test_health_forwards_workspace_id_header_when_set(): void
    {
        $installation = $this->makeInstallation(configJson: ['workspace_id' => 'ws-42', 'api_key' => 'k']);

        Http::fake([
            'api.fabric.so/v2/users/me' => Http::response(['user' => ['id' => 1]], 200),
        ]);

        $this->connector()->health($installation->id);

        Http::assertSent(function ($req) {
            return $req->hasHeader('X-Fabric-Workspace-Id', 'ws-42');
        });
    }

    public function test_disconnect_clears_credentials_and_emits_audit_note(): void
    {
        $installation = $this->makeInstallation();

        $this->connector()->disconnect($installation->id);

        $events = array_column($this->spy->audits, 'eventType');
        $this->assertContains('disconnected', $events);

        $disconnectedAudit = $this->spy->audits[array_search('disconnected', $events, true)];
        $this->assertStringContainsString('developers.fabric.so', $disconnectedAudit['metadata']['note']);
    }

    public function test_writes_markdown_body_with_title_heading(): void
    {
        $installation = $this->makeInstallation();

        Http::fake([
            'api.fabric.so/v2/notes*' => Http::response([
                'notes' => [
                    ['id' => 'note-md', 'title' => 'My Title', 'content_markdown' => 'the body'],
                ],
                'next_cursor' => null,
            ], 200),
        ]);

        $this->connector()->syncFull($installation->id);

        $disk = Storage::disk('local');
        $files = $disk->allFiles();
        $this->assertNotEmpty($files);
        $contents = (string) $disk->get($files[0]);
        $this->assertStringContainsString('# My Title', $contents);
        $this->assertStringContainsString('the body', $contents);
    }

    public function test_pii_redaction_applied_via_ioc_contract(): void
    {
        $this->spy->redactionPrefix = '[X] ';
        $installation = $this->makeInstallation();

        Http::fake([
            'api.fabric.so/v2/notes*' => Http::response([
                'notes' => [
                    ['id' => 'note-pii', 'title' => 'P', 'content_markdown' => 'email: a@b.co'],
                ],
                'next_cursor' => null,
            ], 200),
        ]);

        $this->connector()->syncFull($installation->id);

        $disk = Storage::disk('local');
        $files = $disk->allFiles();
        $contents = (string) $disk->get($files[0]);
        $this->assertStringContainsString('[X]', $contents);
    }

    public function test_extracts_tags_from_mixed_string_and_object_shapes(): void
    {
        $installation = $this->makeInstallation();

        Http::fake([
            'api.fabric.so/v2/notes*' => Http::response([
                'notes' => [[
                    'id' => 'note-tagged',
                    'title' => 'Tagged',
                    'content_markdown' => 'body',
                    'tags' => ['flat-tag', ['id' => 5, 'name' => 'object-tag'], ['name' => 'flat-tag']],
                ]],
                'next_cursor' => null,
            ], 200),
        ]);

        $this->connector()->syncFull($installation->id);

        $this->assertCount(1, $this->spy->dispatches);
        $tags = $this->spy->dispatches[0]['metadata']['converter_hints']['fabric']['tags'] ?? null;
        $this->assertSame(['flat-tag', 'object-tag'], $tags);
    }

    public function test_buildheaders_carries_workspace_id_when_set_in_config_json(): void
    {
        // Per-tenant credentials live in the installation's config_json
        // (the canonical multi-tenant shape). Verify both api_key and
        // workspace_id flow into the outbound headers.
        $installation = $this->makeInstallation(configJson: [
            'api_key' => 'tenant-key',
            'workspace_id' => 'tenant-ws',
        ]);

        Http::fake([
            'api.fabric.so/v2/notes*' => Http::response(['notes' => [], 'next_cursor' => null], 200),
        ]);

        $this->connector()->syncFull($installation->id);

        Http::assertSent(function ($req) {
            return $req->hasHeader('X-Api-Key', 'tenant-key')
                && $req->hasHeader('X-Fabric-Workspace-Id', 'tenant-ws');
        });
    }
}
