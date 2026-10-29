<?php

namespace Scout\Solr\Tests\Regression;

use PHPUnit\Framework\TestCase;
use Scout\Solr\SolrCollection;
use Solarium\Client;
use Solarium\Core\Client\Adapter\Http;
use Solarium\Core\Client\Response as SolariumResponse;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * Fixed-behavior tests for the SolrCollection accessor crashes that the
 * hop-1 characterization tests had pinned.
 */
class CollectionBugsTest extends TestCase
{
    protected function makeClient(): Client
    {
        return new Client(new Http(), new EventDispatcher());
    }

    /**
     * Issue 3 (fixed): a result whose producing query never had the facetset
     * component returns null from getFacets() instead of fataling.
     */
    public function testGetFacetsReturnsNullWhenTheResultHasNoFacetSetComponent()
    {
        $query = $this->makeClient()->createSelect();
        $response = new SolariumResponse(
            json_encode([
                'responseHeader' => ['status' => 0, 'QTime' => 1],
                'response' => ['numFound' => 0, 'start' => 0, 'docs' => []],
            ]),
            ['HTTP/1.1 200 OK']
        );
        $results = $this->makeClient()->createResult($query, $response);
        $this->assertNull($results->getFacetSet());

        $collection = new SolrCollection();
        $collection->setResults($results);

        $this->assertNull($collection->getFacetSet());
        $this->assertNull($collection->getFacets());
    }

    /**
     * Issue 3 (fixed): a collection with no results attached returns null
     * from every accessor instead of dereferencing a never-initialized value.
     */
    public function testAllAccessorsReturnNullWithoutResults()
    {
        $collection = new SolrCollection();
        $this->assertNull($collection->getFacetSet());
        $this->assertNull($collection->getFacets());
        $this->assertNull($collection->getSpellcheck());
        $this->assertNull($collection->getSpellcheckSuggestions());
        $this->assertNull($collection->getSpellcheckCollations());
    }

    /**
     * Issue 12 (fixed): setting a null result explicitly (done by Builder::get()
     * before the engine's first result exists) is still safe.
     */
    public function testSettingNullResultsIsSafe()
    {
        $collection = new SolrCollection();
        $collection->setResults(null);
        $this->assertNull($collection->getFacetSet());
    }
}
