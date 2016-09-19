<?php

namespace As3\SymfonyData\Import\Persister;

use As3\SymfonyData\Import\Persister;
use As3\SymfonyData\Import\Configuration;
use As3\Modlr\Store\Store;
// use Cygnus\ModlrBundle\Metadata\ModlrMetadata;
// use Cygnus\PlatformBundle\Manager\ConfigValueLoader;
use As3\Modlr\Exception\MetadataException;
// use Cygnus\ApplicationBundle\ModelRouting\ModelRouter;
// use Cygnus\ApplicationBundle\ModelRouting\As3ModelRedirects;

/**
 * Utilizes the As3Modlr Store class to persist data
 *
 * @author Josh Worden <jworden@southcomm.com>
 */
final class As3Modlr extends Persister
{
    /**
     * DI Constructor
     *
     * @param   ConfigValueLoader   $configLoader   The application's configuration
     * @param   Store               $storageEngine  The Modlr Store
     */
    public function __construct(Store $storageEngine)
    {
        // $this->configLoader = $configLoader;
        $this->storageEngine = $storageEngine;
        // $this->modelRouter = $modelRouter;
        // $this->as3ModelRedirects = $as3ModelRedirects;
    }

    /**
     * Uses reflection to get underlying Store persister.
     *
     * @param   string  $scn
     * @return  As3\Modlr\Persister\PersisterInterface
     */
    private function getPersisterFor($scn)
    {
        $reflobj = new \ReflectionObject($this->storageEngine);
        $accessor = $reflobj->getMethod('getPersisterFor');
        $accessor->setAccessible(true);
        return $accessor->invoke($this->storageEngine, $scn);
    }

    /**
     * Uses reflection to extract the raw model values from the Store's persister.
     *
     * @param   string  $scn
     * @param   Model   $model
     * @return  array
     */
    private function extractRawModelValues($scn, $model)
    {
        $persister = $this->getPersisterFor($scn);
        $reflobj = new \ReflectionObject($persister);
        $accessor = $reflobj->getMethod('createInsertObj');
        $accessor->setAccessible(true);
        return $accessor->invoke($persister, $model);
    }

    /**
     * {@inheritdoc}
     */
    public function getModelTypes()
    {
        $types = ['location', 'tag-family', 'tag', 'publication', 'publication-issue', 'publication-section', 'publication-section-award'];
        return array_unique(array_merge($types, $this->storageEngine->getModelTypes()));
    }

    /**
     * {@inheritdoc}
     */
    public function getSubscriberLooper($typeKey)
    {
        return new Subscriber\As3ModlrSubscriberPass($typeKey, $this);
    }

    /**
     * {@inheritdoc}
     */
    final public function findModelForPath($path, $url)
    {
        $request = \Symfony\Component\HttpFoundation\Request::create($url);
        return $this->as3ModelRedirects->getRedirectPath($path, $request);
    }

    /**
     * {@inheritdoc}
     */
    protected function wipeData()
    {
        foreach ($this->storageEngine->getModelTypes() as $type) {
            $this->getCollectionForModel($type)->remove([]);
        }
    }

    /**
     * {@inheritdoc}
     *
     * @todo Eventually modlr should be creating schema, but doesn't yet support it.
     */
    final public function updateSchema()
    {
        if (Configuration::DATA_MODE_WIPE === $mode = $this->configuration->getDataMode()) {
            $this->wipeData();
        }

        if (Configuration::SCHEMA_MODE_NONE !== $mode = $this->configuration->getSchemaMode()) {
            $indices = [
                [
                    'fields'    => ['legacy.id' => 1, 'legacy.source' => 1],
                    'options'   => ['unique' => true, 'sparse' => true]
                ],
                [
                    'fields'    => ['urlPath' => 1, 'deleted' => 1],
                    'options'   => []
                ],
                [
                    'fields'    => ['redirects' => 1, 'deleted' => 1],
                    'options'   => []
                ],
                [
                    'fields'    => ['name' => 'text'],
                    'options'   => ['textIndexVersion'  => 2]
                ]
            ];
            foreach ($this->storageEngine->getModelTypes() as $type) {
                foreach ($indices as $index) {
                    $this->getCollectionForModel($type)->ensureIndex($index['fields'], $index['options']);
                }
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function supports($scn)
    {
        try {
            $md = $this->getMetadataFor($scn);
            return true;
        } catch (MetadataException $e) {
            return false;
        }
    }

    /**
     * Returns the Modlr Metadata for the specified Entity type
     *
     * @param   string  $scn
     * @return  As3\Modlr\Metadata\EntityMetadata
     */
    private function getMetadataFor($scn)
    {
        return $this->storageEngine->getMetadataForType($scn);
    }

    /**
     * {@inheritdoc}
     *
     * @todo Better date val conversion in getAttributeDbValue & unit testing!
     */
    public function sanitizeModel($scn, array $kvs)
    {
        $identifier = isset($kvs['_id']) ? $kvs['_id'] : null;
        if ($identifier instanceof \MongoId) {
            $identifier = (string) $identifier;
        }

        $model = $this->storageEngine->create($scn, $identifier)->apply($kvs);
        $kvs = $this->extractRawModelValues($scn, $model);

        $em = $this->getMetadataFor($scn);
        foreach ($em->attributes as $attribute) {
            if (!isset($kvs[$attribute->getKey()])) {
                continue;
            }
            if ('object' === $attribute->dataType) {
                $kvs[$attribute->getKey()] = json_decode(json_encode($kvs[$attribute->getKey()]), true);
            }
        }

        if (null === $identifier) {
            unset($kvs['_id']);
        }

        if (isset($kvs['_id']) && $kvs['_id'] !== $identifier) {
            $kvs['_id'] = $identifier;
        }

        return $kvs;
    }

    /**
     * {@inheritdoc}
     */
    final public function createReference($scn, $field, $ident, $type = null)
    {
        throw new \RuntimeException('NYI');

        // return $this->storageEngine->getMetadataForType($scn)->getQuery()->getFormatter()->formatReference(???)
    }

    /**
     * {@inheritdoc}
     *
     * @todo as3_modlr doesn't support auto-increment IDs yet.
     */
    protected function generateId($scn)
    {
        // throw new \RuntimeException('NYI');
        return;
        return $this->storageEngine->generateIdentifier($scn);
    }

    /**
     * {@inheritdoc}
     *
     * @todo Store::getPersisterFor was publicized, change this?
     */
    final public function getCollectionForModel($scn)
    {
        $em = $this->getMetadataFor($scn);
        return $this->getPersisterFor($scn)->getQuery()->getModelCollection($em);
    }

    /**
     * {@inheritdoc}
     */
    final public function getDatabaseName($scn)
    {
        $em = $this->getMetadataFor($scn);
        return $em->persistence->db;
    }
}
