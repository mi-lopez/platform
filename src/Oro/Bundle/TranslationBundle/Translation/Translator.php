<?php

namespace Oro\Bundle\TranslationBundle\Translation;

use Oro\Bundle\DistributionBundle\Handler\ApplicationState;
use Oro\Bundle\TranslationBundle\Event\AfterCatalogueDump;
use Oro\Bundle\TranslationBundle\Event\InvalidateTranslationCacheEvent;
use Oro\Bundle\TranslationBundle\Provider\TranslationDomainProvider;
use Oro\Bundle\TranslationBundle\Strategy\TranslationStrategyProvider;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Translation\Translator as BaseTranslator;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Translation\Exception\InvalidArgumentException;
use Symfony\Component\Translation\Formatter\MessageFormatter;
use Symfony\Component\Translation\Loader\LoaderInterface;
use Symfony\Component\Translation\MessageCatalogue;
use Symfony\Component\Translation\MessageCatalogueInterface;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Decorates Symfony translator by extending it, adds loading of dynamic resources for translations and ability to
 * select different translation strategies.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.TooManyFields)
 */
class Translator extends BaseTranslator
{
    public const DEFAULT_LOCALE = 'en';

    protected ?DynamicTranslationMetadataCache $databaseTranslationMetadataCache = null;
    protected ?CacheInterface $resourceCache = null;
    /**
     *  [
     *      locale => [
     *          [
     *              'resource' => DynamicResourceInterface,
     *              'format'   => string,
     *              'code'     => string,
     *              'domain'   => string
     *          ],
     *          ...
     *      ],
     *      ...
     *  ]
     */
    protected array $dynamicResources = [];
    protected array $registeredResources = [];
    protected bool $installed;
    protected ?string $strategyName = null;
    protected MessageFormatter $messageFormatter;
    protected array $originalOptions;
    protected array $resourceFiles = [];
    private TranslationStrategyProvider $strategyProvider;
    private TranslationDomainProvider $translationDomainProvider;
    private EventDispatcherInterface $eventDispatcher;
    private LoggerInterface $logger;
    private array $cacheVary;
    private MessageCatalogueSanitizerInterface $catalogueSanitizer;
    private ApplicationState $applicationState;

    /**
     * @param ContainerInterface $container
     * @param MessageFormatter $formatter
     * @param null $defaultLocale
     * @param array $loaderIds
     * @param array $options
     */
    public function __construct(
        ContainerInterface $container,
        MessageFormatter $formatter,
        $defaultLocale = null,
        $loaderIds = [],
        array $options = []
    ) {
        parent::__construct($container, $formatter, $defaultLocale, $loaderIds, $options);

        $this->messageFormatter = $formatter;
        $this->originalOptions = $this->options;
        $this->resourceFiles = $this->options['resource_files'];
        $this->cacheVary = $this->options['cache_vary'] ?? [];
        $this->logger = new NullLogger();
    }

    public function setMessageCatalogueSanitizer(MessageCatalogueSanitizerInterface $catalogueSanitizer): void
    {
        $this->catalogueSanitizer = $catalogueSanitizer;
    }

    public function setStrategyProvider(TranslationStrategyProvider $strategyProvider): void
    {
        $this->strategyProvider = $strategyProvider;
    }

    public function setTranslationDomainProvider(TranslationDomainProvider $translationDomainProvider): void
    {
        $this->translationDomainProvider = $translationDomainProvider;
    }

    public function setEventDispatcher(EventDispatcherInterface $eventDispatcher): void
    {
        $this->eventDispatcher = $eventDispatcher;
    }

    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    public function setApplicationState(ApplicationState $applicationState): void
    {
        $this->applicationState = $applicationState;
        $this->installed = $applicationState->isInstalled();
    }

