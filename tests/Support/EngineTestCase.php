<?php

namespace Scout\Solr\Tests\Support;

use Illuminate\Config\Repository;
use PHPUnit\Framework\TestCase;
use Scout\Solr\Builder;
use Scout\Solr\Engines\SolrEngine;
use Solarium\Client;
use Symfony\Component\EventDispatcher\EventDispatcher;

abstract class EngineTestCase extends TestCase
{
    protected $adapter;
    protected $client;
    protected $engine;

    protected function setUp(): void
    {
        $this->adapter = new FakeAdapter();
        $this->client = new Client($this->adapter, new EventDispatcher(), ['endpoint' => [
            'default' => ['host' => 'unused.invalid', 'core' => 'default'],
            'books' => ['host' => 'unused.invalid', 'core' => 'books'],
        ]]);
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

    protected function param(string $key)
    {
        return $this->params()[$key];
    }
}
