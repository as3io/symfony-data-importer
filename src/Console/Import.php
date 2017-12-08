<?php

namespace As3\SymfonyData\Console;

use Symfony\Component\Console\Input\InputOption;
use As3\SymfonyData\Import\Manager as ImportManager;
use As3\SymfonyData\Import\ImporterInterface;
use As3\SymfonyData\Import\LoopableInterface;
use As3\SymfonyData\Import\SegmentInterface;
use As3\SymfonyData\Import\Configuration;

/**
 * Imports data using the ImportManager service
 *
 * Configures the import manager and provides status output
 * over the course of the import process.
 *
 * @author  Josh Worden <jworden@southcomm.com>
 * @since   2016-02-23
 */
final class Import extends Command
{
    /**
     * The ImportManager service
     * @var ImportManager
     */
    protected $importManager;

    /**
     * Class constructor. This must be called in extending classes and must call parent::__construct()
     *
     * @param   ImportManager   $importManager
     */
    public function __construct($name, ImportManager $importManager)
    {
        $this->importManager = $importManager;
        parent::__construct($name);
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        parent::configure();
        $this
            // ->setName('radix:import')
            ->setDescription('Imports customers.')
            ->addOption('assume', null, InputOption::VALUE_NONE, 'Assume yes and start ASAP.')
        ;
    }

    /**
     *
     */
    final protected function doCommandConfiguration()
    {
        if ($this->input->getOption('assume')) {
            $configs = $this->importManager->all();
            $config = reset($configs);
            if (null !== $config) {
                $this->importManager->load($config->getFilename());
                return;
            }
        }

        while ('' !== $key = $this->listConfigurations()) {
            if ('new' === $key) {
                $config = $this->importManager->create();
                $this->modifyConfiguration($config);
            } else {
                $configs = $this->importManager->all();
                $configs = array_values($configs);
                $config = $configs[$key];

                if ($this->confirm('<error>Delete configuration?</error>', false)) {
                    $this->importManager->delete($config);
                    return $this->doCommandConfiguration();
                }

                $this->importManager->load($config->getFilename());
                if ($this->confirm('<question>Modify loaded configuration?</question>', false)) {
                    $this->modifyConfiguration($config);
                }
                break;
            }
        }

        while (true !== $this->confirm('<comment>Begin import?</comment>', false)) {
            $this->writeln('<error>Enter yes to start or Ctrl+C to exit.</error>');
        }
    }

    protected function listConfigurations()
    {
        $configs = $this->importManager->all();

        $question = '<question>Load Configuration:</question>';
        $items = [];
        foreach ($configs as $config) {
            $enabledImporters = $config->getImporterKeys();
            $enabledSegments = $config->getSegmentKeys();
            $dataMode = 'not set';
            switch ($config->getDataMode()) {
                case 'wipe':
                    $dataMode = '<error>wipe</error>';
                    break;
                case 'overwrite':
                    $dataMode = '<comment>overwrite</comment>';
                    break;
                case 'progressive':
                    $dataMode = '<info>progressive</info>';
                    break;
            }
            $date = 'N/A';
            if ($config->getModified() instanceof \DateTime) {
                $date = $config->getModified()->format('c');
            }
            $items[] = sprintf(
                "%s Created <info>%s</info>\n        Data Mode: %s\n        Importers: %s\n        Segments: %s",
                $config->getFilename(),
                $date,
                $dataMode,
                sprintf('<info>%s</info>', implode('</info>, <info>', $enabledImporters)),
                sprintf('<info>%s</info>', implode('</info>, <info>', $enabledSegments))
                );
        }
        $items['new'] = sprintf('<comment>Create new Configuration</comment>');
        return $this->askList($question, $items);
    }

