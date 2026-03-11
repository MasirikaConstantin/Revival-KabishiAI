<?php

declare(strict_types=1);

namespace Billing\Infrastructure\Payments\Gateways\FreshPay;

use Billing\Application\Commands\CancelOrderCommand;
use Billing\Application\Commands\CancelSubscriptionCommand;
use Billing\Application\Commands\FulfillOrderCommand;
use Billing\Application\Commands\PayOrderCommand;
use Billing\Domain\Entities\OrderEntity;
use Billing\Domain\Exceptions\AlreadyFulfilledException;
use Billing\Domain\Exceptions\AlreadyPaidException;
use Billing\Domain\Exceptions\InvalidOrderStateException;
use Billing\Domain\ValueObjects\ExternalId;
use Billing\Domain\ValueObjects\PaymentGateway;
use Billing\Infrastructure\Payments\CheckoutDataAwarePaymentGatewayInterface;
use Billing\Infrastructure\Payments\Exceptions\PaymentException;
use Billing\Infrastructure\Payments\Helper;
use Billing\Infrastructure\Payments\OffsitePaymentGatewayInterface;
use Billing\Infrastructure\Payments\PurchaseToken;
use Billing\Infrastructure\Payments\TransactionStatusAwarePaymentGatewayInterface;
use Billing\Infrastructure\Payments\WebhookHandlerInterface;
use Easy\Container\Attributes\Inject;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\UriFactoryInterface;
use Psr\Http\Message\UriInterface;
use Psr\Log\LoggerInterface;
use Shared\Infrastructure\CommandBus\Dispatcher;
use Shared\Infrastructure\Atributes\BuiltInAspect;
use Symfony\Component\Intl\Currencies;

