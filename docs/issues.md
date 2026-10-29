---
footer: MIT Licensed | Copyright © 2017-Present Will Kruse
title: Issues & Bugs
---

# Issues & Bugs #

Bugs and design problems found while building the v3 test suite and upgrade.
Each item records where it lives, why it is a problem, and where it is fixed
(test, upgrade hop, or documented as a known limitation).

## Bugs found in the current code (pre-upgrade) ##

1. **Config namespace mismatch (provider is broken out of the box).**
   `ScoutSolrServiceProvider::register()` merges `config/scout-solr.php` under the
   `scout-solr` config namespace, and `boot()` publishes it as `config_path('scout-solr.php')`.
   But *every consumer* of the config (`SolrEngine::__construct`, the `Solarium\Client`
   singleton binding, `Searchable::searchableAs()`) reads from the `solr.*` namespace
   (`solr.enabled`, `solr.meta_key`, `solr.endpoints`, `solr.core`). Unless the
   application happens to define its own `config/solr.php`, every setting silently
   falls back to defaults (`endpoints => null`, `core => null`). Fixed in the upgrade hop
   by publishing/merging everything under a single `solr` namespace.

2. **`SolrEngine::paginate()` ignores the `enabled` flag.**
   `search()` returns an empty collection when the engine is disabled, but `paginate()`
   calls `performSearch()` directly, so a disabled engine still performs a network
   request (and then `map()`/`getTotalCount()` are fed an unexpected value). A regression
   test pins the disabled behavior; fixed in the upgrade hop.

3. **`SolrCollection` result accessors fatal when no result was attached.**
   `HasSolrResults::getFacetSet()`/`getSpellcheck()` dereference the private
   `$results` object unconditionally, so any `SolrCollection` that never received
   `setResults()` (e.g. everything returned by `Builder::get()`, see issue 12)
   fatals on `getFacetSet()`. Additionally `getFacets()` calls
   `$facetSet->count()` without a null guard, so a result *without* a facetset
   component crashes too (only masked because `performSearch()` always
   initializes the facetset component). Pinned by regression tests; fixed by
   null-guarding in the upgrade hop.

4. **Unknown `wheres` entries silently corrupt filter queries.**
   In `performSearch()` the wheres loop only handles the `NESTED_QUERY` and
   `SIMPLE_QUERY` types. Any other entry (notably Scout's native
   `['field' => …, 'operator' => …, 'value' => …]` shape used for soft deletes since
   Scout 8+) falls through and appends a *stale* `$queryString` — either the previous
   filter or the raw search query — as a filter query. Silent, wrong results. Fixed in
   the upgrade hop by translating Scout-style wheres into Solr filter queries.

5. **`Builder::where()` signature clashes with Scout's `where()`.**
   Since Scout 8/9 the base Scout `Builder::where($field, $operator, $value)` has a
   fixed contract (and Scout itself pushes `__soft_deleted` entries into `wheres`).
   This package's override `where($query, $bindings = [], $boolean = 'AND')` diverges:
   a call like `where('category', 'books')` is misread as "query = 'category'",
   bindings = `'books'` (a *string*, not the array `assemble()` requires). The upgrade
   hop keeps the Solr-native call sites working while teaching the engine to also
   handle the Scout-native shape.

6. **`update()`/`delete()` crash on empty collections.**
   Both call `$models->first()->searchableAs()` without an emptiness check — calling the
   engine directly with an empty `Collection` (bypassing Scout's queue helpers, which do
   guard) fatals. Fixed in the upgrade hop with early returns.

7. **`mapIds()` hardcodes `'id'`.**
   The engine assumes the Solr primary key field is literally `id`, ignoring
   `getScoutKeyName()`. Scout 11 also calls `mapIdsFrom($results, $key)` (default
   implementation just delegates to `mapIds()`), so the default key name must be
   honored. Fixed in the upgrade hop.

8. **Document boosts only apply for `is_float()` values.**
   In `update()` a boost written as an integer literal (e.g. `3`) is silently dropped
   because `is_float(3)` is false. Should accept any numeric. Also note Solr itself
   removed index-time boosts in Solr 7, so the whole mechanism is deprecated — kept
   (working) for BC in v3 but documented.

9. **`flush()` ignores the model's endpoint.**
   `flush()` executes against the Solarium *default* endpoint, while `update()`/
   `delete()`/`search()` target `$model->searchableAs()`. With more than one endpoint
   configured, flushing deletes nothing from the model's core. Fixed in the upgrade hop.

10. **`autoSpellcheckSearch()` is advertised but never implemented.**
    `Builder::autoSpellcheckSearch()` sets flags (and forces `collate`), but nothing ever
    re-runs the search using the collation. Worse, calling only
    `autoSpellcheckSearch()` never enables the spellcheck component at all —
    `getUseSpellcheck()` stays false, so the engine skips the component unless
    `spellcheck()` was also called. The feature is dead code. Documented as a
    known limitation; the flags survive for BC but v3 docs no longer advertise it as
    functional.

