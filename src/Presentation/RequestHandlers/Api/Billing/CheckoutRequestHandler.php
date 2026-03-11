<?php

declare(strict_types=1);

namespace Presentation\RequestHandlers\Api\Billing;

use Billing\Application\Commands\CancelSubscriptionCommand;
use Billing\Application\Commands\CreateOrderCommand;
use Billing\Application\Commands\FulfillOrderCommand;
use Billing\Application\Commands\PayOrderCommand;
use Billing\Domain\Entities\OrderEntity;
use Billing\Domain\Entities\PlanEntity;
use Billing\Domain\ValueObjects\BillingCycle;
use Billing\Domain\ValueObjects\CreditCount;
use Billing\Domain\ValueObjects\Price;
use Billing\Domain\ValueObjects\Title;
use Billing\Infrastructure\Payments\CheckoutDataAwarePaymentGatewayInterface;
use Billing\Infrastructure\Payments\PaymentGatewayFactoryInterface;
use Billing\Infrastructure\Payments\Exceptions\PaymentException;
use Billing\Infrastructure\Payments\Gateways\FreshPay\FreshPay;
use Billing\Infrastructure\Payments\PurchaseToken;
use Easy\Container\Attributes\Inject;
use Easy\Http\Message\RequestMethod;
use Easy\Http\Message\StatusCode;
use Easy\Router\Attributes\Route;
use Presentation\AccessControls\Permission;
use Presentation\AccessControls\WorkspaceAccessControl;
use Presentation\Exceptions\UnprocessableEntityException;
use Presentation\Resources\Api\OrderResource;
use Presentation\Response\JsonResponse;
use Presentation\Validation\Validator;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Shared\Infrastructure\CommandBus\Dispatcher;
use Symfony\Component\Intl\Currencies;
use User\Domain\Entities\UserEntity;
use Workspace\Domain\Entities\WorkspaceEntity;
use Throwable;

