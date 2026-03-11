<?php

declare(strict_types=1);

namespace Billing\Infrastructure\Payments\Gateways\FreshPay;

use Billing\Application\Commands\CancelSubscriptionCommand;
use Billing\Application\Commands\CancelOrderCommand;
use Billing\Application\Commands\FulfillOrderCommand;
use Billing\Application\Commands\PayOrderCommand;
use Billing\Application\Commands\ReadOrderCommand;
use Billing\Domain\Exceptions\AlreadyFulfilledException;
use Billing\Domain\Exceptions\AlreadyPaidException;
use Billing\Domain\Exceptions\InvalidOrderStateException;
use Billing\Domain\Exceptions\OrderNotFoundException;
use Billing\Infrastructure\Payments\Exceptions\WebhookException;
use Billing\Infrastructure\Payments\WebhookHandlerInterface;
use Easy\Container\Attributes\Inject;
use Shared\Infrastructure\CommandBus\Dispatcher;

class WebhookRequestHandler implements WebhookHandlerInterface
{
    public function __construct(
        private Dispatcher $dispatcher,

        #[Inject('option.freshpay.is_enabled')]
        private bool $isEnabled = false,

        #[Inject('option.freshpay.merchant_secrete')]
        private ?string $merchantSecret = null,

        #[Inject('option.freshpay.encryption_key')]
        private ?string $encryptionKey = null,

        #[Inject('option.freshpay.signature_key')]
        private ?string $signatureKey = null,
    ) {}

    public function handle(\Psr\Http\Message\ServerRequestInterface $request): void
    {
        if (!$this->isEnabled) {
            throw new WebhookException('FreshPay is not enabled.', 400);
        }

        $request->getBody()->rewind();
        $body = trim($request->getBody()->getContents());
        $request->getBody()->rewind();

        if ($body === '') {
            throw new WebhookException('Empty payload.', 400);
        }

        $signature = $request->getHeaderLine('X-Signature');
        if (!$signature) {
            throw new WebhookException('Invalid signature.', 401);
        }

        $payload = json_decode($body, true);
        if (!is_array($payload) || !isset($payload['data'])) {
            throw new WebhookException('Invalid encryption.', 400);
        }

        if (!$this->isSignatureValid($signature, $body, (string) $payload['data'])) {
            throw new WebhookException('Invalid signature.', 401);
        }

        $decrypted = $this->decryptPayload((string) $payload['data']);
        if (!is_array($decrypted)) {
            throw new WebhookException('Invalid encryption.', 400);
        }

        $state = $this->resolveTransactionState($decrypted);
        if ($state === 'pending') {
            return;
        }

        $reference = $this->extractString(
            $decrypted,
            ['reference', 'order_id', 'merchant_reference']
        );

        if (!$reference) {
            throw new WebhookException('Missing payment reference.', 400);
        }

        try {
            $order = $this->dispatcher->dispatch(new ReadOrderCommand($reference));
        } catch (OrderNotFoundException) {
            throw new WebhookException('Order not found.', 400);
        }

        if ($state === 'failed') {
            try {
                $this->dispatcher->dispatch(new CancelOrderCommand($order));
            } catch (InvalidOrderStateException) {
            }

            return;
        }

        $previousSubscription = $order->getWorkspace()->getSubscription();

        $externalId = $this->extractString(
            $decrypted,
            ['transaction_id', 'Transaction_id', 'payment_id', 'id', 'reference', 'Reference']
        ) ?: $reference;

        try {
            $this->dispatcher->dispatch(
                new PayOrderCommand($order, FreshPay::LOOKUP_KEY, $externalId)
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

    private function isSignatureValid(
        string $signature,
        string $rawBody,
        string $encryptedPayload
    ): bool {
        $secret = $this->signatureKey ?: $this->merchantSecret;
        if (!$secret) {
            return false;
        }

        $signature = trim((string) preg_replace('/^sha256=/i', '', $signature));
        $hexCandidates = [
            strtolower(hash_hmac('sha256', $rawBody, $secret)),
            strtolower(hash_hmac('sha256', $encryptedPayload, $secret)),
        ];

        foreach ($hexCandidates as $candidate) {
            if (hash_equals($candidate, strtolower($signature))) {
                return true;
            }
        }

        $base64Candidates = [
            base64_encode(hash_hmac('sha256', $rawBody, $secret, true)),
            base64_encode(hash_hmac('sha256', $encryptedPayload, $secret, true)),
        ];

        foreach ($base64Candidates as $candidate) {
            if (hash_equals($candidate, $signature)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function decryptPayload(string $payload): ?array
    {
        $secret = $this->normalizeSecret(
            $this->encryptionKey ?: $this->merchantSecret
        );

        if (!$secret) {
            return null;
        }

        $attempts = $this->buildPayloadAttempts($payload);
        foreach ($attempts as [$cipherText, $iv]) {
            $json = openssl_decrypt(
                $cipherText,
                'AES-256-CBC',
                $secret,
                OPENSSL_RAW_DATA,
                $iv
            );

            if (!is_string($json) || $json === '') {
                continue;
            }

            $data = json_decode($json, true);
            if (is_array($data)) {
                return $data;
            }
        }

        return null;
    }

    private function normalizeSecret(?string $secret): ?string
    {
        if (!$secret) {
            return null;
        }

        $secret = trim($secret);
        if ($secret === '') {
            return null;
        }

        if (preg_match('/^[a-f0-9]{64}$/i', $secret) === 1) {
            return hex2bin($secret) ?: null;
        }

        $decoded = base64_decode($secret, true);
        if ($decoded !== false && strlen($decoded) >= 32) {
            return substr($decoded, 0, 32);
        }

        return substr(hash('sha256', $secret, true), 0, 32);
    }

    /**
     * @return array<int,array{0:string,1:string}>
     */
    private function buildPayloadAttempts(string $payload): array
    {
        $attempts = [];

        if (str_contains($payload, ':')) {
            [$ivPart, $cipherPart] = explode(':', $payload, 2);
            $iv = base64_decode($ivPart, true);
            $cipher = base64_decode($cipherPart, true);

            if ($iv !== false && $cipher !== false && strlen($iv) === 16) {
                $attempts[] = [$cipher, $iv];
            }
        }

        $decoded = base64_decode($payload, true);
        if ($decoded !== false) {
            $json = json_decode($decoded, true);
            if (
                is_array($json)
                && isset($json['iv'], $json['value'])
            ) {
                $iv = base64_decode((string) $json['iv'], true);
                $cipher = base64_decode((string) $json['value'], true);

                if ($iv !== false && $cipher !== false && strlen($iv) === 16) {
                    $attempts[] = [$cipher, $iv];
                }
            }

            if (strlen($decoded) > 16) {
                $attempts[] = [
                    substr($decoded, 16),
                    substr($decoded, 0, 16)
                ];
            }
        }

        return $attempts;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function resolveTransactionState(array $payload): string
    {
        $status = strtolower((string) $this->extractString(
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
        ));

        if ($status === '') {
            return 'pending';
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

        return 'pending';
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<int,string> $keys
     */
    private function extractString(array $payload, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (isset($payload[$key]) && is_scalar($payload[$key])) {
                $value = trim((string) $payload[$key]);
                if ($value !== '') {
                    return $value;
                }
            }
        }

        foreach ($payload as $value) {
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
