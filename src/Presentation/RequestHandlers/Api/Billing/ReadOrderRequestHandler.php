<?php

declare(strict_types=1);

namespace Presentation\RequestHandlers\Api\Billing;

use Billing\Application\Commands\ReadOrderCommand;
use Billing\Domain\Entities\OrderEntity;
use Billing\Domain\Exceptions\OrderNotFoundException;
use Easy\Http\Message\RequestMethod;
use Easy\Router\Attributes\Route;
use Presentation\Exceptions\NotFoundException;
use Presentation\Resources\Api\OrderResource;
use Presentation\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Shared\Infrastructure\CommandBus\Dispatcher;
use Workspace\Domain\Entities\WorkspaceEntity;

#[Route(path: '/orders/[uuid:id]', method: RequestMethod::GET)]
class ReadOrderRequestHandler extends BillingApi implements
    RequestHandlerInterface
{
    public function __construct(
        private Dispatcher $dispatcher
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $id = $request->getAttribute('id');

        try {
            /** @var OrderEntity $order */
            $order = $this->dispatcher->dispatch(new ReadOrderCommand($id));
        } catch (OrderNotFoundException $th) {
            throw new NotFoundException(
                param: 'id',
                previous: $th
            );
        }

        /** @var WorkspaceEntity $workspace */
        $workspace = $request->getAttribute(WorkspaceEntity::class);

        if (
            (string) $order->getWorkspace()->getId()->getValue()
            !== (string) $workspace->getId()->getValue()
        ) {
            throw new NotFoundException(param: 'id');
        }

        return new JsonResponse(new OrderResource($order));
    }
}
