<?php

declare(strict_types=1);

namespace Ai\Infrastructure\Services\xAi;

use Ai\Domain\Entities\VideoEntity;
use Ai\Domain\ValueObjects\State;
use Ai\Infrastructure\Services\CostCalculator;
use Billing\Domain\Events\CreditUsageEvent;
use Billing\Domain\ValueObjects\CreditCount;
use Easy\Container\Attributes\Inject;
use File\Domain\Entities\FileEntity;
use File\Domain\ValueObjects\ObjectKey;
use File\Domain\ValueObjects\Size;
use File\Domain\ValueObjects\Storage;
use File\Domain\ValueObjects\Url;
use Psr\EventDispatcher\EventDispatcherInterface;
use Shared\Infrastructure\FileSystem\CdnInterface;

class VideoUploadProcessor
{
    public function __construct(
        private CdnInterface $cdn,
        private CostCalculator $calc,
        private EventDispatcherInterface $dispatcher,

        #[Inject('option.billing.negative_balance_enabled')]
        private bool $negativeBalance = false,
    ) {}

    public function __invoke(VideoEntity $entity, string $videoContent): void
    {
        $user = $entity->getUser();
        $ws = $entity->getWorkspace();
        $model = $entity->getModel();

        $key = $this->cdn->generatePath('mp4', $ws, $user);
        $this->cdn->write($key, $videoContent);

        $file = new FileEntity(
            new Storage($this->cdn->getAdapterLookupKey()),
            new ObjectKey($key),
            new Url($this->cdn->getUrl($key)),
            new Size(strlen($videoContent)),
        );

        $entity->setOutputFile($file);
        $entity->setState(State::COMPLETED);

        if (!$entity->hasMeta('xai_cost_calculated')) {
            $requestParams = $entity->getRequestParams();
            $duration = $requestParams->duration ?? 8;
            $duration = is_numeric($duration) ? (int) $duration : 8;
            $duration = max(1, min(15, $duration));

            $cost = $this->calc->calculate($duration, $model);

            $imageCount = (int) ($entity->getMeta('input_image_count') ?: 0);
            if ($imageCount > 0) {
                $imageCost = $this->calc->calculate($imageCount, $model, CostCalculator::IMAGE);
                $cost = new CreditCount($cost->value + $imageCost->value);
            }

            $reserved = new CreditCount(
                (float) ($entity->getMeta('reserved_credit') ?: 0)
            );
            $ws->unallocate($reserved);
            $ws->deductCredit($cost, $this->negativeBalance);

            $entity->addCost($cost);
            $entity->addMeta('xai_cost_calculated', true);

            $event = new CreditUsageEvent($ws, $cost);
            $this->dispatcher->dispatch($event);
        }
    }
}
