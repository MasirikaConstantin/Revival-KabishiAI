<?php

declare(strict_types=1);

namespace Presentation\Resources\Api;

use Billing\Domain\Entities\OrderEntity;
use JsonSerializable;
use Presentation\Resources\CurrencyResource;
use Presentation\Resources\DateTimeResource;

class OrderResource implements JsonSerializable
{
    use Traits\TwigResource;

    public function __construct(private OrderEntity $order) {}

    public function jsonSerialize(): array
    {
        $o = $this->order;
        $coupon = $o->getCoupon();
        $status = $o->getStatus();

        if (
            $status->value === 'cancelled'
            && $o->getPaymentGateway()->value === 'freshpay'
        ) {
            $status = \Billing\Domain\ValueObjects\OrderStatus::FAILED;
        }

        return [
            'id' => $o->getId(),
            'currency' => new CurrencyResource($o->getCurrencyCode()),
            'status' => $status,
            'external_id' => $o->getExternalId(),
            'trial_period_days' => $o->getTrialPeriodDays(),
            'created_at' => new DateTimeResource($o->getCreatedAt()),
            'updated_at' => new DateTimeResource($o->getUpdatedAt()),
            'coupon' => $coupon ? new CouponResource($coupon) : null,
            'plan' => new PlanSnapshotResource($o->getPlan()),
            'subtotal' => $o->getSubtotal(),
            'discount' => $o->getDiscount(),
            'total' => $o->getTotalPrice(),
            'payment_gateway' => $o->getPaymentGateway(),
        ];
    }
}
