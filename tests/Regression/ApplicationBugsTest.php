<?php

namespace Scout\Solr\Tests\Regression;

use Laravel\Scout\EngineManager;
use Scout\Solr\Tests\Support\ApplicationTestCase;
use Scout\Solr\Tests\Support\MapStubModel;

/** Bugs whose reproduction needs the application/provider wired up. */
class ApplicationBugsTest extends ApplicationTestCase
{
    public function test_issue_1_config_is_merged_under_a_namespace_nobody_reads(): void
    {
        // The provider merges config/scout-solr.php under 'scout-solr' ...
        self::assertSame(25, config('scout-solr.paginate_size'));
        // ... but every consumer reads the 'solr.*' namespace.
        self::assertNull(config('solr.paginate_size'));
    }

    public function test_issue_3_collections_without_attached_results_fatal(): void
    {
        $collection = new \Scout\Solr\SolrCollection();
        $this->expectException(\Error::class);
        // getFacetSet() dereferences the never-set results object.
        $collection->getFacetSet();
    }

    public function test_issue_12_get_returns_a_collection_that_cannot_reach_the_solr_result(): void
    {
        MapStubModel::$fakeRows = [new MapStubModel(['id' => 1])];
        $this->adapter->response['response']['docs'] = [['id' => 1]];
        $models = MapStubModel::search('books')->get();
        // The result is only attached in paginate(), not get(): facet accessors fatal.
        $this->expectException(\Error::class);
        $models->getFacets();
    }
}
