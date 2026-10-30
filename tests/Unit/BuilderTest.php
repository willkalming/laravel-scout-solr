<?php

namespace Scout\Solr\Tests\Unit;

use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\TestCase;
use Scout\Solr\Builder;
use Scout\Solr\Engines\SolrEngine;
use Solarium\Core\Query\Helper;

class BuilderTest extends TestCase
{
    /**
     * Build a model instance without booting a Laravel application.
     */
    protected function makeModel(): Model
    {
        return new class extends Model {
        };
    }

    protected function makeBuilder($query = 'lorem'): Builder
    {
        return new Builder($this->makeModel(), $query);
    }

    public function testItExtendsTheScoutBuilderWithEmptyDefaults()
    {
        $builder = $this->makeBuilder();

        $this->assertSame([], $builder->wheres);
        $this->assertSame([], $builder->facetOptions);
        $this->assertSame([], $builder->facetFields);
        $this->assertSame([], $builder->facetQueries);
        $this->assertSame([], $builder->facetPivots);
        $this->assertFalse($builder->hasBoosts());
        $this->assertSame('', $builder->getBoosts());
        $this->assertSame([], $builder->getBoostsArray());
        $this->assertFalse($builder->isDismax());
        $this->assertFalse($builder->isEDismax());
        $this->assertNull($builder->getStart());
        $this->assertFalse($builder->getUseSpellcheck());
        $this->assertSame([], $builder->getSpellcheckOptions());
        $this->assertFalse($builder->getDoAutoSpellcheckSearch());
        $this->assertFalse($builder->getDebug());
        $this->assertSame([], $builder->getQueryParams());
    }

    public function testWhereAddsASimpleFilterQueryAndIsFluent()
    {
        $builder = $this->makeBuilder();

        $result = $builder->where('title:%P1%', ['foo bar']);

        $this->assertSame($builder, $result);
        $this->assertCount(1, $builder->wheres);
        $this->assertSame([
            'type' => SolrEngine::SIMPLE_QUERY,
            'query' => 'title:%P1%',
            'bindings' => ['foo bar'],
            'boolean' => 'AND',
        ], $builder->wheres[0]);
    }

    public function testOrWhereMarksTheBooleanAsOr()
    {
        $builder = $this->makeBuilder();
        $builder->where('a:1')->orWhere('b:2');

        $this->assertSame('AND', $builder->wheres[0]['boolean']);
        $this->assertSame('OR', $builder->wheres[1]['boolean']);
        $this->assertSame([], $builder->wheres[1]['bindings']);
    }

    public function testWhereAcceptsAClosureAndStoresANestedQuery()
    {
        $builder = $this->makeBuilder();

        $builder->where(function (Builder $nested) {
            $nested->where('status:active')->orWhere('status:pending');
        });

        $this->assertCount(1, $builder->wheres);
        $nested = $builder->wheres[0];
        $this->assertSame(SolrEngine::NESTED_QUERY, $nested['type']);
        $this->assertSame('AND', $nested['boolean']);
        $this->assertCount(2, $nested['queries']);
        $this->assertSame('status:active', $nested['queries'][0]['query']);
        $this->assertSame('AND', $nested['queries'][0]['boolean']);
        $this->assertSame('status:pending', $nested['queries'][1]['query']);
        $this->assertSame('OR', $nested['queries'][1]['boolean']);
    }

    public function testNestedWhereClausesMayContainDeeperNesting()
    {
        $builder = $this->makeBuilder();

        $builder->where(function (Builder $nested) {
            $nested->where('status:active');
            $nested->where(function (Builder $deeper) {
                $deeper->where('title:foo')->orWhere('title:bar');
            });
        });

        $queries = $builder->wheres[0]['queries'];
        $this->assertSame(SolrEngine::SIMPLE_QUERY, $queries[0]['type']);
        $this->assertSame(SolrEngine::NESTED_QUERY, $queries[1]['type']);
        $this->assertSame('title:bar', $queries[1]['queries'][1]['query']);
    }

    public function testWhereRangeBuildsASolariumPlaceholderQuery()
    {
        $builder = $this->makeBuilder();

        $builder->whereRange('price', '10', '20');

        $this->assertSame([
            'type' => SolrEngine::SIMPLE_QUERY,
            'query' => 'price:[%L1% TO %L2%]',
            'bindings' => ['10', '20'],
            'boolean' => 'AND',
        ], $builder->wheres[0]);
    }

