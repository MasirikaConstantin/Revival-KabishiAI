<?php

declare(strict_types=1);

namespace Billing\Infrastructure\Payments\Gateways\FreshPay;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

class Client
{
    private const BASE_URL = 'https://paydrc.gofreshbakery.net/api/v5/';

    public function __construct(
        private ClientInterface $client,
        private RequestFactoryInterface $requestFactory,
        private StreamFactoryInterface $streamFactory,
    ) {}

    /**
     * @param array<string,mixed> $payload
     * @throws ClientExceptionInterface
     */
    public function sendRequest(array $payload): ResponseInterface
    {
        $request = $this->requestFactory
            ->createRequest('POST', self::BASE_URL)
            ->withHeader('Content-Type', 'application/json')
            ->withBody(
                $this->streamFactory->createStream(json_encode($payload))
            );

        return $this->client->sendRequest($request);
    }
}
