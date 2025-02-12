<?php

namespace Oro\Bundle\AttachmentBundle\Imagine\Provider;

use Liip\ImagineBundle\Imagine\Filter\FilterConfiguration;
use Oro\Bundle\AttachmentBundle\Tools\FilenameExtensionHelper;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Provider URL for the image with applied LiipImagine filter.
 */
class ImagineUrlProvider implements ImagineUrlProviderInterface
{
    private UrlGeneratorInterface $urlGenerator;

    private FilterConfiguration $filterConfiguration;

    public function __construct(UrlGeneratorInterface $urlGenerator, FilterConfiguration $filterConfiguration)
    {
        $this->urlGenerator = $urlGenerator;
        $this->filterConfiguration = $filterConfiguration;
    }

    public function getFilteredImageUrl(
        string $path,
        string $filterName,
        string $format = '',
        int $referenceType = UrlGeneratorInterface::ABSOLUTE_URL
    ): string {
        if (!$format) {
            $format = $this->filterConfiguration->get($filterName)['format'] ?? '';
        }

        $path = FilenameExtensionHelper::addExtension(ltrim($path, '/'), $format);

        $params = [
            'path' => $path,
            'filter' => $filterName,
        ];

        return $this->urlGenerator->generate('oro_imagine_filter', $params, $referenceType);
    }
}
