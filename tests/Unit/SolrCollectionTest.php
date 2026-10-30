<?php

namespace Scout\Solr\Tests\Unit;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use PHPUnit\Framework\TestCase;
use Scout\Solr\HasSolrResults;
use Scout\Solr\SolrCollection;
use Solarium\Client;
use Solarium\Component\Result\Facet\Field as FacetFieldResult;
use Solarium\Component\Result\Facet\MultiQuery as MultiQueryResult;
use Solarium\Component\Result\Facet\Pivot\Pivot as PivotResult;
use Solarium\Component\Result\Facet\Query as FacetQueryResult;
use Solarium\Component\Result\FacetSet as FacetSetResult;
use Solarium\Component\Result\Spellcheck\Collation;
use Solarium\Component\Result\Spellcheck\Result as SpellcheckResult;
use Solarium\Component\Result\Spellcheck\Suggestion;
use Solarium\Core\Client\Adapter\Http;
use Solarium\Core\Client\Response as SolariumResponse;
use Solarium\QueryType\Select\Query\Query as SelectQuery;
use Solarium\QueryType\Select\Result\Result as SelectResult;
use Symfony\Component\EventDispatcher\EventDispatcher;

class SolrCollectionTest extends TestCase
{
    /**
     * A real Solarium client; no connection is ever opened because we only
     * build query/result objects offline.
     */
    protected function makeClient(): Client
    {
        // Solarium 7 requires an adapter in the constructor; the Http adapter
        // never connects because we only build query/result objects offline.
        return new Client(new Http(), new EventDispatcher());
    }

    /**
     * Produce a real Solarium select result the same way $client->select()
     * would, but from a canned JSON response body.
     *
     * @param array    $data       Decoded Solr response body (minus the echo header noise)
     * @param callable $configure  Receives the select query to add components to
     */
    protected function makeResult(array $data, callable $configure): SelectResult
    {
        $client = $this->makeClient();
        /** @var SelectQuery $query */
        $query = $client->createSelect();
        $configure($query);

        $response = new SolariumResponse(json_encode($data), ['HTTP/1.1 200 OK']);

        return $client->createResult($query, $response);
    }

    /**
     * A result built the way SolrEngine::performSearch builds it: facetset
     * component always present, engine-style facet keys, and the documented
     * facet_counts payload Solr would return for them.
     */
    protected function makeFacetedResult(): SelectResult
    {
        return $this->makeResult([
            'responseHeader' => ['status' => 0, 'QTime' => 1],
            'response' => [
                'numFound' => 2,
                'start' => 0,
                'docs' => [
                    ['id' => '1', 'title' => 'lorem'],
                    ['id' => '2', 'title' => 'ipsum'],
                ],
            ],
            'facet_counts' => [
                'facet_queries' => [
                    'price-query' => 2,
                    'state-multiquery-0' => 2,
                    'state-multiquery-1' => 1,
                ],
                'facet_fields' => [
                    'status-field' => ['active', 2, 'inactive', 0],
                ],
                'facet_pivot' => [
                    'state-city' => [
                        [
                            'field' => 'state',
                            'value' => 'ca',
                            'count' => 2,
                            'pivot' => [['field' => 'city', 'value' => 'sf', 'count' => 1]],
                        ],
                    ],
                ],
            ],
        ], function (SelectQuery $query) {
            $facetSet = $query->getFacetSet();
            $facetSet->createFacetField('status-field')->setField('status');
            $facetSet->createFacetQuery('price-query')->setQuery('price:[* TO 10]');
            $multi = $facetSet->createFacetMultiQuery('state-multiquery');
            $multi->createQuery('state-multiquery-0', 'state:ca');
            $multi->createQuery('state-multiquery-1', 'state:nv');
            $facetSet->createFacetPivot('state-city')->addFields('state,city');
        });
    }

    protected function makeCollection(SelectResult $results, array $items = []): SolrCollection
    {
        $collection = new SolrCollection($items);
        $collection->setResults($results);

        return $collection;
    }

    public function testItIsAnEloquentCollectionUsingTheHasSolrResultsTrait()
    {
        $collection = new SolrCollection([['id' => 1], ['id' => 2]]);

        $this->assertInstanceOf(EloquentCollection::class, $collection);
        $this->assertContains(HasSolrResults::class, class_uses(SolrCollection::class));
        $this->assertCount(2, $collection);
    }

    public function testGetFacetSetExposesTheSolariumFacetSetResult()
    {
        $collection = $this->makeCollection($this->makeFacetedResult());

        $facetSet = $collection->getFacetSet();

        $this->assertInstanceOf(FacetSetResult::class, $facetSet);
        $this->assertSame(4, $facetSet->count());
    }

