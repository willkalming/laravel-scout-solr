<?php

namespace Scout\Solr\Tests\Regression;

use Scout\Solr\SolrCollection;
use Scout\Solr\Tests\Support\ApplicationTestCase;
use Scout\Solr\Tests\Support\MapStubModel;
use Solarium\Component\Result\FacetSet as FacetSetResult;

/**
 * Application-level issue regression tests asserting the FIXED behavior;
 * their hop-1 ancestors pinned the broken behavior.
 */
class ApplicationBugsTest extends ApplicationTestCase
{
    public function test_issue_1_config_is_merged_and_published_under_the_solr_namespace(): void
    {
        // Config from config/scout-solr.php now lands in the namespace every
        // consumer reads...
        self::assertSame(25, config('solr.paginate_size'));
        // ...and no longer leaks into the old 'scout-solr' namespace.
        self::assertNull(config('scout-solr.paginate_size'));
        // The provider publishes the file as config/solr.php.
        $published = \Illuminate\Support\ServiceProvider::pathsToPublish(\Scout\Solr\ScoutSolrServiceProvider::class);
        $targets = array_values($published);
        self::assertStringEndsWith('/solr.php', $targets[0]);
        self::assertStringEndsWith('config/scout-solr.php', array_keys($published)[0]);
    }

    public function test_issue_3_collections_without_attached_results_return_null(): void
    {
        $collection = new SolrCollection();
        self::assertNull($collection->getFacetSet());
        self::assertNull($collection->getFacets());
        self::assertNull($collection->getSpellcheck());
        self::assertNull($collection->getSpellcheckSuggestions());
        self::assertNull($collection->getSpellcheckCollations());
    }

    public function test_issue_3_facets_on_a_result_without_a_facetset_component_return_null(): void
    {
        $collection = new SolrCollection();
        $collection->setResults($this->plainResult());
        self::assertNull($collection->getFacets());
    }

    public function test_issue_12_get_attaches_the_raw_result_to_the_collection(): void
    {
        MapStubModel::$fakeRows = [new MapStubModel(['id' => 1])];
        $this->adapter->response['response']['docs'] = [['id' => 1]];
        $models = MapStubModel::search('books')->get();
        // The collection is a SolrCollection with the raw result attached:
        // facet/spellcheck accessors work instead of fataling.
        self::assertInstanceOf(SolrCollection::class, $models);
        self::assertInstanceOf(FacetSetResult::class, $models->getFacetSet());
        self::assertNull($models->getFacets());
        self::assertNull($models->getSpellcheck());
    }

    public function test_issue_12_paginate_keeps_attaching_the_raw_result(): void
    {
        MapStubModel::$fakeRows = [new MapStubModel(['id' => 1])];
        $this->adapter->response['response'] = ['numFound' => 1, 'start' => 0, 'docs' => [['id' => 1]]];
        $paginator = MapStubModel::search('books')->paginate();
        $collection = $paginator->getCollection();
        self::assertInstanceOf(FacetSetResult::class, $collection->getFacetSet());
    }

    /**
     * Build a real Solarium select result from a calfed JSON body.
     */
    protected function plainResult(): \Solarium\QueryType\Select\Result\Result
    {
        $client = new \Solarium\Client(
            new \Solarium\Core\Client\Adapter\Http(),
            new \Symfony\Component\EventDispatcher\EventDispatcher()
        );
        $query = $client->createSelect();
        $response = new \Solarium\Core\Client\Response(
            json_encode([
                'responseHeader' => ['status' => 0, 'QTime' => 1],
                'response' => ['numFound' => 0, 'start' => 0, 'docs' => []],
            ]),
            ['HTTP/1.1 200 OK']
        );

        return $client->createResult($query, $response);
    }
}
