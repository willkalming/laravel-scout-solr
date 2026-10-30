<?php

namespace Scout\Solr\Tests\Unit;

use Illuminate\Config\Repository;
use Illuminate\Database\Eloquent\Collection;
use Scout\Solr\Engines\SolrEngine;
use Scout\Solr\Tests\Support\EngineTestCase;
use Scout\Solr\Tests\Support\TestModel;

class SolrEngineTest extends EngineTestCase
{
    public function test_search_serializes_query_filters_sort_and_limits(): void
    {
        $builder = $this->builder('books')->where('category:%P1%', ['fiction'])
            ->orWhere('category:%P1%', ['history'])->whereRange('price', '1', '20')
            ->orderBy('price', 'desc')->take(15)->setStart(4)->queryParam('custom', 'value');
        $result = $this->engine->search($builder);
        $params = $this->params();
        self::assertSame('books', $params['q']);
        self::assertSame(['category:"fiction" OR (category:"history")', 'price:[1 TO 20]'], $params['fq']);
        self::assertSame('price desc', $params['sort']);
        self::assertSame(15, $params['rows']);
        self::assertSame(4, $params['start']);
        self::assertSame('value', $params['custom']);
        self::assertSame('books', $this->adapter->requests[0]['endpoint']->getKey());
        self::assertSame($result, $this->engine->getLastSelectResult());
    }

    public function test_facets_spellcheck_debug_and_boosts_reach_the_request(): void
    {
        $this->engine->search($this->builder('book')->useDismax()->boostField('title', 3)
            ->facetField('category')->facetQuery('price', '[0 TO 10]')
            ->facetPivot(['category', 'author'])->setFacetOption('mincount', 1)
            ->spellcheck(['count' => 4])->debug());
        $params = $this->params();
        self::assertSame('title^3', $params['qf']);
        self::assertStringContainsString('category', implode(' ', (array) $params['facet.field']));
        self::assertStringContainsString('price:[0 TO 10]', implode(' ', (array) $params['facet.query']));
        self::assertSame(4, $params['spellcheck.count']);
        self::assertArrayHasKey('debugQuery', $params);
    }

    public function test_empty_dismax_query_uses_match_all_alternative(): void
    {
        $this->engine->search($this->builder('')->useEDismax());
        self::assertSame('*:*', $this->params()['q.alt']);
    }

    public function test_flat_query_arrays_are_joined(): void
    {
        $this->engine->search($this->builder(['title' => 'books', 'author:will']));
        self::assertSame('title:books author:will', $this->params()['q']);
    }

    public function test_pagination_converts_page_to_offset(): void
    {
        $this->engine->paginate($this->builder(), 10, 3);
        self::assertSame(20, $this->params()['start']);
        self::assertSame(10, $this->params()['rows']);
    }

    public function test_ids_total_and_empty_mapping_use_real_response_parser(): void
    {
        $this->adapter->response['response'] = ['numFound' => 8, 'start' => 0, 'docs' => [['id' => 3], ['id' => 1]]];
        $result = $this->engine->search($this->builder());
        self::assertSame([3, 1], $this->engine->mapIds($result)->all());
        self::assertSame(8, $this->engine->getTotalCount($result));
        self::assertCount(0, $this->engine->map($this->builder(), new Collection(), new TestModel()));
    }

    public function test_update_writes_documents_metadata_and_commit(): void
    {
        $this->engine->update(new Collection([new TestModel(['id' => 7, 'title' => 'A & B']), new TestModel()]));
        $request = $this->adapter->requests[0]['request'];
        // Solarium 7 serializes update requests as JSON by default.
        $payload = json_decode($request->getRawData(), true);
        $doc = $payload['add']['doc'];
        self::assertSame(7, $doc['id']);
        self::assertSame('A & B', $doc['title']);
        self::assertSame(TestModel::class, $doc['_modelClass']);
        self::assertArrayNotHasKey('meta', $doc);
        self::assertArrayHasKey('commit', $payload);
        self::assertSame('books', $this->adapter->requests[0]['endpoint']->getKey());
    }

    public function test_delete_serializes_ids_and_commit(): void
    {
        $this->engine->delete(new Collection([new TestModel(['id' => 7]), new TestModel(['id' => 8])]));
        // Solarium 7 serializes update requests as JSON by default.
        $payload = json_decode($this->adapter->requests[0]['request']->getRawData(), true);
        self::assertSame([7, 8], $payload['delete']);
        self::assertArrayHasKey('commit', $payload);
    }

    public function test_disabled_search_update_and_delete_do_not_use_transport(): void
    {
        $engine = new SolrEngine($this->client, new Repository(['solr' => ['enabled' => false]]));
        self::assertCount(0, $engine->search($this->builder()));
        $engine->update(new Collection());
        $engine->delete(new Collection());
        self::assertSame([], $this->adapter->requests);
    }

    public function test_escaping_uses_solarium_helper(): void
    {
        self::assertSame('a\\:b', $this->engine->escapeQueryAsTerm('a:b'));
        self::assertSame('"a\\"b"', $this->engine->escapeQueryAsPhrase('a"b'));
    }

    public function test_base_scout_builder_is_rejected(): void
    {
        $this->expectExceptionMessage('Your model must use');
        $this->engine->search(new \Laravel\Scout\Builder(new TestModel(), '*:*'));
    }
}
