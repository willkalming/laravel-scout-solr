<?php

namespace Scout\Solr\Tests\Regression;

use Illuminate\Config\Repository;
use Illuminate\Database\Eloquent\Collection;
use Scout\Solr\Engines\SolrEngine;
use Scout\Solr\Tests\Support\EngineTestCase;
use Scout\Solr\Tests\Support\TestModel;

/**
 * Characterization tests: these deliberately describe the library's current
 * buggy behavior so the upgrade hop can replace them with corrected expectations.
 * Test names reference entries in docs/issues.md.
 */
class EngineBugsTest extends EngineTestCase
{
    public function test_issue_2_disabled_engine_still_paginates_over_the_wire(): void
    {
        $engine = new SolrEngine($this->client, new Repository(['solr' => ['enabled' => false]]));
        $engine->paginate($this->builder(), 10, 1);
        self::assertCount(1, $this->adapter->requests);
    }

    public function test_issue_4_unknown_where_entry_reuses_the_stale_query_string(): void
    {
        $builder = $this->builder('books');
        $builder->wheres[] = ['type' => 'unknown', 'boolean' => 'AND'];
        $this->engine->search($builder);
        // The unknown where falls through and the *main query* is appended as a filter query.
        self::assertSame(['books'], (array) $this->params()['fq']);
    }

    public function test_issue_5_scout_style_where_silently_corrupts_the_filter(): void
    {
        // where('category', 'books') is misread as query='category', bindings='books' (a string).
        $this->engine->search($this->builder()->where('category', 'books'));
        $params = $this->params();
        // The field name becomes a nonsense filter query; the value silently disappears.
        self::assertSame(['category'], (array) $params['fq']);
    }

    public function test_issue_6_update_crashes_on_an_empty_collection(): void
    {
        $this->expectException(\Error::class);
        $this->expectExceptionMessage('searchableAs() on null');
        $this->engine->update(new Collection());
    }

    public function test_issue_6_delete_crashes_on_an_empty_collection(): void
    {
        $this->expectException(\Error::class);
        $this->expectExceptionMessage('searchableAs() on null');
        $this->engine->delete(new Collection());
    }

    public function test_issue_7_map_ids_ignores_the_scout_key_name(): void
    {
        $this->adapter->response['response']['docs'] = [['sku' => 'book-1']];
        self::assertSame([null], $this->engine->mapIds($this->engine->search($this->builder()))->all());
    }

    public function test_issue_20_map_cannot_pluck_scout_7_qualified_key(): void
    {
        $model = new TestModel();
        self::assertSame('books.id', $model->getScoutKeyName());
        $this->adapter->response['response']['docs'] = [['id' => 1]];
        $ids = collect($this->engine->search($this->builder()))->pluck($model->getScoutKeyName())->all();
        self::assertSame([null], $ids);
    }

    public function test_issue_9_flush_uses_the_default_endpoint_instead_of_the_models_core(): void
    {
        $this->engine->flush(new TestModel());
        self::assertSame('default', $this->adapter->requests[0]['endpoint']->getKey());
    }

    public function test_issue_17_nested_grouped_query_arrays_fatal(): void
    {
        $this->expectException(\Error::class);
        $this->expectExceptionMessage('[] operator not supported for strings');
        $this->engine->search($this->builder(['title' => ['one', 'two']]));
    }

    public function test_issue_18_nested_filters_lose_grouping_parentheses(): void
    {
        $builder = $this->builder()->where(function ($q) {
            $q->where('a:1')->where(function ($nested) {
                $nested->where('b:2')->orWhere('c:3');
            });
        });
        $this->engine->search($builder);
        // The nested group is not parenthesized, changing operator precedence.
        self::assertSame(['a:1 AND b:2 OR c:3'], (array) $this->params()['fq']);
    }

    public function test_issue_19_builder_callback_is_never_invoked(): void
    {
        $called = false;
        $builder = $this->builder();
        $builder->callback = function () use (&$called) { $called = true; };
        $this->engine->search($builder);
        self::assertFalse($called);
    }
}
