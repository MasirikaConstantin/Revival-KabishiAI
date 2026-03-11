<?php

declare(strict_types=1);

namespace Ai\Infrastructure\Services\xAi;

use Ai\Domain\Entities\ImageEntity;
use Ai\Domain\Exceptions\DomainException;
use Ai\Domain\Exceptions\InsufficientCreditsException;
use Ai\Domain\Exceptions\ModelNotSupportedException;
use Ai\Domain\Image\ImageServiceInterface;
use Ai\Domain\ValueObjects\Model;
use Ai\Domain\ValueObjects\RequestParams;
use Ai\Domain\ValueObjects\State;
use Ai\Infrastructure\Services\AbstractBaseService;
use Ai\Infrastructure\Services\CostCalculator;
use Billing\Domain\ValueObjects\CreditCount;
use Doctrine\ORM\EntityManagerInterface;
use Easy\Container\Attributes\Inject;
use File\Domain\Entities\ImageFileEntity;
use File\Domain\ValueObjects\Height;
use File\Domain\ValueObjects\ObjectKey;
use File\Domain\ValueObjects\Size;
use File\Domain\ValueObjects\Storage;
use File\Domain\ValueObjects\Url;
use File\Domain\ValueObjects\Width;
use File\Infrastructure\BlurhashGenerator;
use Override;
use Psr\Http\Message\UploadedFileInterface;
use Shared\Infrastructure\FileSystem\CdnInterface;
use Shared\Infrastructure\Services\ModelRegistry;
use User\Domain\Entities\UserEntity;
use Workspace\Domain\Entities\WorkspaceEntity;

class ImageService extends AbstractBaseService implements ImageServiceInterface
{
    public function __construct(
        private Client $client,
        private CostCalculator $calc,
        private CdnInterface $cdn,
        private ModelRegistry $registry,
        private EntityManagerInterface $em,

        #[Inject('option.features.imagine.is_enabled')]
        private bool $isToolEnabled = false,
    ) {
        parent::__construct($registry, 'xai', 'image');
    }

    #[Override]
    public function generateImage(
        WorkspaceEntity $workspace,
        UserEntity $user,
        Model $model,
        ?array $params = null
    ): ImageEntity {
        if (!$this->supportsModel($model)) {
            throw new ModelNotSupportedException(
                self::class,
                $model
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

        $endpoint = '/v1/images/generations';

        $data = [
            'prompt' => $params['prompt'],
            'model' => $model->value
        ];

        if (array_key_exists('aspect_ratio', $params)) {
            $data['aspect_ratio'] = $params['aspect_ratio'];
        }

        if (array_key_exists('quality', $params)) {
            $data['quality'] = $params['quality'];
        }

        if (array_key_exists('resolution', $params)) {
            $data['resolution'] = $params['resolution'];
        }

        $imageInputs = [];
        if (
            isset($params['images'])
            && is_array($params['images'])
            && count($params['images']) > 0
        ) {
            /** @var UploadedFileInterface $image */
            foreach ($params['images'] as $image) {
                $content = $image->getStream()->getContents();
                $filename = $image->getClientFilename() ?? 'image';
                $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION) ?: 'jpg');
                $mime = $image->getClientMediaType() ?: match ($ext) {
                    'jpg', 'jpeg' => 'image/jpeg',
                    'png' => 'image/png',
                    'webp' => 'image/webp',
                    default => 'image/jpeg',
                };
                $dataUri = 'data:' . $mime . ';base64,' . base64_encode($content);
                $imageInputs[] = ['url' => $dataUri, 'type' => 'image_url'];
            }

            if (count($imageInputs) > 0) {
                $endpoint = '/v1/images/edits';
                $data[count($imageInputs) === 1 ? 'image' : 'images'] = count($imageInputs) === 1
                    ? $imageInputs[0]
                    : $imageInputs;
            }
        }

        $imageCount = count($imageInputs);

        try {
            $resp = $this->client->sendRequest('POST', $endpoint, $data);
        } finally {
            $workspace->unallocate($estimate);
        }

        $resp = json_decode($resp->getBody()->getContents());

        if (!isset($resp->data) || !is_array($resp->data) || count($resp->data) === 0) {
            throw new DomainException('Failed to generate image');
        }

        $url = $resp->data[0]->url;
        $resp = $this->client->sendRequest('GET', $url);
        $content = $resp->getBody()->getContents();

        if ($imageCount > 0) {
            $inputCost = $this->calc->calculate($imageCount, $model, CostCalculator::IMAGE);
            $outputCost = $this->calc->calculate(1, $model, CostCalculator::OUTPUT);
            $cost = new CreditCount($inputCost->value + $outputCost->value);
        } else {
            $cost = $this->calc->calculate(1, $model);
        }

        // Save image to CDN
        $name = $this->cdn->generatePath('png', $workspace, $user);
        $this->cdn->write($name, $content);

        $img = imagecreatefromstring($content);
        $width = imagesx($img);
        $height = imagesy($img);

        $file = new ImageFileEntity(
            new Storage($this->cdn->getAdapterLookupKey()),
            new ObjectKey($name),
            new Url($this->cdn->getUrl($name)),
            new Size(strlen($content)),
            new Width($width),
            new Height($height),
            BlurhashGenerator::generateBlurHash($img, $width, $height),
        );

        $entity = new ImageEntity(
            $workspace,
            $user,
            $model,
            RequestParams::fromArray($params),
            $cost
        );

        $entity->setOutputFile($file);
        $entity->setState(State::COMPLETED);

        return $entity;
    }
}