    protected function modifyConfiguration($config)
    {
        $default = $this->importManager->getConfiguration()->getDataMode();
        $mode = $this->askInput('Data Mode [<comment>progressive</comment>|<comment>overwrite</comment>|<comment>wipe</comment>]', $default);
        if ($mode !== $default) {
            $this->importManager->getConfiguration()->setDataMode($mode);
        }

        if ('progressive' === $this->importManager->getConfiguration()->getDataMode()) {
            $default = $this->importManager->getConfiguration()->getProgressiveMode();
            $mode = $this->askInput('Progressive Data Mode [<comment>id</comment>|<comment>date</comment>]', $default);
            if ($mode !== $default) {
                $this->importManager->getConfiguration()->setProgressiveMode($mode);
            }
        }

        $default = $this->importManager->getConfiguration()->getSchemaMode();
        $mode = $this->askInput('Schema Mode [<comment>none</comment>|<comment>create</comment>|<comment>update</comment>]', $default);
        if ($mode !== $default) {
            $this->importManager->getConfiguration()->setSchemaMode($mode);
        }

        $default = $this->importManager->getConfiguration()->getElasticMode();
        $mode = $this->askInput('Elastic Mode [<comment>full</comment>|<comment>none</comment>]', $default);
        if ($mode !== $default) {
            $this->importManager->getConfiguration()->setElasticMode($mode);
        }

        $default = $this->importManager->getConfiguration()->getSubscriberMode();
        $mode = $this->askInput('Subscriber Mode [<comment>full</comment>|<comment>none</comment>]', $default);
        if ($mode !== $default) {
            $this->importManager->getConfiguration()->setSubscriberMode($mode);
        }


        $this->configureImporters();
        $this->configureSegments();

        if ($this->confirm('<question>Save Configuration?</question>', false)) {
            $this->importManager->save();
        }
    }

    private function listImporters()
    {
        $question = '<question>Enter an importer key to toggle inclusion.</question>';
        $items = ['*' => '<comment>Toggle all</comment>'];
        foreach ($this->importManager->getImportersForContext(true) as $key => $importer) {
            $tag = $importer->isEnabled() ? 'info' : 'error';
            $status = $importer->isEnabled() ? 'ENABLED ' : 'DISABLED';
            $items[] = sprintf('<%s>%s</%s> %s', $tag, $status, $tag, $importer->getKey());
        }
        $items[''] = sprintf('<comment>ENTER to continue</comment>');
        return $this->askList($question, $items);
    }

    private function configureImporters()
    {
        $this->writeln('Configure <info>Importers</info>', true, true);
        $config = $this->importManager->getConfiguration();

        while ('' !== $key = $this->listImporters()) {
            $segmentKeys = [];
            foreach ($this->importManager->getImportersForContext(true) as $index => $importer) {
                if ($key == $index || '*' === $key) {
                    $config->toggleImporter($importer->getKey());
                }
            }
        }

        $keys = [];
        foreach ($config->getImporters() as $importer) {
            $keys[] = $importer->getKey();
        }

        $this->writeln(sprintf('The following importers will be used: <info>%s</info>', implode(', ', $keys)), true, true);
    }

    private function listSegments()
    {
        $question = '<question>Enter a segment to toggle inclusion.</question>';
        $items = ['*' => '<comment>Toggle all</comment>'];
        foreach ($this->importManager->getConfiguration()->getSegments(true) as $segment) {
            $tag = $segment->isEnabled() ? '<info>ENABLED </info>' : '<error>DISABLED</error>';
            $items[] = sprintf('%s %s', $tag, $segment->getKey());
        }
        $items[''] = sprintf('<comment>ENTER to continue</comment>');
        return $this->askList($question, $items);
    }

    private function configureSegments()
    {
        $this->writeln('Configure <info>Segments</info>', true, true);
        $config = $this->importManager->getConfiguration();

        while ('' !== $key = $this->listSegments()) {
            $segmentKeys = [];
            foreach ($config->getSegments(true) as $index => $segment) {
                if ($key == $index || '*' === $key) {
                    $segment->toggle();
                }
            }
        }

        $keys = [];
        foreach ($config->getSegments() as $segment) {
            $keys[] = $segment->getKey();
        }

        $this->writeln(sprintf('The following segments will be used: <info>%s</info>', implode(', ', $keys)), true, true);
    }

