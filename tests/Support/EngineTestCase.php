<?php

namespace Scout\Solr\Tests\Support;

use Illuminate\Config\Repository;
use PHPUnit\Framework\TestCase;
use Scout\Solr\Builder;
use Scout\Solr\Engines\SolrEngine;
use Solarium\Client;

abstract class EngineTestCase extends TestCase
{
    protected $adapter;
    protected $client;
    protected $engine;

    protected function setUp(): void
    {
        $this->adapter = new FakeAdapter();
        $this->client = new Client(['endpoint' => [
            'default' => ['host' => 'unused.invalid', 'core' => 'default'],
            'books' => ['host' => 'unused.invalid', 'core' => 'books'],
        ]]);
        $this->client->setAdapter($this->adapter);
        $this->engine = new SolrEngine($this->client, new Repository());
    }

    protected function builder($query = '*:*')
    {
        return new Builder(new TestModel(), $query);
    }

    protected function params()
    {
        return $this->adapter->requests[count($this->adapter->requests) - 1]['request']->getParams();
    }
}
