<?php

namespace Scout\Solr\Tests\Support;

use Illuminate\Database\Eloquent\Model;
use Scout\Solr\Searchable;

/**
 * Model without a searchableAs() override: it reads the core from config,
 * exercising the `solr.core` config key used by the Searchable trait.
 */
class DefaultCoreModel extends Model
{
    use Searchable;

    protected $guarded = [];

    public static function bootSearchable() {}
}
