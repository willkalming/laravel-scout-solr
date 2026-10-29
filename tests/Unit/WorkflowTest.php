<?php

namespace Scout\Solr\Tests\Unit;

use Laravel\Scout\EngineManager;
use Scout\Solr\Engines\SolrEngine;
use Scout\Solr\SolrCollection;
use Scout\Solr\Tests\Support\ApplicationTestCase;
use Scout\Solr\Tests\Support\DefaultCoreModel;
use Scout\Solr\Tests\Support\MapStubModel;
use Solarium\Client;

/** End-to-end workflow tests through the provider, builder, engine and mapping. */
class WorkflowTest extends ApplicationTestCase
{
    public function test_provider_registers_the_solr_driver(): void
    {
        self::assertInstanceOf(SolrEngine::class, $this->app->make(EngineManager::class)->engine());
        self::assertSame($this->app->make(Client::class), $this->app->make(Client::class));
    }

    public function test_search_get_maps_documents_to_models(): void
    {
        MapStubModel::$fakeRows = [new MapStubModel(['id' => 1]), new MapStubModel(['id' => 2])];
        $this->adapter->response['response']['docs'] = [['id' => 1], ['id' => 2]];
        $models = MapStubModel::search('books')->get();
        self::assertInstanceOf(SolrCollection::class, $models);
        self::assertCount(2, $models);
        // map() attaches facets/spellcheck attributes even when empty.
        self::assertSame([], $models->first()->facets);
        self::assertNull($models->first()->spellcheck);
    }

    public function test_paginate_attaches_the_raw_result_to_the_collection(): void
    {
        MapStubModel::$fakeRows = [new MapStubModel(['id' => 1])];
        $this->adapter->response['response'] = ['numFound' => 5, 'start' => 1, 'docs' => [['id' => 1]]];
        $paginator = MapStubModel::search('books')->paginate(1, 'page', 2);
        self::assertSame(5, $paginator->total());
        $collection = $paginator->getCollection();
        self::assertInstanceOf(SolrCollection::class, $collection);
        // HasSolrResults accessors work because paginate() attached the result.
        self::assertInstanceOf(\Solarium\Component\Result\FacetSet::class, $collection->getFacetSet());
        $params = end($this->adapter->requests)['request']->getParams();
        self::assertSame(1, $params['start']);
        self::assertSame(1, $params['rows']);
    }

    public function test_model_searches_the_configured_core(): void
    {
        DefaultCoreModel::search('anything')->get();
        self::assertSame('books', $this->adapter->requests[0]['endpoint']->getKey());
    }

    public function test_escaping_helpers_resolve_through_the_engine_manager(): void
    {
        self::assertSame('a\\:b', MapStubModel::escapeSolrQueryAsTerm('a:b'));
        self::assertSame('"a\\"b"', MapStubModel::escapeSolrQueryAsPhrase('a"b'));
    }
}
