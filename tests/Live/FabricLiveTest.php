<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorFabric\Tests\Live;

use Illuminate\Support\Facades\Http;
use Padosoft\AskMyDocsConnectorFabric\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Live test — hits api.fabric.so when `CONNECTOR_FABRIC_LIVE=1` and a
 * valid `CONNECTOR_FABRIC_API_KEY` is present in the environment.
 *
 * Operators run this manually to validate credentials. CI does NOT run
 * this suite by default.
 */
final class FabricLiveTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('CONNECTOR_FABRIC_LIVE') !== '1') {
            $this->markTestSkipped('CONNECTOR_FABRIC_LIVE not set to 1 — live suite disabled.');
        }

        $key = getenv('CONNECTOR_FABRIC_API_KEY');
        if ($key === false || trim((string) $key) === '') {
            $this->markTestSkipped('Missing credential env var: CONNECTOR_FABRIC_API_KEY');
        }
    }

    #[Test]
    public function fetches_authenticated_user_via_real_api(): void
    {
        $headers = ['X-Api-Key' => (string) getenv('CONNECTOR_FABRIC_API_KEY')];
        $workspace = getenv('CONNECTOR_FABRIC_WORKSPACE_ID');
        if (is_string($workspace) && $workspace !== '') {
            $headers['X-Fabric-Workspace-Id'] = $workspace;
        }

        $response = Http::withHeaders($headers)
            ->acceptJson()
            ->timeout(10)
            ->get('https://api.fabric.so/v2/users/me');

        $this->assertTrue(
            $response->successful(),
            'Fabric /v2/users/me returned: '.$response->status(),
        );
    }
}
