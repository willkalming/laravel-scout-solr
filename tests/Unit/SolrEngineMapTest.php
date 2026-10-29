<?php

namespace Scout\Solr\Tests\Unit;

use Scout\Solr\SolrCollection;
use Scout\Solr\Tests\Support\EngineTestCase;
use Scout\Solr\Tests\Support\MapStubModel;

class SolrEngineMapTest extends EngineTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $connection = new \Illuminate\Database\MySqlConnection(
            new \PDO('sqlite::memory:'), 'db', '', ['driver' => 'mysql']
        );
        $resolver = new \Illuminate\Database\ConnectionResolver(['default' => $connection]);
        $resolver->setDefaultConnection('default');
        \Illuminate\Database\Eloquent\Model::setConnectionResolver($resolver);
    }

    public function test_map_attaches_facets_and_ranks_with_mysql_field_ordering(): void
    {
        // Facet keys in the response are the engine-suffixed names (issue: keys are "category-field", not "category").
        $this->adapter->response['facet_counts'] = ['facet_fields' => ['category-field' => ['fiction', 3, 'physics', 1]]];
        $this->adapter->response['response']['docs'] = [['id' => 1], ['id' => 2]];
        MapStubModel::$fakeRows = [new MapStubModel(['id' => 1]), new MapStubModel(['id' => 2])];

        $builder = $this->builder()->facetField('category');
        $models = $this->engine->map($builder, $this->engine->search($builder), new MapStubModel());

        self::assertInstanceOf(SolrCollection::class, $models);
        self::assertSame('FIELD(id, 1,2)', MapStubModel::$lastOrderByRaw);
        self::assertArrayHasKey('category-field', $models->first()->facets);
    }

    public function test_map_without_facets_still_attaches_empty_facets(): void
    {
        $this->adapter->response['response']['docs'] = [['id' => 1]];
        MapStubModel::$fakeRows = [new MapStubModel(['id' => 1])];
        // Note: getFacetSet() is a real object here because the engine always
        // configures the facetset component; getSpellcheck() is null.
        $builder = $this->builder();
        $models = $this->engine->map($builder, $this->engine->search($builder), new MapStubModel());
        self::assertSame([], $models->first()->facets);
        self::assertNull($models->first()->spellcheck);
    }
}