#[Route(path: '/checkout', method: RequestMethod::POST)]
class CheckoutRequestHandler extends BillingApi implements
    RequestHandlerInterface
{
    public function __construct(
        private Validator $validator,
        private WorkspaceAccessControl $ac,
        private Dispatcher $dispatcher,
        private PaymentGatewayFactoryInterface $factory,
        private LoggerInterface $logger,

        #[Inject('option.billing.custom_credits.enabled')]
        private bool $customCreditsEnabled = false,

        #[Inject('option.billing.custom_credits.rate')]
        private int $customCreditsRate = 0,

        #[Inject('option.billing.currency')]
        private ?string $currency = "USD",
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $order = null;
        $payload = null;

        try {
            $this->validateRequest($request);
            $payload = (object) $request->getParsedBody();

            /** @var WorkspaceEntity */
            $ws = $request->getAttribute(WorkspaceEntity::class);

            $this->logger->info('Billing checkout handler started', [
                'gateway' => $payload->gateway ?? null,
                'workspace_id' => $ws->getId()->getValue()->toString(),
                'plan_id' => $payload->id ?? null,
                'has_amount' => property_exists($payload, 'amount'),
            ]);

            // Current subscription, cancel after new subscription is created
            $sub = $ws->getSubscription();

            $plan = $payload->id ?? null;
            $custom = false;
            if (!$plan) {
                if (!$this->customCreditsEnabled) {
                    throw new UnprocessableEntityException('Custom credits are not enabled');
                }

                if (!$ws->getSubscription()) {
                    throw new UnprocessableEntityException('Workspace does not have a subscription');
                }

                $amount = $payload->amount;
                if (!$amount || $amount <= 0) {
                    throw new UnprocessableEntityException('Invalid amount');
                }

                $fraction = Currencies::getFractionDigits($this->currency);
                $credits = ($amount / 10 ** $fraction) * $this->customCreditsRate;

                $plan = new PlanEntity(
                    new Title('Credit purchase'),
                    new Price($amount),
                    BillingCycle::ONE_TIME
                );

                $plan->setCreditCount(new CreditCount($credits));

                $plan = $plan->getSnapshot();
                $plan->unlink();
                $custom = true;
            }

            $cmd = new CreateOrderCommand($ws, $plan);

            if (
                !$custom
                && property_exists($payload, 'coupon')
                && $payload->coupon
            ) {
                $cmd->setCoupon($payload->coupon);
            }

            /** @var OrderEntity */
            $order = $this->dispatcher->dispatch($cmd);

            $this->logger->info('Billing order created', [
                'gateway' => $payload->gateway ?? null,
                'order_id' => $order->getId()->getValue()->toString(),
                'total' => $order->getTotalPrice()->value,
                'is_paid' => $order->isPaid(),
            ]);

            if ($order->getTotalPrice()->value > 0 && !$order->isPaid()) {
                try {
                    $gateway = $this->factory->create($payload->gateway);
                    $checkoutData = json_decode(json_encode($payload), true) ?: [];
                    $resp = $gateway instanceof CheckoutDataAwarePaymentGatewayInterface
                        ? $gateway->purchaseWithCheckoutData($order, $checkoutData)
                        : $gateway->purchase($order);
                } catch (PaymentException $th) {
                    throw new UnprocessableEntityException(
                        previous: $th,
                    );
                } catch (Throwable $th) {
                    $this->logger->error('Billing checkout failed unexpectedly', [
                        'gateway' => $payload->gateway ?? null,
                        'order_id' => $order->getId()->getValue()->toString(),
                        'exception' => $th::class,
                        'message' => $th->getMessage(),
                        'file' => $th->getFile(),
                        'line' => $th->getLine(),
                    ]);

                    throw $th;
                }

                if ($resp instanceof UriInterface) {
                    return new JsonResponse(
                        [
                            'redirect' => (string) $resp
                        ]
                    );
                }

                if ($resp instanceof PurchaseToken) {
                    return new JsonResponse([
                        'id' => $order->getId()->getValue()->toString(),
                        'purchase_token' => $resp->value
                    ]);
                }

                $cmd = new PayOrderCommand($order, $payload->gateway, $resp);
                $this->dispatcher->dispatch($cmd);
            }

            $cmd = new FulfillOrderCommand($order);
            $resp = $this->dispatcher->dispatch($cmd);

            if ($sub) {
                $cmd = new CancelSubscriptionCommand($sub);
                $this->dispatcher->dispatch($cmd);
            }

            return new JsonResponse(new OrderResource($order), StatusCode::CREATED);
        } catch (Throwable $th) {
            $this->logger->error('Billing checkout aborted', [
                'gateway' => $payload?->gateway ?? null,
                'order_id' => $order?->getId()->getValue()->toString(),
                'exception' => $th::class,
                'message' => $th->getMessage(),
                'file' => $th->getFile(),
                'line' => $th->getLine(),
            ]);

            throw $th;
        }
    }

    private function validateRequest(ServerRequestInterface $req): void
    {
        $this->validator->validateRequest($req, [
            'id' => 'required_without:amount|uuid|nullable',
            'amount' => 'required_without:id|integer|nullable',
            'gateway' => 'string',
            'coupon' => 'string|nullable',
            'freshpay.customer_number' => 'nullable|string|max:30',
            'customer_number' => 'nullable|string|max:30',
            'freshpay[customer_number]' => 'nullable|string|max:30',
        ]);

        /** @var UserEntity */
        $user = $req->getAttribute(UserEntity::class);

        /** @var WorkspaceEntity */
        $workspace = $req->getAttribute(WorkspaceEntity::class);

        $this->ac->denyUnlessGranted(
            Permission::WORKSPACE_MANAGE,
            $user,
            $workspace
        );

        $payload = (object) $req->getParsedBody();
        if (($payload->gateway ?? null) !== FreshPay::LOOKUP_KEY) {
            return;
        }

        $customerNumber = trim($this->extractFreshpayCustomerNumber($payload));
        $workspacePhone = $workspace->getAddress()?->phoneNumber;
        $userPhone = $user->getPhoneNumber()->value;

        $this->logger->info('FreshPay checkout request received', [
            'payload_keys' => array_keys((array) $payload),
            'has_freshpay_object' => property_exists($payload, 'freshpay'),
            'customer_number' => $this->maskPhoneNumber($customerNumber),
            'workspace_phone' => $this->maskPhoneNumber((string) $workspacePhone),
            'user_phone' => $this->maskPhoneNumber((string) $userPhone),
        ]);

        if (
            $customerNumber === ''
            && !$workspacePhone
            && !$userPhone
        ) {
            throw new UnprocessableEntityException(
                'FreshPay customer number is required. Add one in checkout, workspace billing address or account profile.'
            );
        }
    }

    private function extractFreshpayCustomerNumber(object $payload): string
    {
        $freshpay = $payload->freshpay ?? null;
        if (
            is_object($freshpay)
            && property_exists($freshpay, 'customer_number')
        ) {
            return (string) $freshpay->customer_number;
        }

        foreach ([
            'customer_number',
            'freshpay.customer_number',
            'freshpay[customer_number]',
        ] as $property) {
            if (property_exists($payload, $property)) {
                return (string) $payload->{$property};
            }
        }

        return '';
    }

    private function maskPhoneNumber(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $length = strlen($value);
        if ($length <= 4) {
            return str_repeat('*', $length);
        }

        return str_repeat('*', $length - 4) . substr($value, -4);
    }
}
