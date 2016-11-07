<?php

namespace As3\SymfonyData\Import;

use Symfony\Component\Filesystem\Filesystem;

class Manager
{
    /**
     * @var Configuration
     */
    private $configuration;

    /**
     * @var string
     */
    private $storagePath;

    /**
     * @var ImporterInterface[]
     */
    private $importers = [];

    /**
     * The contextual key used to determine if an importer can run in this environment
     * @var string
     */
    private $contextKey = 'default';

    /**
     * DI Constructor
     *
     * @param   string              $contextKey     The context key
     * @param   string              $storagePath   Directory prefix for stored configs
     * @param   Configuration       $config         The active import configuration
     */
    public function __construct($contextKey = 'default', $storagePath = null, Configuration $config = null)
    {
        if (null === $storagePath) {
            $storagePath = sprintf('%s/as3import', sys_get_temp_dir());
        }
        $this->storagePath = $storagePath;
        $this->contextKey = $contextKey;
        $this->configuration = $config ?: $this->create();

        $filesystem = new Filesystem();
        if (!$filesystem->exists($this->storagePath)) {
            $filesystem->mkdir($this->storagePath);
        }
    }

    public function create()
    {
        $this->configuration = ConfigurationFactory::create($this->contextKey);
        ConfigurationFactory::initialize($this->configuration, $this->importers);
        return $this->configuration;
    }

    public function load($filename)
    {
        $path = sprintf('%s/%s', $this->storagePath, $filename);
        $this->configuration = ConfigurationFactory::load($path);
        ConfigurationFactory::initialize($this->configuration, $this->importers);
        return $this->configuration;
    }

    public function save()
    {
        $this->configuration = ConfigurationFactory::save($this->configuration, $this->storagePath);
        return $this->configuration;
    }

    public function delete(Configuration $config)
    {
        if (null !== $config->getFilename()) {
            unlink(sprintf('%s/%s', $this->storagePath, $config->getFilename()));
        }
    }

    public function all()
    {
        $configs = [];
        $files = array_diff(scandir($this->storagePath), ['..', '.']);
        foreach($files as $k => $filename) {
            $file = sprintf('%s/%s', $this->storagePath, $filename);
            $configs[] = ConfigurationFactory::load($file);
        }
        return $configs;
    }

    /**
     * DI Injection
     *
     * @param   ImporterInterface   $importer
     */
    public function addImporter(ImporterInterface $importer)
    {
        if ($importer->supports($this->configuration)) {
            $this->importers[] = $importer;
            $this->configuration->addImporter($importer);
        }
    }

    /**
     * Returns the active Configuration
     *
     * @return  Configuration
     */
    public function getConfiguration()
    {
        return $this->configuration;
    }

    /**
     * Returns importers keyed by their internal segment key.
     *
     * @param   bool    $all    If all phases should be returned, regardless of status.
     * @return  array
     */
    public function getImportersForContext($all = false)
    {
        $importers = [];
        foreach ($this->configuration->getImporters($all) as $key => $importer) {
            if ($importer->supports($this->configuration)) {
                $importers[] = $importer;
            }
        }
        return $importers;
    }

    /**
     * Executes global startup tasks
     */
    public function setUp()
    {
        $this->persisterMethod('updateSchema');

        foreach ($this->getImportersForContext() as $importer) {
            $importer->postUpdateSchema();
        }
    }

    /**
     * Executes the supplied method on each unique enabled persister.
     *
     * @param   string  $method     The persister method to execute
     */
    private function persisterMethod($method)
    {
        $processed = [];
        foreach ($this->getImportersForContext() as $importer) {
            $persister = $importer->getPersister();
            foreach ($processed as $class) {
                if ($persister instanceof $class) {
                    continue 2;
                }
            }
            $importer->getPersister()->$method();
            $processed[] = get_class($persister);
        }
    }
}
