<?php

namespace As3\SymfonyData\Import;

class ConfigurationFactory
{
    public static function create($contextKey, $importers = [], $segments = [], $dataMode = null, $progressiveMode = null, $elasticMode = null, $subscriberMode = null, $schemaMode = null)
    {
        $config = new Configuration($contextKey, $importers, $segments);

        $config->setSchemaMode($schemaMode ?: Configuration::SCHEMA_MODE_NONE);
        $config->setDataMode($dataMode ?: Configuration::DATA_MODE_OVERWRITE);
        $config->setProgressiveMode($progressiveMode ?: Configuration::PROGRESSIVE_MODE_ID);
        $config->setElasticMode($elasticMode ?: Configuration::ELASTIC_MODE_NONE);
        $config->setSubscriberMode($subscriberMode ?: Configuration::SUBSCRIBER_MODE_NONE);

        return $config;
    }

    public static function initialize(Configuration $config, array $importers)
    {
        $iKeys = $config->importerKeys;
        $sKeys = $config->segmentKeys;

        foreach ($importers as $importer) {
            $config->addImporter($importer);
        }
        if (empty($iKeys) && empty($sKeys)) {
            return;
        }

        foreach ($config->getImporters(true) as $importer) {
            $importer->disable();
            $key = $importer->getKey();
            if (array_key_exists($key, $iKeys) && true === $iKeys[$key]) {
                $importer->enable();
            }
        }

        foreach ($config->getSegments(true) as $segment) {
            $segment->disable();
            $key = $segment->getKey();
            if (array_key_exists($key, $sKeys) && true === $sKeys[$key]) {
                $segment->enable();
            }
        }

        $config->importerKeys = $iKeys;
        $config->segmentKeys = $sKeys;
    }

    public static function load($filename)
    {
        $config = unserialize(file_get_contents($filename));
        if (!$config instanceof Configuration) {
            throw new \Exception('Could not load file '.$filename);
        }
        return $config;
    }

    public static function save(Configuration $config, $path)
    {
        if (null === $filename = $config->getFilename()) {
            $filename = tempnam($path, sprintf('as3import_%s_', $config->getContextKey()));
            $filename = str_replace($path, '', $filename);
            $filename = str_replace('/private/', '', $filename);
            $config->setFilename($filename);
        }
        $config->touch();
        $filename = sprintf('%s/%s', $path, $config->getFilename());
        file_put_contents($filename, serialize($config));
        return static::load($filename);
    }
}
