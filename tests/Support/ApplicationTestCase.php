<?php

namespace Scout\Solr\Tests\Support;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\MySqlConnection;
use Laravel\Scout\EngineManager;
use PHPUnit\Framework\TestCase;
use Scout\Solr\ScoutSolrServiceProvider;
use Solarium\Client;

/**
 * Boots a minimal hand-rolled application: container, config, Scout's engine
 * manager, this package's service provider and a mysql-labelled sqlite
 * connection so the engine's orderQuery() sees a supported driver.
 */
abstract class ApplicationTestCase extends TestCase
{
    protected $app;
    protected $adapter;
    protected $config;

    protected function setUp(): void
    {
        $this->adapter = new FakeAdapter();
        $this->app = new Container();
        Container::setInstance($this->app);

        $this->config = new ConfigRepository([
            'solr' => [
                'enabled' => true,
                'meta_key' => 'meta',
                'core' => 'books',
                'endpoints' => [
                    'default' => ['host' => 'unused.invalid', 'core' => 'default'],
                    'books' => ['host' => 'unused.invalid', 'core' => 'books'],
                ],
            ],
            'scout' => ['driver' => 'solr', 'soft_delete' => false],
        ]);
        $this->app->instance('config', $this->config);
        $this->app->instance(Repository::class, $this->config);
        $this->app->singleton(EngineManager::class, function ($app) {
            return new EngineManager($app);
        });

        $provider = new ScoutSolrServiceProvider($this->app);
        $provider->register();
        $provider->boot();

        $this->app->make(Client::class)->setAdapter($this->adapter);

        $connection = new MySqlConnection(new \PDO('sqlite::memory:'), 'db', '', ['driver' => 'mysql']);
        $resolver = new ConnectionResolver(['default' => $connection]);
        $resolver->setDefaultConnection('default');
        Model::setConnectionResolver($resolver);
    }
}
