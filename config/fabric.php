<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Fabric (fabric.so) connector configuration
|--------------------------------------------------------------------------
|
| Provider settings for `padosoft/askmydocs-connector-fabric`.
|
| The base package merges this block under
| `config('connectors.providers.fabric')`, so concrete connector code
| reads its config via the standard
| `config('connectors.providers.fabric.<key>')` path.
|
| Fabric.so uses API-key auth today; OAuth2 is "coming soon" upstream
| (see README). Operators may also write the api_key / workspace_id
| directly into the installation's `config_json` for per-tenant
| credentials.
|
*/

return [
    // Personal or Developer API key. For multi-tenant deployments
    // store the per-tenant key in the installation's config_json
    // instead.
    'api_key' => env('CONNECTOR_FABRIC_API_KEY'),

    // Required only for Developer API keys, which grant delegated
    // access to a specific workspace.
    'workspace_id' => env('CONNECTOR_FABRIC_WORKSPACE_ID'),

    'api_base' => env('CONNECTOR_FABRIC_API_BASE', 'https://api.fabric.so'),

    // Flip to true when fabric.so ships OAuth2 GA upstream.
    'oauth_enabled' => env('CONNECTOR_FABRIC_OAUTH_ENABLED', false),

    // Reserved for the OAuth path; left null while OAuth is "coming soon".
    'client_id' => env('CONNECTOR_FABRIC_CLIENT_ID'),
    'client_secret' => env('CONNECTOR_FABRIC_CLIENT_SECRET'),
    'redirect_uri' => env(
        'CONNECTOR_FABRIC_REDIRECT_URI',
        env('APP_URL', 'http://localhost').'/api/admin/connectors/fabric/oauth/callback'
    ),
    'oauth_authorize_url' => env(
        'CONNECTOR_FABRIC_OAUTH_AUTHORIZE_URL',
        'https://api.fabric.so/v2/oauth/authorize'
    ),
];
