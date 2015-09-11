<?php

use FelipeDouradinho\JsonApi\Request;
use FelipeDouradinho\JsonApi\Response;
use FelipeDouradinho\JsonApi\Handler;

class HandlerWithGETSupport extends Handler
{
    public function handleGet(Request $req)
    {
        return new Response([ 'param' => 1 ]);
    }
}

class HandlerTest extends PHPUnit_Framework_TestCase
{
    public function testMockInstanceWithNoGETSupport()
    {
        $req = new Request('http://www.example.com/', 'GET');
        $stub = $this->getMockForAbstractClass('FelipeDouradinho\JsonApi\Handler', [$req]);

        $this->setExpectedException('FelipeDouradinho\JsonApi\Exception');
        $stub->fulfillRequest();
    }

    public function testHandler()
    {
        $req = new Request('http://www.example.com/', 'GET');
        $handler = new HandlerWithGETSupport($req);
        $handlerResult = $handler->fulfillRequest();

        $this->assertInstanceOf('FelipeDouradinho\JsonApi\Response', $handlerResult);
    }

    public function testHandlerUnsupportedRequest()
    {
        $req = new Request('http://www.example.com/', 'PUT', null);
        $handler = new HandlerWithGETSupport($req);

        $this->setExpectedException('FelipeDouradinho\JsonApi\Exception');
        $handler->fulfillRequest();
    }
}
