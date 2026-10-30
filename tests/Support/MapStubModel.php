<?php

namespace Scout\Solr\Tests\Support;

/**
 * Model whose whereIn() returns an in-memory fake Eloquent builder, so the
 * engine's map() + orderQuery() paths can be tested without a real database.
 */
class MapStubModel extends TestModel
{
    public static $lastOrderByRaw;
    public static $fakeRows = [];

    public function whereIn($column, $values, $boolean = 'and', $not = false)
    {
        return new class(self::$fakeRows) {
            private $rows;

            public function __construct(array $rows) { $this->rows = $rows; }

            public function orderByRaw($sql)
            {
                MapStubModel::$lastOrderByRaw = $sql;
                return $this;
            }

            public function get($columns = ['*'])
            {
                return (new MapStubModel())->newCollection($this->rows);
            }
        };
    }
}
