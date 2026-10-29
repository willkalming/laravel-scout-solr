---
footer: MIT Licensed | Copyright © 2017-Present Will Kruse
title: v3 Plan
---

# v3 Plan #

The `v3` release line modernizes `laravel-scout-solr` in two major hops. Work starts
on the `release/v3` branch, and each major hop is developed on its own branch and
merged back into `release/v3`:

| Hop | Branch | Summary |
| --- | --- | --- |
| 0 | `release/v3` | Planning, issue tracking, integration point for the hops below |
| 1 | `release/v3-tests` | A [devenv](https://devenv.sh) based PHP toolchain and a full unit + regression test suite for the *current* code |
| 2 | `release/v3-php-scout` | Upgrade to the latest PHP, latest Laravel Scout, and latest Solarium |

## Hop 1 — Test suite (`release/v3-tests`) ##

Goals:

* Bring up a reproducible PHP toolchain for the repo via `devenv` (PHP, Composer, PHPUnit).
* Add a PHPUnit test suite covering every public entry point of the library:
  * `Scout\Solr\Builder` — facets, boosts, wheres (simple, nested, OR), spellcheck,
    debug, query params, start/limit.
  * `Scout\Solr\Engines\SolrEngine` — update/delete documents, query string building,
    filter query merging (including the OR merge), pagination math, `mapIds`,
    `getTotalCount`, escaping helpers, flush, disabled-mode behavior.
  * `Scout\Solr\HasSolrResults` / `SolrCollection` — result attachment and facet/spellcheck accessors.
  * `Scout\Solr\ScoutSolrServiceProvider` — engine registration and client binding.
* Tests run against a **fake Solr transport** (a stubbed Solarium adapter) so no live
  Solr server is needed in CI or locally.
* Regression tests pin down the bugs found during this work (see [issues](/issues.html)),
  so the upgrade hop cannot silently change behavior.

Implementation notes:

* The suite runs against `laravel/scout` 7.2.1 + `illuminate/*` 7.30.6 + `solarium/solarium`
  4.2.0. This baseline matches the current Scout `Engine` contract and coexists with
  the repo's existing Psalm 3 / Solarium 4 dependencies. The attempted Scout 9 /
  Illuminate 9 baseline required Symfony Console 6, conflicting with Psalm 3.
  Updating those constraints is hop 2's job.
* All PHP/Composer/PHPUnit commands run through devenv (`devenv shell -- ...`) on PHP 8.5;
  `error_reporting` in `phpunit.xml` suppresses E_DEPRECATED from the legacy dependencies.

## Hop 2 — PHP + Scout + Solarium upgrade (`release/v3-php-scout`) ##

Targets (latest as of October 2026):

| Component | Current | Target |
| --- | --- | --- |
| PHP | `^7.1` | `^8.3` (tested on 8.5) |
| `laravel/scout` | `>=5.0` | `^11.0` |
| `illuminate/*` | `>=5.5` | `^12.0\|^13.0` |
| `solarium/solarium` | `^4.2` | `^7.0` |

Key changes that have to be handled:

1. **Scout 11 `Engine` contract** — new abstract methods `lazyMap`, `createIndex`,
   `deleteIndex`; `mapIdsFrom`/`keys`/`get`/`cursor` flows; wheres now use the
   `['field' =>, 'operator' =>, 'value' =>]` shape (including `__soft_deleted` entries),
   so the engine must understand both the Scout shape and this package's
   `SIMPLE_QUERY`/`NESTED_QUERY` shapes.
2. **Solarium 7 client construction** — `new Client(['endpoint' => [...]])` no longer
   works; the client now takes an adapter and a PSR-14 event dispatcher. The service
   provider has to build these.
3. **Index-time boosts** — Solarium 7 marks document/field boosts as deprecated
   (Solr removed them in Solr 7); query-time boosts (dismax `qf`) remain the supported path.
4. **PHP 8.x** — implicit nullable parameters, typed properties, `strict_types`, and
   removal of loose behaviors the old code relied on.

Both hops record every behavioral bug discovered along the way in
[docs/issues.md](/issues.html).
