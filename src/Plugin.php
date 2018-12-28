<?php

namespace Creativestyle\Composer\Patchset;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\ScriptEvents;
use Composer\Script\Event as ScriptEvent;

use Composer\Util\ProcessExecutor;
use Psr\Log\LoggerInterface;

class Plugin implements PluginInterface, EventSubscriberInterface
{
    /**
     * @var IOInterface
     */
    private $io;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * {@inheritdoc}
     */
    public function activate(Composer $composer, IOInterface $io)
    {
        $this->io = $io;
        $this->logger = new IOLogger($io);
    }

    /**
     * {@inheritdoc}
     */
    public static function getSubscribedEvents()
    {
        return [
            ScriptEvents::PRE_AUTOLOAD_DUMP => 'onPreAutoloadDump'
        ];
    }

    /**
     * @param ScriptEvent $event
     */
    public function onPreAutoloadDump(ScriptEvent $event)
    {
        // Execute patching before autoload is dumped because it may
        // change after patching under some circumstances...
        $this->applyPatches($event->getComposer());
    }

    /**
     * @param Composer $composer
     */
    public function applyPatches(Composer $composer)
    {
        $patcher = new Patcher(
            $this->logger,
            $composer->getInstallationManager(),
            $composer->getRepositoryManager(),
            new ProcessExecutor($this->io)
        );

        $patcher->patch();
    }
}