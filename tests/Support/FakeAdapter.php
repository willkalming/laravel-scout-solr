<?php

namespace Scout\Solr\Tests\Support;

use Solarium\Core\Client\Adapter\AdapterInterface;
use Solarium\Core\Client\Endpoint;
use Solarium\Core\Client\Request;
use Solarium\Core\Client\Response;

/**
 * A stubbed Solarium transport: records requests and returns a canned JSON
 * body, so engine tests never need a live Solr server.
 */
class FakeAdapter implements AdapterInterface
{
    public $requests = [];
    public $response = [
        'responseHeader' => ['status' => 0, 'QTime' => 1],
        'response' => ['numFound' => 0, 'start' => 0, 'docs' => []],
    ];

    public function execute(Request $request, Endpoint $endpoint): Response
    {
        $this->requests[] = ['request' => $request, 'endpoint' => $endpoint];
        return new Response(json_encode($this->response), ['HTTP/1.1 200 OK']);
    }
}
