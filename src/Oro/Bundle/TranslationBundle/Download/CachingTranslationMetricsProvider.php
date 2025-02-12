<?php
declare(strict_types=1);

namespace Oro\Bundle\TranslationBundle\Download;

use Exception;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Provides translation metrics (translation completeness and last build date) using an adapter
 * to the translation service and caches them for 1 day.
 */
class CachingTranslationMetricsProvider implements TranslationMetricsProviderInterface
{
    public const CACHE_KEY = 'translation_statistic';
    private const CACHE_TTL = 86400;

    protected CacheInterface $cache;
    protected TranslationServiceAdapterInterface $adapter;
    protected LoggerInterface $logger;

    protected ?array $metrics = null;

    public function __construct(
        TranslationServiceAdapterInterface $adapter,
        CacheInterface $cache,
        LoggerInterface $logger
    ) {
        $this->adapter = $adapter;
        $this->cache = $cache;
        $this->logger = $logger;
    }

    public function getAll(): array
    {
        if (null === $this->metrics) {
            $this->populateMetrics();
        }

        foreach ($this->metrics as $languageCode => $metrics) {
            $this->metrics[$languageCode] = $this->convertLastBuildDateToDateTimeOrUnset($metrics);
        }

        return $this->metrics;
    }

    public function getForLanguage(string $languageCode): ?array
    {
        if (null === $this->metrics) {
            $this->populateMetrics();
        }

        if (!isset($this->metrics[$languageCode])) {
            return null;
        }

        return $this->convertLastBuildDateToDateTimeOrUnset($this->metrics[$languageCode]);
    }

    /**
     * Retrieves metrics from the cache if already cached, or from the translation service adapter
     * (and populates the cache) otherwise.
     */
    private function populateMetrics(): void
    {
        $data = $this->cache->get(static::CACHE_KEY, function (ItemInterface $item) {
            $item->expiresAfter(static::CACHE_TTL);
            return $this->fetchMetrics();
        });

        $this->metrics = [];
        if ($data) {
            foreach ($data as $metrics) {
                $this->metrics[$metrics['code']] = $metrics;
            }
        }
    }

    /**
     * Converts 'lastBuildDate' value into a \DateTime instance if the date-time is valid, and unsets it otherwise.
     */
    private function convertLastBuildDateToDateTimeOrUnset(array $metrics): array
    {
        if (isset($metrics['lastBuildDate'])) {
            if (\is_string($metrics['lastBuildDate'])) {
                try {
                    $metrics['lastBuildDate'] = new \DateTime(
                        $metrics['lastBuildDate'],
                        new \DateTimeZone('UTC')
                    );
                } catch (Exception $e) {
                    unset($metrics['lastBuildDate']);
                }
            } elseif (!($metrics['lastBuildDate'] instanceof \DateTimeInterface)) {
                unset($metrics['lastBuildDate']);
            }
        }

        return $metrics;
    }

    /**
     * Fetches translations metrics from the adapter while silencing all exceptions.
     */
    private function fetchMetrics(): ?array
    {
        try {
            $data = $this->adapter->fetchTranslationMetrics();
        } catch (\Throwable $e) {
            $this->logger->error('Failed to fetch translation metrics.', ['exception' => $e]);
            return null;
        }

        return $data;
    }
}
