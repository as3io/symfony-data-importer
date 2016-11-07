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
use As3\Modlr\Metadata\AttributeMetadata;

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
        $types = [];
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
        $identifier = $oId = isset($kvs['_id']) ? $kvs['_id'] : null;
        if ($identifier instanceof \MongoId) {
            $identifier = (string) $identifier;
        }

        $model = $this->storageEngine->create($scn, $identifier)->apply($kvs);
        $legacy = $kvs['legacy'];
        $kvs = $this->extractRawModelValues($scn, $model);
        $em = $this->getMetadataFor($scn);

        foreach ($em->attributes as $attribute) {
            $key = $attribute->getKey();
            if (!isset($kvs[$key])) {
                continue;
            }
            $value = &$kvs[$key];
            $value = $this->sanitizeAttribute($attribute, $value);
        }

        foreach ($em->embeds as $embed) {
            $key = $embed->getKey();
            if (!isset($kvs[$key])) {
                continue;
            }
            if ('many' === $embed->embedType) {
                foreach ($kvs[$key] as $k => $v) {
                    foreach ($embed->embedMeta->attributes as $attribute) {
                        $attrKey = $attribute->getKey();
                        if (!isset($v[$attrKey])) {
                            continue;
                        }
                        $value = &$kvs[$key][$k][$attrKey];
                        $value = $this->sanitizeAttribute($attribute, $value);
                    }
                }
            } else {
                foreach ($embed->embedMeta->attributes as $attribute) {
                    $attrKey = $attribute->getKey();
                    if (!isset($kvs[$key][$attrKey])) {
                        continue;
                    }
                    $value = &$kvs[$key][$attrKey];
                    $value = $this->sanitizeAttribute($attribute, $value);
                }
            }
        }

        if (null === $identifier) {
            unset($kvs['_id']);
        }

        if (isset($kvs['_id']) && $kvs['_id'] !== $oId) {
            $kvs['_id'] = $oId;
        }

        $kvs['legacy'] = $legacy;

        return $kvs;
    }

    private function sanitizeAttribute(AttributeMetadata $md, $value)
    {
        if ('object' === $md->dataType) {
            $value = json_decode(json_encode($value), true);
        }
        return $value;
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