    /**
     * Performs startup tasks
     */
    final protected function doCommandImportSetUp()
    {
        $this->writeln('Executing <info>setup</info> tasks', true);

        // $this->updateSchema();
        $this->importManager->setUp();

        $this->writeln('Startup tasks complete.', true, true);
    }

    /**
     * The main import loop
     */
    final protected function doCommandImport()
    {
        $this->writeln('Starting Import', true, true);
        $this->indent();

        foreach ($this->importManager->getConfiguration()->getSegments() as $segment) {
            $this->importSegment($segment);
        }

        $this->outdent();
        $this->writeln('<info>Import complete!</info>', true, true);
    }

    /**
     * Performs teardown tasks
     */
    final protected function doCommandImportTearDown()
    {
        $this->writeln('Executing <info>teardown</info> tasks', false, true);
        $this->indent();

        $this->subscriberPass();

        $this->outdent();
        $this->writeln('Teardown tasks complete.', false, true);
    }

    /**
     *
     */
    private function importSegment(SegmentInterface $segment)
    {
        $this->writeln(sprintf('Started  segment <info>%s</info>!', $segment->getKey()));
        $this->indent();

        $counter = function() use($segment) {
            return $segment->count();
        };

        $retriever = function($limit, $skip) use($segment) {
            return $segment->modify($limit, $skip);
        };

        $modifier = function($item) {
            return $item;
        };

        $persister = function($items) use($segment) {
            return $segment->persist($items);
        };

        $this->loop($counter, $retriever, $modifier, $persister, null, $segment->getLimit());

        $this->outdent();
        $this->writeln(sprintf('Finished segment <info>%s</info>!', $segment->getKey()));
    }

    private function getPersisters()
    {
        $persisters = $processed = [];
        foreach ($this->importManager->getImportersForContext() as $importer) {
            $persister = $importer->getPersister();
            foreach ($processed as $class) {
                if ($persister instanceof $class) {
                    continue 2;
                }
            }
            $persisters[] = $persister;
            $processed[] = get_class($persister);
        }
        return $persisters;
    }

    private function updateSchema()
    {
        $this->writeln('Executing <comment>schema updates</comment>', false, true);
        $this->indent();

        if (Configuration::SCHEMA_MODE_NONE !== $this->importManager->getConfiguration()->getSubscriberMode()) {
            foreach ($this->getPersisters() as $persister) {
                $looper = $persister->getSchemaLooper();
                if ($looper instanceof LoopableInterface) {
                    $this->loop(
                        [$looper, 'count'],
                        [$looper, 'retrieve'],
                        [$looper, 'modify'],
                        [$looper, 'persist'],
                        $type
                    );
                }
            }
        }

        $this->outdent();
        $this->writeln('Completed <comment>schema updates</comment>');
    }

    private function subscriberPass()
    {
        $this->writeln('Executing <comment>subscriber passes</comment>', false, true);
        $this->indent();

        if (Configuration::SUBSCRIBER_MODE_FULL === $this->importManager->getConfiguration()->getSubscriberMode()) {
            foreach ($this->getPersisters() as $persister) {
                foreach ($persister->getModelTypes() as $type) {
                    $looper = $persister->getSubscriberLooper($type);
                    if ($looper instanceof LoopableInterface) {
                        $this->loop(
                            [$looper, 'count'],
                            [$looper, 'retrieve'],
                            [$looper, 'modify'],
                            [$looper, 'persist'],
                            $type
                        );
                    }
                }
            }
        }

        $this->outdent();
        $this->writeln('Completed <comment>subscriber passes</comment>');
    }
}
