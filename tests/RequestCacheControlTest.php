<?php

namespace Kevinrob\GuzzleCache;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

class RequestCacheControlTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @var Client
     */
    protected $client;

    public function setUp()
    {
        // Create default HandlerStack
        $stack = HandlerStack::create(function (RequestInterface $request, array $options) {
            switch ($request->getUri()->getPath()) {
                case '/2s':
                    return new FulfilledPromise(
                        (new Response())
                            ->withAddedHeader('Cache-Control', 'max-age=2')
                    );
                case '/3s':
                    return new FulfilledPromise(
                        (new Response())
                            ->withAddedHeader('Cache-Control', 'max-age=3')
                    );
                case '/only-if-cached':
                    return new FulfilledPromise(
                        (new Response())
                            ->withAddedHeader('Cache-Control', 'max-age=3')
                    );
            }

            throw new \InvalidArgumentException();
        });

        // Add this middleware to the top with `push`
        $stack->push(new CacheMiddleware(), 'cache');

        // Initialize the client with the handler option
        $this->client = new Client(['handler' => $stack]);
    }

    public function testNoStoreHeader()
    {
        $this->client->get('http://test.com/2s', [
            'headers' => [
                'Cache-Control' => 'no-store',
            ]
        ]);

        $response = $this->client->get('http://test.com/2s');
        $this->assertEquals(CacheMiddleware::HEADER_CACHE_MISS, $response->getHeaderLine(CacheMiddleware::HEADER_CACHE_INFO));
    }

    public function testNoCacheHeader()
    {
        $this->client->get('http://test.com/2s');

        $response = $this->client->get('http://test.com/2s');
        $this->assertEquals(CacheMiddleware::HEADER_CACHE_HIT, $response->getHeaderLine(CacheMiddleware::HEADER_CACHE_INFO));

        $response = $this->client->get('http://test.com/2s', [
            'headers' => [
                'Cache-Control' => 'no-cache',
            ]
        ]);
        $this->assertEquals(CacheMiddleware::HEADER_CACHE_MISS, $response->getHeaderLine(CacheMiddleware::HEADER_CACHE_INFO));
    }

    public function testMaxAgeHeader()
    {
        $this->client->get('http://test.com/3s');

        $response = $this->client->get('http://test.com/3s');
        $this->assertEquals(CacheMiddleware::HEADER_CACHE_HIT, $response->getHeaderLine(CacheMiddleware::HEADER_CACHE_INFO));

        sleep(2);

        $response = $this->client->get('http://test.com/3s', [
            'headers' => [
                'Cache-Control' => 'max-age=1',
            ]
        ]);
        $this->assertEquals(CacheMiddleware::HEADER_CACHE_MISS, $response->getHeaderLine(CacheMiddleware::HEADER_CACHE_INFO));
    }

    public function testOnlyIfCachedHeader()
    {
        $this->client->get('http://test.com/3s');
        $response = $this->client->get('http://test.com/3s', [
            'headers' => [
                'Cache-Control' => 'only-if-cached',
            ]
        ]);
        $this->assertEquals(
            CacheMiddleware::HEADER_CACHE_HIT,
            $response->getHeaderLine(CacheMiddleware::HEADER_CACHE_INFO)
        );

        $this->setExpectedException('GuzzleHttp\Exception\ServerException', 'Server error: 504');
        $this->client->get('http://test.com/only-if-cached', [
            'headers' => [
                'Cache-Control' => 'only-if-cached',
            ]
        ]);
    }

    public function testPragmaNoCacheHeader()
    {
        $this->client->get('http://test.com/2s');

        $response = $this->client->get('http://test.com/2s');
        $this->assertEquals(CacheMiddleware::HEADER_CACHE_HIT, $response->getHeaderLine(CacheMiddleware::HEADER_CACHE_INFO));

        // Pragma is not important if a Cache-Control is present
        $response = $this->client->get('http://test.com/2s', [
            'headers' => [
                'Cache-Control' => '',
                'Pragma' => 'no-cache',
            ]
        ]);
        $this->assertEquals(CacheMiddleware::HEADER_CACHE_HIT, $response->getHeaderLine(CacheMiddleware::HEADER_CACHE_INFO));

        $response = $this->client->get('http://test.com/2s', [
            'headers' => [
                'Pragma' => 'no-cache',
            ]
        ]);
        $this->assertEquals(CacheMiddleware::HEADER_CACHE_MISS, $response->getHeaderLine(CacheMiddleware::HEADER_CACHE_INFO));
    }
}
