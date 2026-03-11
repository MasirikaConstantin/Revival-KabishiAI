<?php

declare(strict_types=1);

namespace Billing\Infrastructure\Payments\Gateways\FreshPay;

use Billing\Domain\Entities\OrderEntity;
use Billing\Domain\ValueObjects\ExternalId;
use Billing\Domain\ValueObjects\PaymentGateway;
use Billing\Infrastructure\Payments\CheckoutDataAwarePaymentGatewayInterface;
use Billing\Infrastructure\Payments\Exceptions\PaymentException;
use Billing\Infrastructure\Payments\Helper;
use Billing\Infrastructure\Payments\OffsitePaymentGatewayInterface;
use Billing\Infrastructure\Payments\PurchaseToken;
use Billing\Infrastructure\Payments\WebhookHandlerInterface;
use Easy\Container\Attributes\Inject;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\UriFactoryInterface;
use Psr\Http\Message\UriInterface;
use Shared\Infrastructure\Atributes\BuiltInAspect;
use Symfony\Component\Intl\Currencies;

#[BuiltInAspect]
class FreshPay implements
    OffsitePaymentGatewayInterface,
    CheckoutDataAwarePaymentGatewayInterface
{
    public const LOOKUP_KEY = 'freshpay';

    public function __construct(
        private Client $client,
        private Helper $helper,
        private UriFactoryInterface $uriFactory,

        #[Inject('option.freshpay.is_enabled')]
        private bool $isEnabled = false,

        #[Inject('option.freshpay.currency')]
        private string $currency = 'CDF',

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

        $freshpay = $data['freshpay'] ?? [];
        $customerNumber = trim((string) ($freshpay['customer_number'] ?? ''));
        $method = trim((string) ($freshpay['method'] ?? ''));

        if (!$customerNumber) {
            throw new PaymentException('FreshPay customer number is required.');
        }

        if (!$method) {
            throw new PaymentException('FreshPay payment method is required.');
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
            'action' => 'credit',
            'customer_number' => $customerNumber,
            'firstname' => $this->firstName,
            'lastname' => $this->lastName,
            'e-mail' => $this->email,
            'reference' => $order->getId()->getValue()->toString(),
            'method' => $method,
            'callback_url' => $this->helper->generateWebhookUrl(
                $order,
                self::LOOKUP_KEY
            ),
        ];

        try {
            $response = $this->client->sendRequest($payload);
        } catch (ClientExceptionInterface $exception) {
            throw new PaymentException(
                $exception->getMessage(),
                $exception->getCode(),
                $exception
            );
        }

        $status = $response->getStatusCode();
        $body = trim($response->getBody()->getContents());
        $data = $body !== '' ? json_decode($body, true) : [];

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
