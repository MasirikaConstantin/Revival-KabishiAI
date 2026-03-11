<?php

declare(strict_types=1);

namespace Ai\Infrastructure\Services\xAi;

use Ai\Domain\Entities\VideoEntity;
use Ai\Domain\Exceptions\DomainException;
use Ai\Domain\Exceptions\InsufficientCreditsException;
use Ai\Domain\Exceptions\ModelNotSupportedException;
use Ai\Domain\ValueObjects\ExternalId;
use Ai\Domain\ValueObjects\Model;
use Ai\Domain\ValueObjects\RequestParams;
use Ai\Domain\ValueObjects\State;
use Ai\Domain\Video\VideoServiceInterface;
use Ai\Infrastructure\Services\AbstractBaseService;
use Ai\Infrastructure\Services\CostCalculator;
use Doctrine\ORM\EntityManagerInterface;
use Easy\Container\Attributes\Inject;
use Psr\Http\Message\UploadedFileInterface;
use Shared\Infrastructure\FileSystem\CdnInterface;
use Shared\Infrastructure\Services\ModelRegistry;
use Throwable;
use User\Domain\Entities\UserEntity;
use Workspace\Domain\Entities\WorkspaceEntity;

class VideoService extends AbstractBaseService implements VideoServiceInterface
{
    public function __construct(
        private Client $client,
        private CostCalculator $calc,
        private CdnInterface $cdn,
        private ModelRegistry $registry,
        private EntityManagerInterface $em,

        #[Inject('option.site.domain')]
        private ?string $domain = null,

        #[Inject('option.site.is_secure')]
        private ?bool $isSecure = null,

        #[Inject('option.xai.webhook_secret')]
        private ?string $webhookSecret = null,
    ) {
        parent::__construct($registry, 'xai', 'video');
    }

    public function generateVideo(
        WorkspaceEntity $workspace,
        UserEntity $user,
        Model $model,
        ?array $params = null
    ): VideoEntity {
        if (!$this->supportsModel($model)) {
            throw new ModelNotSupportedException(self::class, $model);
        }

        if (empty($this->webhookSecret)) {
            throw new DomainException(
                'Video generation is temporarily unavailable.'
            );
        }

        if (!$params || !array_key_exists('prompt', $params)) {
            throw new DomainException('Missing parameter: prompt');
        }

        $estimate = $this->calc->estimate($model);
        if (!$workspace->hasSufficientCredit($estimate)) {
            throw new InsufficientCreditsException();
        }

        $workspace->allocate($estimate);
        $this->em->flush(); // Save the workspace with the allocated credits

        $entity = new VideoEntity(
            $workspace,
            $user,
            $model,
            RequestParams::fromArray($params ?? [])
        );
        $entity->setState(State::PROCESSING);

        $uploadUrl = $this->buildSignedUploadUrl($entity);

        $body = [
            'model' => $model->value,
            'prompt' => $params['prompt'],
            'duration' => (int) ($params['duration'] ?? 8),
            'aspect_ratio' => $params['aspect_ratio'] ?? '16:9',
            'resolution' => $params['resolution'] ?? '720p',
            'output' => [
                'upload_url' => $uploadUrl,
            ],
        ];

        $allowedAspectRatios = ['1:1', '16:9', '9:16', '4:3', '3:4', '3:2', '2:3'];
        if (
            isset($params['aspect_ratio'])
            && in_array($params['aspect_ratio'], $allowedAspectRatios)
        ) {
            $body['aspect_ratio'] = $params['aspect_ratio'];
        }

        $allowedResolutions = ['720p', '480p'];
        if (
            isset($params['resolution'])
            && in_array($params['resolution'], $allowedResolutions)
        ) {
            $body['resolution'] = $params['resolution'];
        }

        $duration = (int) ($params['duration'] ?? 8);
        $duration = max(1, min(15, $duration));
        $body['duration'] = $duration;

        if (isset($params['frames']) && is_array($params['frames']) && count($params['frames']) > 0) {
            /** @var UploadedFileInterface $frame */
            $frame = $params['frames'][0];
            $ext = strtolower(pathinfo($frame->getClientFilename() ?? 'image', PATHINFO_EXTENSION) ?: 'jpg');
            $key = $this->cdn->generatePath($ext, $workspace, $user);
            $this->cdn->write($key, $frame->getStream()->getContents());
            $body['image'] = ['url' => $this->cdn->getUrl($key)];
        }

        $entity->addMeta('input_image_count', isset($body['image']) ? 1 : 0);

        $safeParams = [
            'prompt' => $params['prompt'],
            'duration' => $body['duration'],
            'aspect_ratio' => $body['aspect_ratio'],
            'resolution' => $body['resolution'],
        ];
        if (isset($body['image'])) {
            $safeParams['image_url'] = $body['image']['url'];
        }
        $entity->setRequestParams(RequestParams::fromArray($safeParams));

        try {
            $resp = $this->client->sendRequest(
                'POST',
                '/v1/videos/generations',
                $body
            );
        } catch (Throwable $th) {
            $workspace->unallocate($estimate);
            throw $th;
        }

        $content = $resp->getBody()->getContents();
        $data = json_decode($content);

        $requestId = $data->request_id;
        $entity->addMeta('request_id', $requestId);
        $entity->addMeta('reserved_credit', $estimate->value);
        $entity->setExternalId(new ExternalId('xai/' . $requestId));

        return $entity;
    }

    private function buildSignedUploadUrl(VideoEntity $entity): string
    {
        $entityId = $entity->getId()->getValue()->toString();
        $timestamp = (string) time();
        $payload = $entityId . '|' . $timestamp;
        $signature = base64_encode(
            hash_hmac('sha256', $payload, $this->webhookSecret, true)
        );

        $protocol = $this->isSecure ? 'https' : 'http';
        $domain = $this->domain ?? 'localhost';

        return sprintf(
            '%s://%s/webhooks/xai/%s?sig=%s&t=%s',
            $protocol,
            $domain,
            $entityId,
            urlencode($signature),
            $timestamp
        );
    }
}
