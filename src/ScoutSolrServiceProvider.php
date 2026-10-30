<?php

namespace Scout\Solr;

use Illuminate\Support\ServiceProvider;
use Laravel\Scout\EngineManager;
use Scout\Solr\Engines\SolrEngine;
use Solarium\Client;
use Solarium\Core\Client\Adapter\Curl;
use Solarium\Core\Client\Adapter\Http;
use Symfony\Component\EventDispatcher\EventDispatcher;

class ScoutSolrServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // extend the Scout engine manager
        resolve(EngineManager::class)->extend('solr', function () {
            return resolve(SolrEngine::class);
        });
        // publish the solr.php config file when the user publishes this provider
        $this->publishes([
            __DIR__.'/../config/scout-solr.php' => config_path('solr.php'),
        ], 'config');
    }

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/scout-solr.php', 'solr');
        // bind the solarium client as a singleton so we can DI
        $this->app->singleton(Client::class, function ($app) {
            // the curl adapter performs better, but is not always available
            $adapter = extension_loaded('curl') ? new Curl() : new Http();

            return new Client(
                $adapter,
                new EventDispatcher(),
                ['endpoint' => $app['config']->get('solr.endpoints', [])]
            );
        });
    }
}