    /**
     * Collector of translations
     *
     * Collects all translations for corresponded domains and locale,
     * takes in account fallback of locales.
     * Method is used for exposing of collected translations.
     *
     * @param array $domains list of required domains, by default empty, means all domains
     * @param string|null $locale locale of translations, by default is current locale
     *
     * @return array
     */
    public function getTranslations(array $domains = [], ?string $locale = null): array
    {
        // if new strategy was selected
        if ($this->strategyProvider->getStrategy()->getName() !== $this->strategyName) {
            $this->applyCurrentStrategy();
        }

        if (null === $locale) {
            $locale = $this->getLocale();
        }

        if (!isset($this->catalogues[$locale])) {
            $this->loadCatalogue($locale);
        }

        $fallbackCatalogues = array();
        $fallbackCatalogues[] = $catalogue = $this->catalogues[$locale];
        while ($catalogue = $catalogue->getFallbackCatalogue()) {
            $fallbackCatalogues[] = $catalogue;
        }

        $domains = array_flip($domains);
        $translations = array();
        for ($i = count($fallbackCatalogues) - 1; $i >= 0; $i--) {
            $localeTranslations = $fallbackCatalogues[$i]->all();
            // if there are domains -> filter only their translations
            if ($domains) {
                $localeTranslations = array_intersect_key($localeTranslations, $domains);
            }
            foreach ($localeTranslations as $domain => $domainTranslations) {
                if (!empty($translations[$domain])) {
                    $translations[$domain] = array_merge($translations[$domain], $domainTranslations);
                } else {
                    $translations[$domain] = $domainTranslations;
                }
            }
        }

        return $translations;
    }

    public function trans(?string $id, array $parameters = [], string $domain = null, string $locale = null): ?string
    {
        try {
            return parent::trans($id, $parameters, $domain, $locale);
        } catch (InvalidArgumentException $e) {
            $this->logger->warning($e->getMessage(), ['exception' => $e]);

            $count = '';
            if (isset($parameters['%count%'])) {
                $count = ' ' . $parameters['%count%'];
                unset($parameters['%count%']);
            }

            return $this->trans($id, $parameters, $domain, $locale) . $count;
        }
    }

    /**
     * Checks if the given message has a translation.
     */
    public function hasTrans(string $id, ?string $domain = null, ?string $locale = null): bool
    {
        if (null === $locale) {
            $locale = $this->getLocale();
        }

        if (null === $domain) {
            $domain = 'messages';
        }

        if (!isset($this->catalogues[$locale])) {
            $this->loadCatalogue($locale);
        }

        $id = (string)$id;

        $catalogue = $this->catalogues[$locale];
        $result = $catalogue->defines($id, $domain);
        while (!$result && $catalogue = $catalogue->getFallbackCatalogue()) {
            $result = $catalogue->defines($id, $domain);
        }

        return $result;
    }

    public function addLoader(string $format, LoaderInterface $loader): void
    {
        if (null !== $this->resourceCache) {
            // wrap a resource loader by a caching loader to prevent loading of the same resource several times
            // it strongly decreases a translation catalogue loading time
            // for example a time of translation cache warming up is decreased in about 4 times
            $loader = new CachingTranslationLoader($loader, $this->resourceCache);
        }
        parent::addLoader($format, $loader);
    }

    public function getCatalogue(string $locale = null): MessageCatalogueInterface
    {
        // if new strategy was selected
        if ($this->strategyProvider->getStrategy()->getName() !== $this->strategyName) {
            $this->applyCurrentStrategy();
        }

        return parent::getCatalogue($locale);
    }

    public function addResource(string $format, $resource, string $locale, string $domain = null): void
    {
        if (is_string($resource)) {
            $this->resourceFiles[$locale][] = $resource;
        }

        parent::addResource($format, $resource, $locale, $domain);
    }

    public function warmUp(string $cacheDir): void
    {
        // skip warmUp when translator doesn't use cache
        if (null === $this->options['cache_dir']) {
            return;
        }

        $this->applyCurrentStrategy();

        // load catalogues only for needed locales. We cannot call parent::warmUp() because it would load catalogues
        // for all resources found in filesystem
        $locales = array_unique($this->getFallbackLocales());
        foreach ($locales as $locale) {
            // no need to reset catalogue like it is done in parent::warmUp() because all catalogues are already cleared
            // in applyCurrentStrategy(), so we are just loading new catalogue
            $this->loadCatalogue($locale);
        }
    }

    /**
     * Removes all cached message catalogs.
     */
    public function clearCache(): void
    {
        $this->applyCurrentStrategy();
        $locales = array_unique($this->getFallbackLocales());

        $affectedLocales = [];

        foreach ($locales as $locale) {
            $catalogueFile = $this->getCatalogueCachePath($locale);
            if ($this->isCatalogueCacheFileExits($catalogueFile)) {
                // The file cache should be invalidated before removal
                // @see https://bugs.php.net/bug.php?id=75939
                if (function_exists('opcache_invalidate')) {
                    opcache_invalidate($catalogueFile, true);
                }
                clearstatcache(true, $catalogueFile);
                unlink($catalogueFile);
                $affectedLocales[] = $locale;
            }
        }

        if (!empty($affectedLocales) && $this->isApplicationInstalled()) {
            if (count($locales) === 1) {
                $this->dispatchInvalidateTranslationCacheEvent(reset($locales));
            } else {
                $this->dispatchInvalidateTranslationCacheEvent();
            }
        }
    }

