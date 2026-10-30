<?php

namespace Scout\Solr;

use Solarium\Component\Result\Spellcheck\Suggestion;

trait HasSolrResults
{
  /**
   * The raw solr results.
   *
   * @var \Solarium\QueryType\Select\Result\Result|null
   */
  private $results = null;

  /**
   * The spellcheck component or null if not present.
   *
   * @var \Solarium\Component\Result\Spellcheck\Result|null
   */
  private $spellcheck = null;

  /**
   * The facet component or null if not present.
   *
   * @var \Solarium\Component\Result\FacetSet|null
   */
  private $facetSet = null;

  /**
   * Set the results object on this collection.
   *
   * @param \Solarium\QueryType\Select\Result\Result|null $results
   * @return void
   */
  public function setResults($results)
  {
    $this->results = $results;
  }

  /**
   * Get the facet set of the results.
   *
   * @return \Solarium\Component\Result\FacetSet|null
   */
  public function getFacetSet()
  {
    $this->facetSet = $this->facetSet ?? $this->results?->getFacetSet();
    return $this->facetSet;
  }

  /**
   * Get the facets from the facet set.
   *
   * @return \Solarium\Component\Result\FacetInterface[]|null
   */
  public function getFacets()
  {
    $facetSet = $this->getFacetSet();
    if ($facetSet !== null && $facetSet->count() > 0) {
      return $facetSet->getFacets();
    } else {
      return null;
    }
  }

  /**
   * Get the spellcheck results.
   *
   * @return \Solarium\Component\Result\Spellcheck\Result|null
   */
  public function getSpellcheck()
  {
    $this->spellcheck = $this->spellcheck ?? $this->results?->getSpellcheck();
    return $this->spellcheck;
  }

  /**
   * Get the suggestions from the spellcheck results.
   *
   * @return Suggestion[]|null
   */
  public function getSpellcheckSuggestions()
  {
    $spellcheck = $this->getSpellcheck();
    if ($spellcheck) {
      return $spellcheck->getSuggestions();
    }
  }

  /**
   * Get the collated spellcheck suggestions.
   *
   * @return Collation[]|null
   */
  public function getSpellcheckCollations()
  {
    $spellcheck = $this->getSpellcheck();
    if ($spellcheck) {
      return $spellcheck->getCollations();
    }
  }
}