#[BuiltInAspect]
class FreshPay implements
    OffsitePaymentGatewayInterface,
    CheckoutDataAwarePaymentGatewayInterface,
    TransactionStatusAwarePaymentGatewayInterface
{
    public const LOOKUP_KEY = 'freshpay';

    public function __construct(
        private Client $client,
        private Helper $helper,
        private UriFactoryInterface $uriFactory,
        private LoggerInterface $logger,
        private Dispatcher $dispatcher,

        #[Inject('option.freshpay.is_enabled')]
        private bool $isEnabled = false,

        #[Inject('option.freshpay.currency')]
        private string $currency = 'USD',

        #[Inject('option.freshpay.merchant_id')]
        private ?string $merchantId = null,

        #[Inject('option.freshpay.merchant_secrete')]
        private ?string $merchantSecret = null,

        #[Inject('option.freshpay.firstname')]
        private ?string $firstName = null,

        #[Inject('option.freshpay.lastname')]
        private ?string $lastName = null,

        #[Inject('option.freshpay.email')]
        private ?string $email = null,

        #[Inject('option.freshpay.networks.airtel')]
        private string $airtelPrefixes = '097,098,099',

        #[Inject('option.freshpay.networks.mpesa')]
        private string $mpesaPrefixes = '081,082,083',

        #[Inject('option.freshpay.networks.orange')]
        private string $orangePrefixes = '084,085,089',

        #[Inject('option.freshpay.networks.africell')]
        private string $africellPrefixes = '090',
    ) {}

    public function isEnabled(): bool
    {
        return $this->isEnabled;
    }

    public function getName(): string
    {
        return 'FreshPay';
    }

    public function getLogo(): string
    {
        return file_get_contents(__DIR__ . '/logo.svg');
    }

    public function getButtonBackgroundColor(): string
    {
        return '#0f766e';
    }

    public function getButtonTextColor(): string
    {
        return '#ffffff';
    }

    public function purchase(OrderEntity $order): UriInterface|PurchaseToken|string
    {
        throw new PaymentException('FreshPay requires checkout data.');
    }

    public function purchaseWithCheckoutData(
        OrderEntity $order,
        array $data = []
    ): UriInterface|PurchaseToken|string {
        $this->validateConfiguration();

        $customerNumber = $this->resolveCustomerNumber(
            $order,
            $this->extractCustomerNumber($data)
        );

        if (!$customerNumber) {
            throw new PaymentException('FreshPay customer number is required.');
        }

        $method = $this->detectNetwork($customerNumber);
        if (!$method) {
            throw new PaymentException(
                'Unable to detect the FreshPay network from this phone number.'
            );
        }

        [$amount, $currency] = $this->helper->convert(
            $order->getTotalPrice(),
            $order->getCurrencyCode(),
            $this->currency
        );

        $payload = [
            'merchant_id' => $this->merchantId,
            'merchant_secrete' => $this->merchantSecret,
            'amount' => $this->formatAmount($amount->value, $currency->value),
            'currency' => $currency->value,
            'action' => 'debit',
            'customer_number' => $customerNumber,
            'firstname' => $this->firstName,
            'lastname' => $this->lastName,
            'email' => $this->email,
            'reference' => $order->getId()->getValue()->toString(),
            'method' => $method,
            'callback_url' => $this->helper->generateWebhookUrl(
                $order,
                self::LOOKUP_KEY
            ),
        ];

        $this->logger->info('FreshPay API payload prepared', [
            'order_id' => $order->getId()->getValue()->toString(),
            'customer_number' => $this->maskPhoneNumber($customerNumber),
            'method' => $method,
            'amount' => $payload['amount'],
            'currency' => $payload['currency'],
            'reference' => $payload['reference'],
            'callback_url' => $payload['callback_url'],
        ]);

        try {
            $response = $this->client->sendRequest($payload);
        } catch (ClientExceptionInterface $exception) {
            $this->logger->error('FreshPay API request failed', [
                'order_id' => $order->getId()->getValue()->toString(),
                'message' => $exception->getMessage(),
            ]);
            throw new PaymentException(
                $exception->getMessage(),
                $exception->getCode(),
                $exception
            );
        }

        $status = $response->getStatusCode();
        $body = trim($response->getBody()->getContents());
        $data = $body !== '' ? json_decode($body, true) : [];

        $this->logger->info('FreshPay API response received', [
            'order_id' => $order->getId()->getValue()->toString(),
            'status' => $status,
            'body' => is_array($data) ? $data : $body,
        ]);

        if ($status < 200 || $status >= 300) {
            $message = is_array($data)
                ? $this->extractMessage($data)
                : null;

            throw new PaymentException(
                $message ?: 'FreshPay request failed.'
            );
        }

        $externalId = $this->extractExternalId($data)
            ?: $order->getId()->getValue()->toString();

        $order->initiatePayment(
            new PaymentGateway(self::LOOKUP_KEY),
            new ExternalId($externalId)
        );

        $redirectUrl = $this->extractString(
            $data,
            ['redirect_url', 'payment_url', 'checkout_url', 'url']
        );

        if ($redirectUrl) {
            return $this->uriFactory->createUri($redirectUrl);
        }

        return new PurchaseToken($externalId);
    }

    public function completePurchase(
        OrderEntity $order,
        array $params = []
    ): string {
        return $order->getExternalId()->value ?: '';
    }

    public function cancelSubscription(string $id): void
    {
    }

    public function getWebhookHandler(): string|WebhookHandlerInterface
    {
        return WebhookRequestHandler::class;
    }

    public function syncOrderStatus(OrderEntity $order): void
    {
        $remote = $this->verifyOrder($order);
        if (!$remote) {
            return;
        }

        $state = $remote['state'];
        if ($state === 'pending') {
            return;
        }

        if ($state === 'failed') {
            try {
                $this->dispatcher->dispatch(new CancelOrderCommand($order));
            } catch (InvalidOrderStateException) {
            }

            return;
        }

        $previousSubscription = $order->getWorkspace()->getSubscription();
        $externalId = $remote['external_id']
            ?: ($order->getExternalId()->value ?: $order->getId()->getValue()->toString());

        try {
            $this->dispatcher->dispatch(
                new PayOrderCommand($order, self::LOOKUP_KEY, $externalId)
            );
        } catch (AlreadyPaidException|AlreadyFulfilledException) {
        }

        try {
            $this->dispatcher->dispatch(new FulfillOrderCommand($order));
        } catch (AlreadyFulfilledException) {
        }

        if (
            $previousSubscription
            && $order->getPlan()->getBillingCycle()->isRenewable()
        ) {
            $this->dispatcher->dispatch(
                new CancelSubscriptionCommand($previousSubscription)
            );
        }
    }

    private function resolveCustomerNumber(
        OrderEntity $order,
        string $customerNumber
    ): string {
        $normalized = $this->normalizePhoneNumber($customerNumber);
        if ($normalized !== '') {
            return $normalized;
        }

        $workspace = $order->getWorkspace();
        $workspacePhone = $workspace->getAddress()?->phoneNumber;
        $normalized = $this->normalizePhoneNumber((string) $workspacePhone);
        if ($normalized !== '') {
            return $normalized;
        }

        $ownerPhone = $workspace->getOwner()->getPhoneNumber()->value;
        return $this->normalizePhoneNumber((string) $ownerPhone);
    }

    /**
     * @param array<string,mixed> $data
     */
    private function extractCustomerNumber(array $data): string
    {
        $freshpay = $data['freshpay'] ?? null;
        if (is_array($freshpay) && array_key_exists('customer_number', $freshpay)) {
            return (string) $freshpay['customer_number'];
        }

        foreach ([
            'customer_number',
            'freshpay.customer_number',
            'freshpay[customer_number]',
        ] as $key) {
            if (array_key_exists($key, $data)) {
                return (string) $data[$key];
            }
        }

        return '';
    }

    /**
     * @return array{state:string,external_id:string|null}|null
     */
    private function verifyOrder(OrderEntity $order): ?array
    {
        $this->validateConfiguration();

        foreach ($this->getVerificationReferences($order) as $reference) {
            $payload = [
                'merchant_id' => $this->merchantId,
                'merchant_secrete' => $this->merchantSecret,
                'action' => 'verify',
                'reference' => $reference,
            ];

            $this->logger->info('FreshPay verify payload prepared', [
                'order_id' => $order->getId()->getValue()->toString(),
                'reference' => $reference,
            ]);

            try {
                $response = $this->client->sendRequest($payload);
            } catch (ClientExceptionInterface $exception) {
                $this->logger->error('FreshPay verify request failed', [
                    'order_id' => $order->getId()->getValue()->toString(),
                    'reference' => $reference,
                    'message' => $exception->getMessage(),
                ]);
                return null;
            }

            $status = $response->getStatusCode();
            $body = trim($response->getBody()->getContents());
            $data = $body !== '' ? json_decode($body, true) : [];

            $this->logger->info('FreshPay verify response received', [
                'order_id' => $order->getId()->getValue()->toString(),
                'reference' => $reference,
                'status' => $status,
                'body' => is_array($data) ? $data : $body,
            ]);

            if ($status < 200 || $status >= 300 || !is_array($data)) {
                continue;
            }

            $state = $this->resolveRemoteState($data);
            if ($state === null) {
                continue;
            }

            return [
                'state' => $state,
                'external_id' => $this->extractString(
                    $data,
                    ['Transaction_id', 'transaction_id', 'Reference', 'reference']
                ),
            ];
        }

        return null;
    }

    /**
     * @return array<int,string>
     */
    private function getVerificationReferences(OrderEntity $order): array
    {
        $references = [];
        $externalId = trim((string) $order->getExternalId()->value);
        if ($externalId !== '') {
            $references[] = $externalId;
        }

        $references[] = $order->getId()->getValue()->toString();

        return array_values(array_unique($references));
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function resolveRemoteState(array $payload): ?string
    {
        $status = strtolower((string) (
            $this->extractString(
                $payload,
                [
                    'Trans_Status',
                    'trans_status',
                    'transaction_status',
                    'payment_status',
                    'state',
                    'result',
                    'Status',
                    'status',
                ]
            ) ?? ''
        ));

        if ($status === '') {
            return null;
        }

        if (in_array(
            $status,
            ['success', 'successful', 'paid', 'completed', 'approved'],
            true
        )) {
            return 'completed';
        }

        if (in_array(
            $status,
            ['failed', 'fail', 'rejected', 'cancelled', 'canceled', 'error', 'expired'],
            true
        )) {
            return 'failed';
        }

        if (in_array(
            $status,
            ['pending', 'processing', 'initiated', 'waiting', 'in_progress', 'in progress'],
            true
        )) {
            return 'pending';
        }

        return null;
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

    private function validateConfiguration(): void
    {
        $required = [
            'merchant_id' => $this->merchantId,
            'merchant_secrete' => $this->merchantSecret,
            'firstname' => $this->firstName,
            'lastname' => $this->lastName,
            'email' => $this->email,
        ];

        foreach ($required as $field => $value) {
            if (!is_string($value) || trim($value) === '') {
                throw new PaymentException(
                    sprintf('FreshPay %s is not configured.', $field)
                );
            }
        }
    }

    private function detectNetwork(string $customerNumber): ?string
    {
        foreach ($this->getNetworkPrefixes() as $network => $prefixes) {
            foreach ($prefixes as $prefix) {
                if ($this->matchesPrefix($customerNumber, $prefix)) {
                    return $network;
                }
            }
        }

        return null;
    }

    /**
     * @return array<string,array<int,string>>
     */
    private function getNetworkPrefixes(): array
    {
        return [
            'airtel' => $this->splitPrefixes($this->airtelPrefixes),
            'mpesa' => $this->splitPrefixes($this->mpesaPrefixes),
            'orange' => $this->splitPrefixes($this->orangePrefixes),
            'africell' => $this->splitPrefixes($this->africellPrefixes),
        ];
    }

    /**
     * @return array<int,string>
     */
    private function splitPrefixes(string $prefixes): array
    {
        $parts = preg_split('/[\s,;|]+/', $prefixes) ?: [];
        $normalized = [];

        foreach ($parts as $prefix) {
            $prefix = $this->normalizePhoneNumber($prefix);
            if (!$prefix) {
                continue;
            }

            $normalized[] = $prefix;

            if (str_starts_with($prefix, '0')) {
                $normalized[] = ltrim($prefix, '0');
            }
        }

        return array_values(array_unique(array_filter($normalized)));
    }

    private function matchesPrefix(string $phoneNumber, string $prefix): bool
    {
        return str_starts_with($phoneNumber, $prefix)
            || str_starts_with(ltrim($phoneNumber, '0'), ltrim($prefix, '0'));
    }

    private function normalizePhoneNumber(string $phoneNumber): string
    {
        $normalized = preg_replace('/\D+/', '', $phoneNumber) ?: '';

        if (str_starts_with($normalized, '00243')) {
            return '0' . substr($normalized, 5);
        }

        if (str_starts_with($normalized, '243')) {
            return '0' . substr($normalized, 3);
        }

        return $normalized;
    }

    private function formatAmount(int $amount, string $currency): string
    {
        $fractionDigits = Currencies::getFractionDigits($currency);
        $value = number_format(
            $amount / 10 ** $fractionDigits,
            $fractionDigits,
            '.',
            ''
        );

        return rtrim(rtrim($value, '0'), '.');
    }

    /**
     * @param array<string,mixed> $data
     */
    private function extractExternalId(array $data): ?string
    {
        return $this->extractString(
            $data,
            [
                'transaction_id',
                'payment_id',
                'reference',
                'id',
            ]
        );
    }

    /**
     * @param array<string,mixed> $data
     */
    private function extractMessage(array $data): ?string
    {
        return $this->extractString(
            $data,
            ['message', 'error', 'status']
        );
    }

    /**
     * @param array<string,mixed> $data
     * @param array<int,string> $keys
     */
    private function extractString(array $data, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (isset($data[$key]) && is_scalar($data[$key])) {
                $value = trim((string) $data[$key]);
                if ($value !== '') {
                    return $value;
                }
            }
        }

        foreach ($data as $value) {
            if (is_array($value)) {
                $found = $this->extractString($value, $keys);
                if ($found) {
                    return $found;
                }
            }
        }

        return null;
    }
}
