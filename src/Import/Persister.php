<?php

namespace As3\SymfonyData\Import;

use Cygnus\PlatformBundle\Manager\ManagerConfigurator;

/**
 * Handles persisting model data using the supplied storageEngine
 *
 * @author Josh Worden <jworden@southcomm.com>
 */
abstract class Persister implements PersisterInterface
{
    const WRITE_MODE_INSERT = 'insert';
    const WRITE_MODE_UPSERT = 'upsert';
    const WRITE_MODE_UPDATE = 'update';

    /**
     * @var Configuration
     */
    public $configuration;

    /**
     * @var Cygnus\ModlrBundle\ModelRouting\As3ModelRedirects
     */
    protected $as3ModelRedirects;

    /**
     * @var Cygnus\PlatformBundle\Manager\ConfigValueLoader
     */
    protected $configLoader;

    /**
     * @var Cygnus\ModlrBundle\ModelRouting\ModelRouter
     */
    protected $modelRouter;

    /**
     * @var Cygnus\ModlrBundle\ApiNext\ModlrApi|As3\Modlr\Store
     */
    protected $storageEngine;

    /**
     * The current write mode
     */
    protected $writeMode = 'upsert';

    /**
     * Create indicies
     */
    abstract public function updateSchema();

    /**
     * {@inheritdoc}
     */
    abstract public function sanitizeModel($typeKey, array $kvs);

    /**
     * {@inheritdoc}
     */
    abstract public function createReference($typeKey, $field, $ident, $refTypeKey = null);

    /**
     * {@inheritdoc}
     */
    abstract public function getCollectionForModel($typeKey);

    /**
     * {@inheritdoc}
     */
    abstract public function getDatabaseName($typeKey);

    /**
     * {@inheritdoc}
     */
    abstract public function getSubscriberLooper($typeKey);

    /**
     * {@inheritdoc}
     */
    abstract public function getModelTypes();

    /**
     * {@inheritdoc}
     */
    abstract public function findModelForPath($path, $url);

    /**
     * {@inheritdoc}
     */
    final public function getConfigLoader()
    {
        return $this->configLoader;
    }

    /**
     * Returns the loaded configuration
     */
    final public function getConfiguration()
    {
        return $this->configuration;
    }

    /**
     * {@inheritdoc}
     */
    final public function getStorageEngine()
    {
        return $this->storageEngine;
    }

    /**
     * Fake DI, sets the import Configuration instance. Write/data modes are read
     * from the configuration to keep them in sync across all Persisters.
     *
     * @param   Configuration   $config     The import configuration
     */
    final public function setConfiguration(Configuration $config)
    {
        $this->configuration = $config;
    }

    /**
     * Returns the current write mode
     *
     * @return string
     */
    final public function getWriteMode()
    {
        switch ($this->getDataMode()) {
            case Configuration::DATA_MODE_WIPE:
            case Configuration::DATA_MODE_PROGRESSIVE:
                $this->setWriteMode(static::WRITE_MODE_INSERT);
                break;
            default:
                $this->setWriteMode(static::WRITE_MODE_UPSERT);
        }
        return $this->writeMode;
    }

    /**
     * {@inheritdoc}
     */
    final public function insert($typeKey, array $item)
    {
        $item = $this->sanitizeModel($typeKey, $item);
        switch ($this->getWriteMode()) {
            case static::WRITE_MODE_INSERT:
                $items = $this->appendIds($typeKey, [$item]);
                $item = $this->doInsert($typeKey, $items[0]);
                break;
            case static::WRITE_MODE_UPSERT:
                $item = $this->doUpsert($typeKey, $item);
                break;
            case static::WRITE_MODE_UPDATE:
                $item = $this->doUpdate($typeKey, $item);
                break;
            default:
                throw new Exception\WriteModeException(sprintf('Unsupported write mode `%s`!', $this->getWriteMode()));
        }
        return $item;
    }

    /**
     * {@inheritdoc}
     */
    final public function batchInsert($typeKey, array $items)
    {
        if (empty($items)) {
            throw new \InvalidArgumentException('Nothing to insert!');
        }
        $items = array_map(function($item) use($typeKey, $items) { return $this->sanitizeModel($typeKey, $item); }, $items);
        switch ($mode = $this->getWriteMode()) {
            case static::WRITE_MODE_INSERT:
                $items = $this->appendIds($typeKey, $items);
                $items = $this->doBatchInsert($typeKey, $items);
                break;
            case static::WRITE_MODE_UPSERT:
                $items = $this->doBatchUpsert($typeKey, $items);
                break;
            case static::WRITE_MODE_UPDATE:
                $items = $this->doBatchUpdate($typeKey, $items);
                break;
            default:
                throw new Exception\WriteModeException(sprintf('Unsupported write mode `%s`!', $this->getWriteMode()));
        }
        return $items;
    }

