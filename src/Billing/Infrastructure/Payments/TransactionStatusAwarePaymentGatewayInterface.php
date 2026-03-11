<?php

declare(strict_types=1);

namespace Billing\Infrastructure\Payments;

use Billing\Domain\Entities\OrderEntity;

interface TransactionStatusAwarePaymentGatewayInterface
{
    public function syncOrderStatus(OrderEntity $order): void;
}