11. **`orderQuery()` (MySQL branch) passes a third argument `sprintf` ignores.**
    `sprintf('FIELD(%s, %s)', $model_key, $id_list, 'ASC')` — the `'ASC'` is silently
    discarded (and `FIELD()`'s natural ordering is ascending anyway). Cosmetic, but the
    code reads as if direction were configurable. Cleaned up in the upgrade hop.

12. **`Builder::get()` result attachment no longer works on modern Scout.**
    The override checks `method_exists($models, 'getCollection')`. In Scout ≥ 9
    `Builder::get()` returns an Eloquent collection directly (no `getCollection()`), so
    the raw Solr result is never attached to the collection — and because of issue 3,
    calling `getFacetSet()`/`getSpellcheckSuggestions()` on a collection returned by
    `get()` **fatals** (rather than returning nothing). Fixed in the upgrade hop by
    attaching the result through `SolrCollection`.

13. **Double `->filter()` on the document collection in `update()`.**
    `$documents->filter()` is applied at line 123 *and* again inside
    `$update->addDocuments($documents->filter()->toArray())`. Harmless, but indicates
    missing test coverage of that path. Cleaned up in the upgrade hop.

14. **PHP 8.x compatibility nits in the current code.**
    Implicit nullable parameters (`paginate($perPage = null, …)` on a typed parameter)
    are deprecated as of PHP 8.4; `SolrEngine` mutates `$builder->take($perPage)` /
    `setStart()` in place, and several doc types (`@var [][]`) are malformed. All
    addressed by the upgrade hop.

## Design issues / limitations (documented, not necessarily changed) ##

15. **One core for all models.** `Searchable::searchableAs()` returns
    `config('solr.core')` for every model — there is no per-model core/index naming
    (Scout's own convention is `config('scout.prefix').$this->getTable()`). Combined with
    issue 1 above, the `endpoints` config and `solr.core` are easy to misconfigure. v3
    keeps `searchableAs()` overridable per model (as before) and documents the contract.

16. **`whereRange()` literal mode is an injection foot-gun.** The default placeholder
    mode `L` inserts the low/high values *unescaped* into the filter query. Callers
    dealing with user input should use phrase (`P`) or term (`T`) escaping. Documented.

16a. **Facet keys are engine-suffixed.** Facets attach under keys like
    `category-field`, `price-query` (and dash-joined pivots), not the bare field
    names callers declared. Consumers must know the convention. Kept for BC in v3,
    documented.

16b. **Unity boost is type-sensitive.** `boostField('title', 1)` (int) is reduced to
    the bare field, but `boostField('title', '1')` (string) renders `title^1` because
    the reduction check is `!== 1`. Cosmetic; normalized to numeric comparisons in
    the upgrade hop.

## Issues found building the hop-1 regression suite (Scout 7 baseline) ##

17. **Nested/grouped array queries fatal in the engine.**
    An array query containing a nested group (`['title' => ['one', 'two']]`) reaches
    `performSearch()`'s flat-array path, which treats the nested array as a scalar
    and fatals with *"[] operator not supported for strings"*. Flat numeric/path-keyed
    arrays work; nested groups do not. Pinned by a characterization test; the upgrade
    hop either supports grouped arrays recursively or throws a clear exception.

18. **Nested filters lose their grouping parentheses.**
    A nested closure group is appended to the filter query *without* wrapping
    parentheses, so `a:1 AND (b:2 OR c:3)` is serialized as `a:1 AND b:2 OR c:3` —
    operator precedence changes, and results are silently wrong. Pinned by a
    characterization test; fixed in the upgrade hop by adding a grouped-nested
    serialization path.

19. **The Scout `Builder` callback is never invoked.**
    `Searchable::search($query, $callback)` stores the callback on the builder, and
    Scout's contract is that the engine invokes it before searching (other engines
    do). `SolrEngine::performSearch()` never calls it, so callers cannot apply
    last-minute query adjustments. Pinned by a characterization test; the upgrade
    hop invokes the callback and lets it mutate/return the Solarium query.

20. **Scout ≤ 8's qualified `getScoutKeyName()` breaks `map()`/`orderQuery()`.**
    Scout 7 returns the *qualified* key name (`books.id`); `collect($results)->pluck('books.id')`
    uses dot-path `data_get` semantics and cannot read a document field literally
    named `books.id`, so the ids come back `null` — producing `FIELD(id, ,)` ordering
    SQL and a `whereIn(id, [null])` fetch. Scout ≥ 9 returns the plain key name, so
    the upgrade hop resolves this; pinned by a characterization test on the current
    baseline.

21. **Boosting an array query crashes the builder.**
    `Builder::selectQueryParser()` runs `strpos($this->query, '*')`, but `$query` can
    be an array (the engine's flat-array path accepts one); calling `boostField()`
    on an array query throws a `TypeError`. Pinned by characterization test; fixed
    in the upgrade hop.

## Issues found during the upgrade hop (release/v3-php-scout) ##

To be filled in during the upgrade work: Solarium 7 constructor/BC break, Scout 11
`Engine` contract additions (`lazyMap`, `createIndex`, `deleteIndex`,
`mapIdsFrom`/`keys`/`get`/`cursor`), wheres-shape migration, soft-delete `__soft_deleted`
handling, and deprecation of index-time boosts.
