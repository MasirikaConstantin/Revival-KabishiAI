<?php

declare(strict_types=1);

namespace Billing\Infrastructure\Payments;

use Billing\Domain\Entities\OrderEntity;
use Billing\Infrastructure\Payments\Exceptions\PaymentException;
use Psr\Http\Message\UriInterface;

interface CheckoutDataAwarePaymentGatewayInterface
{
    /**
     * @param array<string,mixed> $data
     * @throws PaymentException
     */
    public function purchaseWithCheckoutData(
        OrderEntity $order,
        array $data = []
    ): UriInterface|PurchaseToken|string;
}