    public function testWhereRangeAcceptsAPlaceholderModeAndBoolean()
    {
        $builder = $this->makeBuilder();

        $builder->whereRange('name', 'foo bar', 'zap', 'T', 'OR');

        $this->assertSame('name:[%T1% TO %T2%]', $builder->wheres[0]['query']);
        $this->assertSame(['foo bar', 'zap'], $builder->wheres[0]['bindings']);
        $this->assertSame('OR', $builder->wheres[0]['boolean']);
    }

    /**
     * Pin the Solarium placeholder contract the range builder relies on,
     * since SolrEngine::compileNestedQuery assembles them verbatim.
     */
    public function testRangePlaceholdersAssembleThroughTheSolariumHelper()
    {
        $helper = new Helper();

        $this->assertSame(
            'price:[10 TO 20]',
            $helper->assemble('price:[%L1% TO %L2%]', ['10', '20'])
        );
        // term mode escapes, literal mode passes through untouched
        $escaped = $helper->assemble('name:[%T1% TO %L2%]', ['foo bar', 'zap']);
        $this->assertSame('name:[foo\ bar TO zap]', $escaped);
    }

    public function testFacetFieldsAreCollectedInOrder()
    {
        $builder = $this->makeBuilder();

        $result = $builder->facetField('category')->facetField('state');

        $this->assertSame($builder, $result);
        $this->assertSame(['category', 'state'], $builder->facetFields);
    }

    public function testFacetQueriesStackUnderTheirField()
    {
        $builder = $this->makeBuilder();

        $builder
            ->facetQuery('price', '[* TO 10]')
            ->facetQuery('price', '[10 TO 100]')
            ->facetQuery('state', 'active');

        $this->assertSame([
            'price' => ['[* TO 10]', '[10 TO 100]'],
            'state' => ['active'],
        ], $builder->facetQueries);
    }

    public function testFacetPivotsAreCollectedAsFieldLists()
    {
        $builder = $this->makeBuilder();

        $result = $builder->facetPivot(['state', 'city'])->facetPivot(['category']);

        $this->assertSame($builder, $result);
        $this->assertSame([['state', 'city'], ['category']], $builder->facetPivots);
    }

    public function testFacetOptionsCanBeSetAndOverwritten()
    {
        $builder = $this->makeBuilder();

        $builder->setFacetOption('limit', 5)->setFacetOption('limit', 10)->setFacetOption('mincount', 1);

        $this->assertSame(['limit' => 10, 'mincount' => 1], $builder->facetOptions);
    }

    public function testBoostFieldsRenderAsDismaxQueryFieldStrings()
    {
        $builder = $this->makeBuilder('lorem');

        $builder
            ->boostField('title', 3)
            ->boostField('body', 2);

        $this->assertTrue($builder->hasBoosts());
        $this->assertSame('title^3 body^2', $builder->getBoosts());
        $this->assertSame(['title' => 'title^3', 'body' => 'body^2'], $builder->getBoostsArray());
    }

    /**
     * Fixed: all numeric boosts equal to 1 (int, float or numeric string) are
     * reduced to the bare field; only "", null and non-unity values render ^n.
     */
    public function testUnityBoostsAreDroppedForAllNumericShapes()
    {
        $builder = $this->makeBuilder('lorem');

        $builder
            ->boostField('plain')
            ->boostField('unity', 1)
            ->boostField('stringy', '1')
            ->boostField('floaty', 1.0);

        $this->assertSame('plain unity stringy floaty', $builder->getBoosts());
        $this->assertTrue($builder->hasBoosts());
    }

    public function testBoostingAQueryContainingAWildcardSelectsTheEDismaxParser()
    {
        $builder = $this->makeBuilder('title:foo*');

        $builder->boostField('title', 2);

        $this->assertTrue($builder->isEDismax());
        $this->assertFalse($builder->isDismax());
    }

    public function testBoostingWithoutAWildcardLeavesTheParserUnset()
    {
        $builder = $this->makeBuilder('title:foo');

        $builder->boostField('title', 2);

        $this->assertFalse($builder->isEDismax());
        $this->assertFalse($builder->isDismax());
    }

    public function testAWildcardQueryAloneDoesNotSelectAParser()
    {
        $builder = $this->makeBuilder('title:foo*');

        $this->assertFalse($builder->isEDismax());
        $this->assertFalse($builder->isDismax());
    }

