<?php

namespace Scout\Solr\Engines;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\LazyCollection;
use InvalidArgumentException;
use Laravel\Scout\Builder as BaseBuilder;
use Laravel\Scout\Engines\Engine;
use Scout\Solr\Builder;
use Solarium\Client as SolariumClient;
use Solarium\Core\Query\AbstractQuery as SolariumAbstractQuery;
use Solarium\Core\Query\Helper;
use Solarium\QueryType\Select\Query\Query as SolariumSelect;
use Solarium\QueryType\Select\Result\Result as SelectResult;

class SolrEngine extends Engine
{
    public const NESTED_QUERY = '_nested_';
    public const SIMPLE_QUERY = '_simple_';

    /**
     * The Solarium client we are currently using.
     */
    private SolariumClient $client;

    /**
     * Access to the query helper.
     */
    private Helper $helper;

    /**
     * Is searching/updating disabled for this instance.
     */
    private bool $enabled = true;

    /**
     * Make the key for the meta values in the searchable array configurable.
     */
    private ?string $metaKey = null;

    /**
     * Store the last result created allowing us to attach it to collection results.
     *
     * @var SelectResult|null
     */
    private $lastSelectResult = null;

    /**
     * Constructor takes an initialized Solarium client as its only parameter.
     *
     * @param SolariumClient $client The Solarium client to use
     */
    public function __construct(SolariumClient $client, ConfigRepository $config)
    {
        $this->client = $client;
        $this->helper = $client->createSelect()->getHelper();
        $this->enabled = (bool) $config->get('solr.enabled', true);
        $this->metaKey = $config->get('solr.meta_key', 'meta');
    }

    /**
     * Update the given model in the index.
     *
     * @param \Illuminate\Database\Eloquent\Collection $models
     * @return void
     */
    public function update($models)
    {
        if (! $this->enabled || $models->isEmpty()) {
            return;
        }

        $model = $models->first();
        $update = $this->client->createUpdate();
        $documents = $models
            ->map(
                /**
                 * @return false|\Solarium\QueryType\Update\Query\Document
                 */
                function ($model) use ($update) {
                    /** @var \Solarium\QueryType\Update\Query\Document */
                    $document = $update->createDocument();
                    /** @var \Scout\Solr\Searchable $model */
                    $attrs = $model->toSearchableArray();
                    if (empty($attrs)) {
                        return false;
                    }
                    // introduce functionality for solr meta data
                    if (array_key_exists($this->metaKey, $attrs)) {
                        $meta = $attrs[$this->metaKey];
                        // check if there are boosts to apply to the document
                        if (array_key_exists('boosts', $meta)) {
                            $boosts = $meta['boosts'];
                            if (array_key_exists('document', $boosts)) {
                                if (is_numeric($boosts['document'])) {
                                    $document->setBoost((float) $boosts['document']);
                                }
                                unset($boosts['document']);
                            }
                            foreach ($boosts as $field => $boost) {
                                if (is_numeric($boost)) {
                                    $document->setFieldBoost($field, (float) $boost);
                                }
                            }
                        }
                        unset($attrs[$this->metaKey]);
                    }
                    // leave this extra here to allow for modification if needed
                    foreach ($attrs as $key => $attr) {
                        $document->$key = $attr;
                    }
                    $class = is_object($model) ? get_class($model) : false;
                    if ($class) {
                        $document->_modelClass = $class;
                    }

                    return $document;
                }
            )
            ->filter();
        $update->addDocuments($documents->toArray());
        $update->addCommit();
        $this->client->update($update, $model->searchableAs());
    }

    /**
     * Remove the given model from the index.
     *
     * @param  \Illuminate\Database\Eloquent\Collection  $models
     * @return void
     */
    public function delete($models)
    {
        if (! $this->enabled || $models->isEmpty()) {
            return;
        }

        $model = $models->first();
        $delete = $this->client->createUpdate();
        $endpoint = $model->searchableAs();
        $ids = $models->map(function ($model) {
            return $model->getScoutKey();
        });
        $delete->addDeleteByIds($ids->all());
        $delete->addCommit();
        $this->client->update($delete, $endpoint);
    }

