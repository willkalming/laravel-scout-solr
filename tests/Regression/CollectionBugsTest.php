<?php

namespace Scout\Solr\Tests\Regression;

use PHPUnit\Framework\TestCase;
use Scout\Solr\SolrCollection;
use Solarium\Client;
use Solarium\Core\Client\Response as SolariumResponse;
use Solarium\QueryType\Select\Query\Query as SelectQuery;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * Characterization tests for currently-buggy SolrCollection behavior.
 *
 * These pin down the failures as they exist today so a future fix has an
 * explicit failure mode to change; they are kept out of the unit suite so
 * the unit suite only contains healthy-behavior coverage.
 */
class CollectionBugsTest extends TestCase
{
    protected function makeClient(): Client
    {
        return new Client(null, new EventDispatcher());
    }

    /**
     * Bug: HasSolrResults::getFacets() unconditionally calls
     * $facetSet->count(), but Result::getFacetSet() returns null whenever the
     * select query that produced the result never initialized the facetset
     * component. SolrEngine::performSearch() always initializes it, but any
     * result created another way (a hand-built query, or a future engine
     * path) makes getFacets() fatal instead of returning null.
     */
    public function testGetFacetsFatalsWhenTheResultHasNoFacetSetComponent()
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

        // preconditions: Solarium legitimately gives us a null facetset
        $this->assertNull($results->getFacetSet());

        $collection = new SolrCollection();
        $collection->setResults($results);

        $this->expectException(\Error::class);
        $this->expectExceptionMessageMatches('/Call to a member function count\(\) on null/');
        $collection->getFacets();
    }
}
