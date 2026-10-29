<?php

namespace Scout\Solr\Tests\Support;

use Solarium\Core\Client\Adapter\AdapterInterface;
use Solarium\Core\Client\Response;
use Solarium\Core\Configurable;

class FakeAdapter extends Configurable implements AdapterInterface
{
    public $requests = [];
    public $response = [
        'responseHeader' => ['status' => 0, 'QTime' => 1],
        'response' => ['numFound' => 0, 'start' => 0, 'docs' => []],
    ];

    public function execute($request, $endpoint)
    {
        $this->requests[] = ['request' => $request, 'endpoint' => $endpoint];
        return new Response(json_encode($this->response), ['HTTP/1.1 200 OK']);
    }
}