    /**
     * Rebuilds all cached message catalogs, w/o any delay at clients side
     */
    public function rebuildCache()
    {
        $cacheDir = $this->originalOptions['cache_dir'];

        $tmpDir = $cacheDir . uniqid('', true);

        $options = array_merge(
            $this->originalOptions,
            [
                'cache_dir' => $tmpDir,
                'resource_files' => array_map(
                    static function (array $localeResources) {
                        return array_unique($localeResources);
                    },
                    $this->resourceFiles
                )
            ]
        );

        // save current translation strategy
        $currentStrategy = $this->strategyProvider->getStrategy();

        // build translation cache for each translation strategy in tmp cache directory
        foreach ($this->strategyProvider->getStrategies() as $strategy) {
            $this->strategyProvider->setStrategy($strategy);

            $translator = new static(
                $this->container,
                $this->messageFormatter,
                $this->getLocale(),
                $this->loaderIds,
                $options
            );
            $translator->setStrategyProvider($this->strategyProvider);
            $translator->setTranslationDomainProvider($this->translationDomainProvider);
            $translator->setEventDispatcher($this->eventDispatcher);
            $translator->setApplicationState($this->applicationState);
            $translator->setDatabaseMetadataCache($this->databaseTranslationMetadataCache);
            $translator->setLogger($this->logger);
            $translator->setMessageCatalogueSanitizer($this->catalogueSanitizer);
            $translator->warmUp($tmpDir);
        }

        $filesystem = new Filesystem();

        // replace current cache with new cache
        $iterator = new \IteratorIterator(new \DirectoryIterator($tmpDir));
        foreach ($iterator as $path) {
            if (!$path->isFile()) {
                continue;
            }
            $filesystem->copy($path->getPathName(), $cacheDir . DIRECTORY_SEPARATOR . $path->getFileName(), true);
            $filesystem->remove($path->getPathName());
        }

        $filesystem->remove($tmpDir);

        // restore translation strategy and apply it to make use of new cache
        $this->strategyProvider->setStrategy($currentStrategy);
        $this->applyCurrentStrategy();

        if ($this->isApplicationInstalled()) {
            $this->dispatchInvalidateTranslationCacheEvent();
        }
    }

    /**
     * Sets a cache of dynamic translation metadata
     */
    public function setDatabaseMetadataCache(DynamicTranslationMetadataCache $cache): void
    {
        $this->databaseTranslationMetadataCache = $cache;
    }

    /**
     * Sets a cache of loaded translation resources
     */
    public function setResourceCache(CacheInterface $cache): void
    {
        $this->resourceCache = $cache;
    }

    protected function applyCurrentStrategy(): void
    {
        $strategy = $this->strategyProvider->getStrategy();

        // store current strategy name to skip all following requests to it
        $this->strategyName = $strategy->getName();

        // use current set of fallback locales to build translation cache
        $fallbackLocales = $this->strategyProvider->getAllFallbackLocales($strategy);

        // set new fallback locales and clear catalogues to generate new ones for new strategy
        $this->setFallbackLocales($fallbackLocales);
        $this->cacheVary['fallback_locales'] = $fallbackLocales;
    }

    protected function computeFallbackLocales($locale): array
    {
        return $this->strategyProvider->getFallbackLocales($this->strategyProvider->getStrategy(), $locale);
    }

    protected function loadCatalogue($locale): void
    {
        $this->initializeDynamicResources($locale);
        $isCacheReady = $this->isCatalogueCacheFileExits($this->getCatalogueCachePath($locale));
        parent::loadCatalogue($locale);

        if (!$isCacheReady && $this->isApplicationInstalled()) {
            $this->eventDispatcher->dispatch(
                new AfterCatalogueDump($this->catalogues[$locale]),
                AfterCatalogueDump::NAME
            );
        }
    }

    protected function initialize(): void
    {
        // save already loaded catalogues which are used as fallbacks to prevent their reloading second time
        // it change Symfony`s translator behavior and allows us to apply only already dumped catalogues in fallbacks
        $loadedCatalogues = array_intersect_key($this->catalogues, array_flip($this->getFallbackLocales()));

        // add dynamic resources just before the initialization
        // to be sure that they overrides static translations
        $this->registerDynamicResources();

        parent::initialize();

        // restore already loaded catalogues
        $this->catalogues = array_merge($this->catalogues, array_diff_key($loadedCatalogues, $this->catalogues));
    }

