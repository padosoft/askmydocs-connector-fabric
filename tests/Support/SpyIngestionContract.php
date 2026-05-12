<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorFabric\Tests\Support;

use Padosoft\AskMyDocsConnectorBase\Contracts\ConnectorIngestionContract;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;

/**
 * Test double — captures every interaction with the IoC contract so
 * Feature tests can assert what the connector handed off without
 * needing a real host pipeline.
 */
final class SpyIngestionContract implements ConnectorIngestionContract
{
    /** @var list<array<string,mixed>> */
    public array $dispatches = [];

    /** @var list<array<string,mixed>> */
    public array $audits = [];

    /** @var list<array<string,mixed>> */
    public array $deletions = [];

    public string $redactionPrefix = '';

    /** @var array<string,string> remoteId → tenantId so the spy can pretend a row exists. */
    public array $remoteIdsThatMatch = [];

    public function dispatchIngestion(
        string $projectKey,
        string $relativePath,
        string $disk,
        string $title,
        array $metadata,
        string $mimeType,
        string $tenantId,
    ): void {
        $this->dispatches[] = compact(
            'projectKey',
            'relativePath',
            'disk',
            'title',
            'metadata',
            'mimeType',
            'tenantId',
        );
    }

    public function resolveKbSourcePath(string $relativePath): array
    {
        $normalised = ltrim(str_replace('\\', '/', $relativePath), '/');

        return [
            'relative' => $normalised,
            'absolute' => $normalised,
            'disk' => 'local',
        ];
    }

    public function redactContent(string $content): string
    {
        return $this->redactionPrefix.$content;
    }

    public function emitAudit(
        string $connectorKey,
        string $eventType,
        ?int $installationId = null,
        ?array $metadata = null,
    ): void {
        $this->audits[] = compact('connectorKey', 'eventType', 'installationId', 'metadata');
    }

    public function softDeleteByRemoteId(
        ConnectorInstallation $installation,
        string $metadataKey,
        string $remoteId,
    ): bool {
        $this->deletions[] = [
            'installation_id' => $installation->id,
            'tenant_id' => $installation->tenant_id,
            'metadata_key' => $metadataKey,
            'remote_id' => $remoteId,
        ];

        return isset($this->remoteIdsThatMatch[$remoteId]);
    }
}