    /**
     * Perform the given search on the engine.
     *
     * @param BaseBuilder $builder
     *
     * @return mixed
     */
    public function search(BaseBuilder $builder)
    {
        if (! $this->enabled) {
            return SupportCollection::make();
        }

        return $this->performSearch($builder);
    }

    /**
     * Perform the given search on the engine.
     *
     * @param BaseBuilder $builder
     * @param int  $perPage
     * @param int  $page
     *
     * @return mixed
     */
    public function paginate(BaseBuilder $builder, $perPage, $page)
    {
        if (! $this->enabled) {
            return SupportCollection::make();
        }

        //decrement the page number as we're actually dealing with an offset, not page number
        $page--;
        $builder->take($perPage);

        return $this->performSearch($builder, [
            'start' => $page * $perPage,
        ]);
    }

    /**
     * Pluck and return the primary keys of the given results.
     *
     * @param  SelectResult $results
     * @return SupportCollection
     */
    public function mapIds($results)
    {
        return $this->mapIdsFrom($results, 'id');
    }

    /**
     * Pluck and return the primary keys of the given results using the given key name.
     *
     * @param  SelectResult $results
     * @param  string $key
     * @return SupportCollection
     */
    public function mapIdsFrom($results, $key)
    {
        if (! is_countable($results) || count($results) === 0) {
            return SupportCollection::make();
        }

        return SupportCollection::make($results)
            ->pluck($key)
            ->values();
    }

    /**
     * Map the given results to instances of the given model.
     *
     * @param BaseBuilder $builder
     * @param SelectResult|SupportCollection  $results
     * @param \Illuminate\Database\Eloquent\Model  $model
     *
     * @return Collection
     */
    public function map(BaseBuilder $builder, $results, $model)
    {
        if (! is_iterable($results) || count($results) === 0) {
            return $model->newCollection([]);
        }

        $ids = SupportCollection::make($results)
            ->pluck($model->getScoutKeyName())
            ->values();

        // TODO: Is there a better way to handle including faceting on a mapped result?
        $facets = [];
        if ($results instanceof SelectResult && $results->getFacetSet() !== null) {
            $facetSet = $results->getFacetSet();
            if ($facetSet->count() > 0) {
                $facets = $facetSet->getFacets();
            }
        }

        $spellcheck = $results instanceof SelectResult ? $results->getSpellcheck() : null;
        // TODO: (cont'd) Because attaching facets to every model feels kludgy
        // solution is to implement a custom collection class that can hold the facets
        $models = $model
            ->whereIn($model->getKeyName(), $ids)
            ->orderByRaw($this->orderQuery($model, $ids))
            ->get()
            ->map(function ($item) use ($facets, $spellcheck) {
                $item->facets = $facets;
                $item->spellcheck = $spellcheck;

                return $item;
            });

        return $models;
    }

    /**
     * Map the given results to instances of the given model via a lazy collection.
     *
     * @param BaseBuilder $builder
     * @param SelectResult|SupportCollection  $results
     * @param \Illuminate\Database\Eloquent\Model  $model
     */
    public function lazyMap(BaseBuilder $builder, $results, $model): LazyCollection
    {
        if (! is_iterable($results) || count($results) === 0) {
            return LazyCollection::make();
        }

        $ids = SupportCollection::make($results)
            ->pluck($model->getScoutKeyName())
            ->values();

        $models = $model
            ->whereIn($model->getKeyName(), $ids)
            ->get()
            ->keyBy($model->getKeyName());

        return LazyCollection::make($ids)
            ->map(fn ($id) => $models[$id] ?? null)
            ->filter()
            ->values();
    }