    /**
     * Characterization: facet result keys carry the engine's suffixes
     * ("<field>-field", "<field>-query", "<field>-multiquery", pivot keys are
     * the dash-joined field list) rather than the bare field names.
     */
    public function testGetFacetsReturnsFacetsKeyedByTheEngineStyleKeys()
    {
        $collection = $this->makeCollection($this->makeFacetedResult());

        $facets = $collection->getFacets();

        $this->assertSame(
            ['status-field', 'price-query', 'state-multiquery', 'state-city'],
            array_keys($facets)
        );

        $this->assertInstanceOf(FacetFieldResult::class, $facets['status-field']);
        $this->assertSame(['active' => 2, 'inactive' => 0], $facets['status-field']->getValues());

        $this->assertInstanceOf(FacetQueryResult::class, $facets['price-query']);
        $this->assertSame(2, $facets['price-query']->getValue());

        $this->assertInstanceOf(MultiQueryResult::class, $facets['state-multiquery']);
        $this->assertSame(['state-multiquery-0' => 2, 'state-multiquery-1' => 1], iterator_to_array($facets['state-multiquery']));

        $this->assertInstanceOf(PivotResult::class, $facets['state-city']);
        $pivots = $facets['state-city']->getPivot();
        $this->assertSame('state', $pivots[0]->getField());
        $this->assertSame('ca', $pivots[0]->getValue());
        $this->assertSame(1, $pivots[0]->getPivot()[0]->getCount());
    }

    /**
     * SolrEngine::performSearch always initializes the facetset component, so
     * a facet-less search still yields a (count 0) facetset result whose
     * getFacets() accessor resolves to null.
     */
    public function testGetFacetsReturnsNullWhenTheFacetSetIsEmpty()
    {
        $results = $this->makeResult([
            'responseHeader' => ['status' => 0, 'QTime' => 1],
            'response' => ['numFound' => 0, 'start' => 0, 'docs' => []],
            'facet_counts' => [],
        ], function (SelectQuery $query) {
            $query->getFacetSet();
        });

        $collection = $this->makeCollection($results);

        $this->assertInstanceOf(FacetSetResult::class, $collection->getFacetSet());
        $this->assertNull($collection->getFacets());
    }

    public function testGetSpellcheckExposesSuggestionsAndCollations()
    {
        $results = $this->makeResult([
            'responseHeader' => ['status' => 0, 'QTime' => 1],
            'response' => ['numFound' => 0, 'start' => 0, 'docs' => []],
            'spellcheck' => [
                'suggestions' => [
                    'wrld',
                    [
                        'numFound' => 1,
                        'startOffset' => 0,
                        'endOffset' => 4,
                        'origFreq' => 0,
                        'suggestion' => [['word' => 'world', 'freq' => 7]],
                    ],
                    'correctlySpelled',
                    false,
                ],
                'collations' => ['collation', 'hello world'],
                'correctlySpelled' => false,
            ],
        ], function (SelectQuery $query) {
            $query->getSpellcheck();
        });

        $collection = $this->makeCollection($results);
        $spellcheck = $collection->getSpellcheck();

        $this->assertInstanceOf(SpellcheckResult::class, $spellcheck);
        $this->assertFalse($spellcheck->getCorrectlySpelled());

        $suggestions = $collection->getSpellcheckSuggestions();
        $this->assertCount(1, $suggestions);
        $this->assertInstanceOf(Suggestion::class, $suggestions[0]);
        $this->assertSame(1, $suggestions[0]->getNumFound());
        $this->assertSame('world', $suggestions[0]->getWord());

        $collations = $collection->getSpellcheckCollations();
        $this->assertCount(1, $collations);
        $this->assertInstanceOf(Collation::class, $collations[0]);
        $this->assertSame('hello world', $collations[0]->getQuery());
    }

    public function testSpellcheckAccessorsResolveToNullWhenNothingWasReturned()
    {
        $results = $this->makeResult([
            'responseHeader' => ['status' => 0, 'QTime' => 1],
            'response' => ['numFound' => 0, 'start' => 0, 'docs' => []],
        ], function (SelectQuery $query) {
            // engine sets up the component, but Solr returns no spellcheck block
            $query->getSpellcheck();
        });

        $collection = $this->makeCollection($results);

        $this->assertNull($collection->getSpellcheck());
        $this->assertNull($collection->getSpellcheckSuggestions());
        $this->assertNull($collection->getSpellcheckCollations());
    }
}
