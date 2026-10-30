<?php

namespace Scout\Solr\Tests\Regression;

use Illuminate\Config\Repository;
use Illuminate\Database\Eloquent\Collection;
use Scout\Solr\Engines\SolrEngine;
use Scout\Solr\Tests\Support\EngineTestCase;
use Scout\Solr\Tests\Support\MapStubModel;
use Scout\Solr\Tests\Support\TestModel;
use Solarium\Component\Result\FacetSet as FacetSetResult;

/**
 * Issue regression tests: each test asserts the FIXED behavior of an entry
 * from docs/issues.md. On the release/v3-tests baseline these same scenarios
 * were characterization tests pinning the broken behavior.
 */
class EngineBugsTest extends EngineTestCase
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

    public function test_issue_2_disabled_engine_no_longer_paginates_over_the_wire(): void
    {
        $engine = new SolrEngine($this->client, new Repository(['solr' => ['enabled' => false]]));
        $results = $engine->paginate($this->builder(), 10, 1);
        self::assertCount(0, $results);
        self::assertSame(0, $engine->getTotalCount($results));
        self::assertSame([], $this->adapter->requests);
    }

    public function test_issue_4_unknown_where_entries_are_skipped_instead_of_corrupting_filters(): void
    {
        $builder = $this->builder('books')->where('category:%P1%', ['fiction']);
        $builder->wheres[] = ['type' => 'unknown', 'boolean' => 'AND'];
        $this->engine->search($builder);
        // The unknown entry must not appear, and the real filter is untouched.
        self::assertSame(['category:"fiction"'], (array) $this->params()['fq']);
    }

    public function test_issue_5_scout_style_where_is_translated_into_a_filter_query(): void
    {
        $this->engine->search($this->builder()->where('category', 'fiction'));
        self::assertSame(['category:fiction'], (array) $this->params()['fq']);
    }

    public function test_issue_5_scout_style_operators_map_to_solr_ranges_and_negation(): void
    {
        $this->engine->search(
            $this->builder()
                ->where('price', '>', 10)
                ->where('category', '!=', 'fiction')
        );
        self::assertSame(['price:{10 TO *}', '(*:* -category:fiction)'], (array) $this->params()['fq']);
    }

    public function test_issue_5_values_with_spaces_are_escaped_as_phrases(): void
    {
        $this->engine->search($this->builder()->where('title', 'foo bar'));
        self::assertSame(['title:"foo bar"'], (array) $this->params()['fq']);
    }

    public function test_issue_5_soft_delete_scout_where_is_translated(): void
    {
        $builder = $this->builder('books');
        $builder->wheres[] = ['field' => '__soft_deleted', 'operator' => '=', 'value' => 0];
        $this->engine->search($builder);
        self::assertSame(['__soft_deleted:0'], (array) $this->params()['fq']);
    }

    public function test_where_in_and_where_not_in_become_solr_groups(): void
    {
        $this->engine->search(
            $this->builder()
                ->whereIn('category', ['fiction', 'history'])
                ->whereNotIn('state', ['archived'])
        );
        self::assertSame(
            ['category:(fiction OR history)', '(*:* -state:(archived))'],
            (array) $this->params()['fq']
        );
    }

    public function test_issue_6_update_with_an_empty_collection_is_a_no_op(): void
    {
        $this->engine->update(new Collection());
        self::assertSame([], $this->adapter->requests);
    }

    public function test_issue_6_delete_with_an_empty_collection_is_a_no_op(): void
    {
        $this->engine->delete(new Collection());
        self::assertSame([], $this->adapter->requests);
    }

    public function test_issue_7_map_ids_from_honors_the_given_key_name(): void
    {
        $this->adapter->response['response']['docs'] = [['sku' => 'book-1'], ['sku' => 'book-2'], ['id' => 5]];
        $result = $this->engine->search($this->builder());
        self::assertSame(['book-1', 'book-2', null], $this->engine->mapIdsFrom($result, 'sku')->all());
        // mapIds() keeps Scout's conventional 'id' key.
        self::assertSame([null, null, 5], $this->engine->mapIds($result)->all());
    }

    public function test_issue_20_scout_11_get_scout_key_name_is_the_plain_key(): void
    {
        self::assertSame('id', (new TestModel())->getScoutKeyName());
        MapStubModel::$fakeRows = [new MapStubModel(['id' => 1])];
        $this->adapter->response['response']['docs'] = [['id' => 1]];
        $models = $this->engine->map($this->builder(), $this->engine->search($this->builder()), new MapStubModel());
        self::assertSame('FIELD(id, 1)', MapStubModel::$lastOrderByRaw);
        self::assertCount(1, $models);
    }

    public function test_issue_9_flush_targets_the_models_core(): void
    {
        $this->engine->flush(new TestModel());
        self::assertSame('books', $this->adapter->requests[0]['endpoint']->getKey());
    }

    public function test_issue_17_nested_grouped_query_arrays_compile(): void
    {
        $this->engine->search($this->builder(['title' => ['one', 'two']]));
        self::assertSame('title:(one two)', $this->params()['q']);
    }

    public function test_issue_17_mixed_flat_and_nested_arrays_compile(): void
    {
        $this->engine->search($this->builder(['title' => ['one', 'two'], 'author:will']));
        self::assertSame('title:(one two) author:will', $this->params()['q']);
    }

    public function test_issue_18_nested_filters_keep_their_grouping_parentheses(): void
    {
        $builder = $this->builder()->where(function ($q) {
            $q->where('a:1')->where(function ($nested) {
                $nested->where('b:2')->orWhere('c:3');
            });
        });
        $this->engine->search($builder);
        self::assertSame(['a:1 AND (b:2 OR c:3)'], (array) $this->params()['fq']);
    }

    public function test_issue_19_builder_callback_is_invoked_with_the_solarium_query(): void
    {
        $called = false;
        $builder = $this->builder();
        $builder->callback = function ($query) use (&$called) {
            $called = true;
            $query->addParam('callbackCheck', 'yes');
        };
        $this->engine->search($builder);
        self::assertTrue($called);
        self::assertSame('yes', $this->params()['callbackCheck']);
    }

    public function test_issue_11_mysql_order_query_has_no_stray_argument(): void
    {
        MapStubModel::$fakeRows = [new MapStubModel(['id' => 1]), new MapStubModel(['id' => 2])];
        $this->adapter->response['response']['docs'] = [['id' => 1], ['id' => 2]];
        $this->engine->map($this->builder(), $this->engine->search($this->builder()), new MapStubModel());
        self::assertSame('FIELD(id, 1,2)', MapStubModel::$lastOrderByRaw);
    }

    public function test_create_and_delete_index_use_core_admin(): void
    {
        $this->engine->createIndex('new-core', ['instance_dir' => 'cores/new-core', 'configset' => '_default']);
        $params = $this->params();
        self::assertSame('CREATE', $params['action']);
        self::assertSame('new-core', $params['name']);
        self::assertSame('cores/new-core', $params['instanceDir']);
        self::assertSame('_default', $params['configSet']);

        $this->engine->deleteIndex('old-core');
        $params = $this->params();
        self::assertSame('UNLOAD', $params['action']);
        self::assertSame('old-core', $params['core']);
        // GET params are always strings on the wire.
        self::assertSame('true', $params['deleteDataDir']);
        self::assertSame('true', $params['deleteIndex']);
    }

    public function test_lazy_map_streams_models_without_attaching_facets(): void
    {
        MapStubModel::$fakeRows = [new MapStubModel(['id' => 2]), new MapStubModel(['id' => 1])];
        $this->adapter->response['response']['docs'] = [['id' => 1], ['id' => 2]];
        $lazy = $this->engine->lazyMap($this->builder(), $this->engine->search($this->builder()), new MapStubModel());
        self::assertInstanceOf(\Illuminate\Support\LazyCollection::class, $lazy);
        self::assertSame([1, 2], $lazy->map->id->all());
    }

    public function test_empty_results_give_an_empty_solr_collection_and_empty_lazy_map(): void
    {
        self::assertInstanceOf(\Scout\Solr\SolrCollection::class, $this->engine->map($this->builder(), new Collection(), new TestModel()));
        // search() returns null-aware guards
        self::assertCount(0, $this->engine->lazyMap($this->builder(), collect(), new TestModel()));
        self::assertSame([], $this->engine->mapIdsFrom(collect(), 'id')->all());
    }
}
