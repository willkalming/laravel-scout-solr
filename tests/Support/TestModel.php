<?php

namespace Scout\Solr\Tests\Support;

use Illuminate\Database\Eloquent\Model;
use Scout\Solr\Searchable;

class TestModel extends Model
{
    use Searchable;

    protected $guarded = [];
    protected $table = 'books';

    // No observers or queue bindings are needed for transport-level engine tests.
    public static function bootSearchable() {}

    public function searchableAs() { return 'books'; }
}