    /**
     * Get the total count from a raw result returned by the engine.
     *
     * @param  SelectResult|SupportCollection  $results
     * @return int
     */
    public function getTotalCount($results): int
    {
        if (! $results instanceof SelectResult) {
            return 0;
        }

        return $results->getNumFound();
    }

    /**
     * Flush all of the model's records from the engine.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return void
     */
    public function flush($model)
    {
        $class = is_object($model) ? get_class($model) : false;
        if ($class) {
            $update = $this->client->createUpdate();
            $update->addDeleteQuery("_modelClass:$class");
            $update->addCommit();
            $this->client->update($update, $model->searchableAs());
        }
    }

    /**
     * Create a search index (Solr core) with the given name.
     *
     * @param  string  $name
     * @param  array  $options
     * @return \Solarium\QueryType\Server\CoreAdmin\Result\Result
     */
    public function createIndex($name, array $options = [])
    {
        $query = $this->client->createCoreAdmin();
        $create = $query->createCreate()->setCore($name);
        if (isset($options['instance_dir'])) {
            $create->setInstanceDir((string) $options['instance_dir']);
        }
        if (isset($options['configset'])) {
            $create->setConfigSet((string) $options['configset']);
        }
        // Solarium 7 no longer attaches the created action to the query
        // automatically, so it must be set explicitly.
        $query->setAction($create);

        return $this->client->coreAdmin($query);
    }

    /**
     * Delete a search index (unload the Solr core with the given name).
     *
     * @param  string  $name
     * @return \Solarium\QueryType\Server\CoreAdmin\Result\Result
     */
    public function deleteIndex($name)
    {
        $query = $this->client->createCoreAdmin();
        // Solarium 7 no longer attaches the created action to the query
        // automatically, so it must be set explicitly.
        $query->setAction(
            $query
                ->createUnload()
                ->setCore($name)
                ->setDeleteDataDir(true)
                ->setDeleteIndex(true)
        );

        return $this->client->coreAdmin($query);
    }