    /**
     * Performs a batch update
     *
     * @param   string  $scn
     * @param   array   $criteria   The targeting parameters
     * @param   array   $update     The modifications to make to the targeted documents
     */
    final public function batchUpdate($scn, array $criteria, array $update)
    {
        return $this->getCollectionForModel($scn)->update($criteria, $update, ['multiple' => true]);
    }

    /**
     * {@inheritdoc}
     */
    final public function getEnv()
    {
        return $this->configLoader->getContext()->getEnvironment();
    }

    /**
     * {@inheritdoc}
     */
    final public function getActiveContext()
    {
        return getenv(ManagerConfigurator::ENV_ACTIVE_CONTEXT);
    }

    /**
     * {@inheritdoc}
     */
    final public function getDataMode()
    {
        return $this->configuration->getDataMode();
    }

    /**
     * Generate an ID for the specified model
     *
     * @param   string  $scn    The model type
     * @return  int|null
     */
    abstract protected function generateId($scn);

    /**
     * Handles wiping data when used with DATA_MODE_WIPE.
     */
    abstract protected function wipeData();

    /**
     * Handles a single document insertion
     *
     * @param   string  $scn    The model type
     * @param   array   $kvs    The model's keyValues.
     * @return  array   The inserted modelValues
     */
    final protected function doInsert($scn, array $kvs)
    {
        $this->getCollectionForModel($scn)->insert($kvs);
        return $kvs;
    }

    /**
     * Handles a single document upsert
     *
     * @param   string  $scn    The model type
     * @param   array   $kvs    The model's keyValues.
     * @return  array   The upserted modelValues
     */
    final protected function doUpsert($scn, array $kvs)
    {
        $upsertKey = isset($kvs['external']) ? 'external' : 'legacy';
        $upsertNs  = 'legacy' === $upsertKey ? 'source' : 'namespace';
        $upsertId  = 'legacy' === $upsertKey ? 'id' : 'identifier';

        $update = ['$set' => $kvs];
        if (!isset($kvs[$upsertKey][$upsertId]) || !isset($kvs[$upsertKey][$upsertNs])) {
            throw new Exception\UpsertException(sprintf('%s.%s or %s.%s was not specified, cannot upsert!', $upsertKey, $upsertId, $upsertKey, $upsertNs));
        }

        if (!isset($kvs['_id'])) {
            $id = $this->generateId($scn);
            if (null !== $id) {
                $update['$setOnInsert'] = ['_id' => $id];
            }
        }

        $query = [
            sprintf('%s.%s', $upsertKey, $upsertId) => $kvs[$upsertKey][$upsertId],
            sprintf('%s.%s', $upsertKey, $upsertNs) => $kvs[$upsertKey][$upsertNs],
        ];

        $r = $this->getCollectionForModel($scn)->update($query, $update, ['upsert' => true]);

        if (true === $r['updatedExisting']) {
            $id = $this->getCollectionForModel($scn)->findOne($query, ['_id'])['_id'];
            $kvs['_id'] = $id;
        } else {
            if (!isset($kvs['_id'])) {
                $kvs['_id'] = $r['upserted'];
            }
        }
        return $kvs;
    }

    /**
     * Handles a single document update
     *
     * @param   string  $scn    The model type
     * @param   array   $kvs    The model's keyValues.
     * @return  array   The inserted modelValues
     */
    final protected function doUpdate($scn, array $kvs)
    {
        throw new \InvalidArgumentException(sprintf('%s NYI', __METHOD__));
    }

    /**
     * Sets the current write mode
     * @param   string  $mode   The write mode to use.
     */
    final protected function setWriteMode($mode)
    {
        if (!in_array($mode, [static::WRITE_MODE_INSERT, static::WRITE_MODE_UPSERT, static::WRITE_MODE_UPDATE])) {
            throw new \InvalidArgumentException(sprintf('Passed write mode "%s" is invalid!', $mode));
        }
        $this->writeMode = $mode;
    }

    final protected function appendIds($scn, array $items)
    {
        foreach ($items as $key => $value) {
            if (!isset($value['_id'])) {
                $id = $this->generateId($scn);
                if (null !== $id) {
                    $items[$key]['_id'] = $id;
                }
            }
        }
        return $items;
    }

    final protected function doBatchInsert($scn, array $items)
    {
        $collection = $this->getCollectionForModel($scn);
        $collection->batchInsert($items);
        return $items;
    }

    final protected function doBatchUpsert($scn, array $items)
    {
        foreach ($items as $k => $item) {
            $items[$k] = $this->doUpsert($scn, $item);
        }
        return $items;
    }

    final protected function doBatchUpdate($scn, array $items)
    {
        throw new \InvalidArgumentException(sprintf('%s NYI', __METHOD__));
    }
}