    protected function initializeCatalogue($locale): void
    {
        parent::initializeCatalogue($locale);

        if (!isset($this->catalogues[$locale])) {
            $this->catalogues[$locale] = new MessageCatalogue($locale);
        } else {
            $this->catalogueSanitizer->sanitizeCatalogue($this->catalogues[$locale]);
            foreach ($this->catalogueSanitizer->getSanitizationErrors() as $sanitizationError) {
                $this->logger->warning('Unsafe translation message found', ['error' => $sanitizationError]);
            }
        }
    }

    protected function initializeDynamicResources(string $locale): void
    {
        $this->ensureDynamicResourcesLoaded($locale);

        $isAnyCatalogFileRemoved = false;

        // check if any dynamic resource is changed and update translation catalogue if needed
        if (!empty($this->dynamicResources[$locale])) {
            $catalogueFile = $this->getCatalogueCachePath($locale);
            if ($this->isCatalogueCacheFileExits($catalogueFile)) {
                $time = filemtime($catalogueFile);
                foreach ($this->dynamicResources[$locale] as $item) {
                    /** @var DynamicResourceInterface $dynamicResource */
                    $dynamicResource = $item['resource'];
                    if (!$dynamicResource->isFresh($time)) {
                        // The file cache should be invalidated before removal
                        // @see https://bugs.php.net/bug.php?id=75939
                        if (function_exists('opcache_invalidate')) {
                            opcache_invalidate($catalogueFile, true);
                        }
                        clearstatcache(true, $catalogueFile);
                        // remove translation catalogue to allow parent class to rebuild it
                        unlink($catalogueFile);
                        // make sure that translations will be loaded from source resources
                        if (null !== $this->resourceCache) {
                            $this->resourceCache->clear();
                        }

                        $isAnyCatalogFileRemoved = true;

                        break;
                    }
                }
            }
        }

        if ($isAnyCatalogFileRemoved && $this->isApplicationInstalled()) {
            $this->dispatchInvalidateTranslationCacheEvent($locale);
        }
    }

    private function getCatalogueCachePath(string $locale): string
    {
        return $this->options['cache_dir']
            . '/catalogue.'
            . $locale
            . '.'
            . strtr(
                substr(base64_encode(hash('sha256', serialize($this->cacheVary), true)), 0, 7),
                '/',
                '_'
            )
            . '.php';
    }

    private function isCatalogueCacheFileExits(string $path): bool
    {
        clearstatcache(true, $path);

        return is_file($path);
    }

    /**
     * Adds dynamic translation resources to the translator
     */
    protected function registerDynamicResources(): void
    {
        foreach ($this->dynamicResources as $items) {
            foreach ($items as $item) {
                if (in_array($item, $this->registeredResources, true)) {
                    continue;
                }
                $this->registeredResources[] = $item;
                $this->addResource($item['format'], $item['resource'], $item['code'], $item['domain']);
            }
        }
    }

    /**
     * Makes sure that dynamic translation resources are added to $this->dynamicResources
     */
    protected function ensureDynamicResourcesLoaded(string $locale): void
    {
        if (null !== $this->databaseTranslationMetadataCache && $this->isApplicationInstalled()) {
            $hasDatabaseResources = false;
            if (!empty($this->dynamicResources[$locale])) {
                foreach ($this->dynamicResources[$locale] as $item) {
                    if ($item['format'] === 'oro_database_translation') {
                        $hasDatabaseResources = true;
                        break;
                    }
                }
            }
            if (!$hasDatabaseResources) {
                $locales = $this->getFallbackLocales();
                array_unshift($locales, $locale);
                $locales = array_unique($locales);

                $availableDomainsData = $this->translationDomainProvider
                    ->getAvailableDomainsForLocales($locales);
                foreach ($availableDomainsData as $item) {
                    $item['resource'] = new OrmTranslationResource(
                        $item['code'],
                        $this->databaseTranslationMetadataCache
                    );
                    $item['format'] = 'oro_database_translation';

                    $this->dynamicResources[$item['code']][] = $item;
                }
            }
        }
    }

    private function isApplicationInstalled(): bool
    {
        return !empty($this->installed);
    }

    private function dispatchInvalidateTranslationCacheEvent(?string $locale = null): void
    {
        $this->eventDispatcher->dispatch(
            new InvalidateTranslationCacheEvent($locale),
            InvalidateTranslationCacheEvent::NAME
        );
    }
}