    /**
     * Fixed (issue 21): boosting an array-shaped query no longer crashes the
     * builder; the parser selection simply skips non-string queries.
     */
    public function testBoostingAnArrayQuerySelectsDismaxInsteadOfCrashing()
    {
        $builder = $this->makeBuilder(['title' => 'lorem', 'body' => 'ipsum*']);

        $builder->boostField('title', 2);

        $this->assertFalse($builder->isEDismax());
        $this->assertSame(['title' => 'title^2'], $builder->getBoostsArray());
    }

    /**
     * Fixed (issue 5): Scout-native where() calls are stored in Scout's where
     * shape so the engine can translate them.
     */
    public function testScoutShapedWheresAreStoredForTranslation()
    {
        $builder = $this->makeBuilder();

        $builder->where('category', 'books');

        $this->assertSame(
            ['field' => 'category', 'operator' => '=', 'value' => 'books'],
            $builder->wheres[0]
        );

        $builder->where('price', '>', 10);

        $this->assertSame(
            ['field' => 'price', 'operator' => '>', 'value' => 10],
            $builder->wheres[1]
        );
    }

    public function testWhereInAndWhereNotInKeepScoutShape()
    {
        $builder = $this->makeBuilder();

        $builder->whereIn('category', ['fiction', 'history'])->whereNotIn('state', ['archived']);

        $this->assertSame(['category' => ['fiction', 'history']], $builder->whereIns);
        $this->assertSame(['state' => ['archived']], $builder->whereNotIns);
    }

    public function testUseDismaxAndUseEDismaxAreFluent()
    {
        $builder = $this->makeBuilder();

        $this->assertSame($builder, $builder->useDismax());
        $this->assertTrue($builder->isDismax());
        $this->assertFalse($builder->isEDismax());

        $this->assertSame($builder, $builder->useEDismax());
        $this->assertTrue($builder->isEDismax());
        // setting edismax does not unset dismax
        $this->assertTrue($builder->isDismax());
    }

    public function testStartOffsetRoundTrips()
    {
        $builder = $this->makeBuilder();

        $this->assertNull($builder->getStart());
        $this->assertSame($builder, $builder->setStart(42));
        $this->assertSame(42, $builder->getStart());
    }

    public function testSpellcheckEnablesTheComponentAndStoresOptions()
    {
        $builder = $this->makeBuilder();

        $this->assertSame($builder, $builder->spellcheck(['count' => 3]));
        $this->assertTrue($builder->getUseSpellcheck());
        $this->assertSame(['count' => 3], $builder->getSpellcheckOptions());
    }

    public function testAutoSpellcheckSearchForcesTheCollateOption()
    {
        $builder = $this->makeBuilder();

        $builder->spellcheck(['count' => 3])->autoSpellcheckSearch();

        $this->assertTrue($builder->getUseSpellcheck());
        $this->assertTrue($builder->getDoAutoSpellcheckSearch());
        $this->assertSame(['count' => 3, 'collate' => true], $builder->getSpellcheckOptions());
    }

    /**
     * Characterization: calling autoSpellcheckSearch() without spellcheck()
     * sets the collate option but does NOT enable the spellcheck component,
     * so SolrEngine will never attach spellcheck params to the request.
     */
    public function testAutoSpellcheckSearchByItselfDoesNotEnableTheSpellcheckComponent()
    {
        $builder = $this->makeBuilder();

        $builder->autoSpellcheckSearch();

        $this->assertTrue($builder->getDoAutoSpellcheckSearch());
        $this->assertSame(['collate' => true], $builder->getSpellcheckOptions());
        $this->assertFalse($builder->getUseSpellcheck());
    }

    public function testDebugFlagDefaultsToOffAndToggles()
    {
        $builder = $this->makeBuilder();

        $this->assertFalse($builder->getDebug());
        $this->assertSame($builder, $builder->debug());
        $this->assertTrue($builder->getDebug());
        $builder->debug(false);
        $this->assertFalse($builder->getDebug());
    }

    public function testQueryParamsAccumulateAndOverwriteByKey()
    {
        $builder = $this->makeBuilder();

        $builder
            ->queryParam('defType', 'edismax')
            ->queryParam('mm', '75%')
            ->queryParam('mm', '50%');

        $this->assertSame(['defType' => 'edismax', 'mm' => '50%'], $builder->getQueryParams());
    }

    public function testSetQueryParamsReplacesPriorParams()
    {
        $builder = $this->makeBuilder();

        $builder->queryParam('defType', 'edismax')->setQueryParams(['fq' => 'status:active']);

        $this->assertSame(['fq' => 'status:active'], $builder->getQueryParams());
    }
}
