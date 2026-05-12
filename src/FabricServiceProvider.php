<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorFabric;

use Illuminate\Support\ServiceProvider;

/**
 * Service provider for the Fabric (fabric.so) connector package.
 *
 * Merges the Fabric provider block into the host's `connectors.php`
 * config tree (under `providers.fabric`). Publishes both the config
 * fragment + the brand asset for hosts that want to customise either.
 *
 * Auto-registration into the connector registry happens at the base
 * package level via composer's `extra.askmydocs.connectors` discovery
 * — the entry is in this package's composer.json.
 */
class FabricServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/fabric.php', 'connectors.providers.fabric');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/fabric.php' => config_path('connectors-fabric.php'),
            ], 'connector-fabric-config');

            $this->publishes([
                __DIR__.'/../public/icons' => public_path('connectors'),
            ], 'connector-fabric-assets');
        }
    }
}