    /**
     * Actually perform the search, allows for options to be passed like pagination.
     *
     * @param Builder|BaseBuilder $builder The query builder we were passed
     * @param array $options An array of options to use to do things like pagination, faceting?
     *
     * @return SelectResult The results of the query
     */
    protected function performSearch($builder, array $options = [])
    {
        if (! $builder instanceof Builder) {
            throw new \RuntimeException(
                'Your model must use the Scout\\Solr\\Searchable trait in place of Laravel\\Scout\\Searchable'
            );
        }
        $endpoint = $builder->model->searchableAs();
        // build the query string for the q parameter
        if (is_array($builder->query)) {
            $queryString = $this->compileArrayQuery($builder->query);
        } else {
            $queryString = $builder->query;
        }
        /** @var SolariumSelect $query */
        $query = $this->client->createSelect();
        if ($builder->isEDismax()) {
            $dismax = $query->getEDisMax();
        } elseif ($builder->isDismax()) {
            $dismax = $query->getDisMax();
        }

        if (isset($dismax) && empty($queryString)) {
            $dismax->setQueryAlternative('*:*');
        }
        $query->setQuery($queryString);

        // get the filter query
        $filters = [];
        // loop through and merge any `OR` queries into one
        foreach ($builder->wheres as $where) {
            if (isset($where['type']) && $where['type'] === static::NESTED_QUERY) {
                $queryPiece = $this->compileNestedQuery($where);
            } elseif (isset($where['type']) && $where['type'] === static::SIMPLE_QUERY) {
                $queryPiece = $this->helper->assemble($where['query'], (array) $where['bindings']);
            } elseif (isset($where['field'])) {
                // Scout-native where shape (soft deletes, Builder::where($field, $operator, $value))
                $queryPiece = $this->compileScoutWhere($where);
            } else {
                continue;
            }
            if (! empty($filters) && ($where['boolean'] ?? 'AND') === 'OR') {
                $previous = array_pop($filters);
                $queryPiece = "{$previous} OR ({$queryPiece})";
            }
            $filters[] = $queryPiece;
        }
        // Scout's whereIn()/whereNotIn() filters
        foreach ($builder->whereIns as $field => $values) {
            $terms = implode(' OR ', array_map(fn ($value) => $this->escapeSolrValue($value), $values));
            $filters[] = "$field:($terms)";
        }
        foreach ($builder->whereNotIns as $field => $values) {
            $terms = implode(' OR ', array_map(fn ($value) => $this->escapeSolrValue($value), $values));
            $filters[] = "(*:* -$field:($terms))";
        }
        SupportCollection::make($filters)->each(function (string $fq) use ($query) {
            $query->createFilterQuery(md5($fq))->setQuery($fq);
        });
        // build any faceting
        $facetSet = $query->getFacetSet();
        $facetSet->setOptions($builder->facetOptions);
        if (! empty($builder->facetFields)) {
            foreach ($builder->facetFields as $field) {
                $facetSet->createFacetField("$field-field")->setField($field);
            }
        }
        if (! empty($builder->facetQueries)) {
            foreach ($builder->facetQueries as $field => $queries) {
                if (count($queries) > 1) {
                    $facet = $facetSet->createFacetMultiQuery("$field-multiquery");
                    foreach ($queries as $i => $fQuery) {
                        $facet->createQuery("$field-multiquery-$i", $fQuery);
                    }
                } else {
                    $facetSet->createFacetQuery("$field-query")->setQuery("$field:{$queries[0]}");
                }
            }
        }
        if (! empty($builder->facetPivots)) {
            foreach ($builder->facetPivots as $fields) {
                $facetSet
                    ->createFacetPivot(implode('-', $fields))
                    ->addFields(implode(',', $fields));
            }
        }

        // set up spellchecking
        if ($builder->getUseSpellcheck()) {
            $spellcheck = $query->getSpellcheck();
            $spellcheck->setOptions($builder->getSpellcheckOptions());
        }

        // Set the boost fields
        if (isset($dismax) && $builder->hasBoosts()) {
            $dismax->setQueryFields($builder->getBoosts());
        }

        // allow for pagination here
        if ($builder->getStart() !== null) {
            $query->setStart((int) $builder->getStart());
        } elseif (array_key_exists('start', $options)) {
            $query->setStart((int) $options['start']);
        }
        // add ordering to the search
        if ($builder->orders) {
            foreach ($builder->orders as $sort) {
                $query->addSort($sort['column'], $sort['direction']);
            }
        }
        // if a row limit is set, include that
        if ($builder->limit) {
            $query->setRows((int) $builder->limit);
        }

        if ($builder->getDebug()) {
            $query->getDebug();
        }

        if (count($builder->getQueryParams()) > 0) {
            foreach ($builder->getQueryParams() as $key => $value) {
                $query->addParam($key, $value);
            }
        }

        // Scout contract: give the search() callback a last shot at the query
        if (is_callable($builder->callback)) {
            $replacement = call_user_func($builder->callback, $query, $queryString);
            if ($replacement instanceof SolariumAbstractQuery) {
                $query = $replacement;
            }
        }

        $this->lastSelectResult = $this->client->select($query, $endpoint);

        return $this->lastSelectResult;
    }

    /**
     * Compile a recursive array query specification into a Solr query string.
     *
     * Nested arrays become parenthesized groups so OR/AND grouping survives the
     * round-trip (fixing the "[] operator not supported" fatal on grouped inputs).
     */
    private function compileArrayQuery(array $query): string
    {
        $parts = [];
        foreach ($query as $key => $value) {
            $prefix = is_numeric($key) ? '' : "$key:";
            if (is_array($value)) {
                $parts[] = $prefix.'('.$this->compileArrayQuery($value).')';
            } else {
                $parts[] = $prefix.$value;
            }
        }

        return implode(' ', $parts);
    }

