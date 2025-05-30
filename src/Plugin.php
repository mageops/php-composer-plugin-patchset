<?php

namespace Creativestyle\Composer\Patchset;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use Composer\Plugin\PluginInterface;
use Composer\Repository\ArrayRepository;
use Composer\Script\ScriptEvents;
use Composer\Script\Event as ScriptEvent;

use Composer\Util\ProcessExecutor;
use Psr\Log\LoggerInterface;

class Plugin implements PluginInterface, EventSubscriberInterface
{
    private IOInterface $io;
    private LoggerInterface $logger;

    public function activate(Composer $composer, IOInterface $io): void
    {
        $this->io = $io;
        $this->logger = new IOLogger($io);
    }

    public function deactivate(Composer $composer, IOInterface $io)
    {
    }

    public function uninstall(Composer $composer, IOInterface $io)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ScriptEvents::PRE_AUTOLOAD_DUMP => 'onPreAutoloadDump'
        ];
    }

    public function onPreAutoloadDump(ScriptEvent $event): void
    {
        // Execute patching before autoload is dumped because it may
        // change after patching under some circumstances...
        $this->applyPatches($event->getComposer());
    }

    public function applyPatches(Composer $composer): void
    {
        $patcher = new Patcher(
            $this->logger,
            $composer->getInstallationManager(),
            $composer->getRepositoryManager(),
            new ProcessExecutor($this->io),
            $composer->getPackage()
        );

        $patcher->patch();
    }
}
