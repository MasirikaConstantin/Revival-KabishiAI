<?php

declare(strict_types=1);

namespace Ai\Infrastructure\Services\xAi;

use Ai\Application\Commands\ReadLibraryItemCommand;
use Ai\Domain\Entities\AbstractLibraryItemEntity;
use Ai\Domain\Entities\VideoEntity;
use Ai\Domain\Exceptions\LibraryItemNotFoundException;
use Ai\Domain\ValueObjects\State;
use Easy\Container\Attributes\Inject;
use Easy\Http\Message\RequestMethod;
use Easy\Http\Message\StatusCode;
use Easy\Router\Attributes\Middleware;
use Easy\Router\Attributes\Route;
use Presentation\Exceptions\HttpException;
use Presentation\Exceptions\NotFoundException;
use Presentation\Middlewares\ExceptionMiddleware;
use Presentation\Response\EmptyResponse;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Shared\Infrastructure\CommandBus\Dispatcher;

#[Middleware(ExceptionMiddleware::class)]
#[Route(path: '/webhooks/xai/[uuid:id]', method: RequestMethod::PUT)]
class WebhookRequestHandler implements RequestHandlerInterface
{
    private const TIMESTAMP_MAX_AGE = 7200;  // 2 hours
    private const TIMESTAMP_MAX_FUTURE = 300; // 5 minutes

    public function __construct(
        private Dispatcher $dispatcher,
        private ContainerInterface $container,

        #[Inject('option.xai.webhook_secret')]
        private ?string $webhookSecret = null,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if (empty($this->webhookSecret)) {
            throw new HttpException(
                'Webhook not configured',
                StatusCode::BAD_REQUEST
            );
        }

        $id = $request->getAttribute('id');
        $params = $request->getQueryParams();
        $sig = $params['sig'] ?? null;
        $t = $params['t'] ?? null;

        if (!$sig || !$t) {
            throw new HttpException(
                'Missing signature parameters',
                StatusCode::BAD_REQUEST
            );
        }

        $timestamp = (int) $t;
        $now = time();
        if (
            $timestamp < $now - self::TIMESTAMP_MAX_AGE
            || $timestamp > $now + self::TIMESTAMP_MAX_FUTURE
        ) {
            throw new HttpException(
                'Signature expired',
                StatusCode::BAD_REQUEST
            );
        }

        $payload = $id . '|' . $t;
        $expected = base64_encode(
            hash_hmac('sha256', $payload, $this->webhookSecret, true)
        );

        if (!hash_equals($expected, $sig)) {
            throw new HttpException(
                'Invalid signature',
                StatusCode::BAD_REQUEST
            );
        }

        $cmd = new ReadLibraryItemCommand($id);

        try {
            /** @var AbstractLibraryItemEntity $entity */
            $entity = $this->dispatcher->dispatch($cmd);
        } catch (LibraryItemNotFoundException $th) {
            throw new NotFoundException();
        }

        $state = $entity->getState();
        if ($state === State::COMPLETED) {
            return new EmptyResponse();
        }

        if ($state !== State::PROCESSING && $state !== State::QUEUED) {
            throw new HttpException(
                'Invalid state',
                StatusCode::BAD_REQUEST
            );
        }

        $content = $request->getBody()->getContents();

        if (empty($content)) {
            throw new HttpException(
                'Empty video content',
                StatusCode::BAD_REQUEST
            );
        }

        if ($entity instanceof VideoEntity) {
            $processor = $this->container->get(VideoUploadProcessor::class);
            $processor($entity, $content);
        } else {
            throw new NotFoundException();
        }

        return new EmptyResponse();
    }
}