    /**
     * Translate a Scout-native where entry into a Solr filter query string.
     *
     * Handles soft-delete `__soft_deleted` entries and calls like
     * `where('category', 'books')` that reach the builder in Scout's
     * `['field', 'operator', 'value']` shape.
     */
    private function compileScoutWhere(array $where): ?string
    {
        if (! array_key_exists('value', $where)) {
            return null;
        }

        $field = (string) $where['field'];
        $operator = strtoupper((string) ($where['operator'] ?? '='));
        $value = $where['value'];
        $term = $this->escapeSolrValue($value);

        return match ($operator) {
            '!=', '<>' => "(*:* -{$field}:{$term})",
            '>' => "{$field}:{{$term} TO *}",
            '>=' => "{$field}:[{$term} TO *]",
            '<' => "{$field}:{{* TO {$term}}}",
            '<=' => "{$field}:[* TO {$term}]",
            default => "{$field}:{$term}",
        };
    }

    /**
     * Escape an arbitrary scalar for use inside a filter query value.
     *
     * @param  mixed  $value
     */
    private function escapeSolrValue($value): string
    {
        $value = (string) $value;
        if (strpos($value, ' ') !== false) {
            return $this->helper->escapePhrase($value);
        }

        return $this->helper->escapeTerm($value);
    }

    /**
     * Takes a nested set of queries and turns it into a single query string (pre-compiled).
     *
     * Nested groups are wrapped in parentheses so the boolean boundaries survive
     * serialization (e.g. `a:1 AND (b:2 OR c:3)` stays grouped).
     *
     * @param array $where The nested query array to compile
     */
    public function compileNestedQuery($where): string
    {
        $first = true;
        $query = '';
        foreach ($where['queries'] as $subWhere) {
            if ($subWhere['type'] === static::NESTED_QUERY) {
                $queryPart = '('.$this->compileNestedQuery($subWhere).')';
            } elseif ($subWhere['type'] === static::SIMPLE_QUERY) {
                $queryPart = $this->helper->assemble($subWhere['query'], (array) $subWhere['bindings']);
            } elseif (isset($subWhere['field'])) {
                $queryPart = $this->compileScoutWhere($subWhere) ?? '';
            } else {
                continue;
            }
            if (! $first) {
                $query .= " {$subWhere['boolean']} {$queryPart}";
            } else {
                $query = $queryPart;
                $first = false;
            }
        }

        return $query;
    }

    /**
     * Return the result from the last search query for faceting support.
     *
     * @return SelectResult|null
     */
    public function getLastSelectResult()
    {
        return $this->lastSelectResult;
    }

    /**
     * Return the appropriate sorting(ranking) query for the SQL driver.
     *
     * @param \Illuminate\Database\Eloquent\Model  $model
     * @param  \Illuminate\Support\Collection  $ids
     */
    private function orderQuery($model, $ids): string
    {
        $driver = $model->getConnection()->getDriverName();
        $model_key = $model->getKeyName();
        $query = '';

        if ($driver == 'pgsql') {
            foreach ($ids as $id) {
                $query .= sprintf('%s=%s desc, ', $model_key, $id);
            }
            $query = rtrim($query, ', ');
        } elseif ($driver == 'mysql') {
            $id_list = SupportCollection::make($ids)->implode(',');
            $query = sprintf('FIELD(%s, %s)', $model_key, $id_list);
        } else {
            throw new InvalidArgumentException('The SQL driver is not supported.');
        }

        return $query;
    }

    /**
     * Ensure the query term is solr safe.
     *
     * @param string $query
     * @return string
     */
    public function escapeQueryAsTerm(string $query): string
    {
        return $this->helper->escapeTerm($query);
    }

    /**
     * Ensure the query phrase is solr safe.
     *
     * @param string $query
     * @return string
     */
    public function escapeQueryAsPhrase(string $query): string
    {
        return $this->helper->escapePhrase($query);
    }
}
