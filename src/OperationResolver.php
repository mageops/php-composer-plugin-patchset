<?php

namespace Creativestyle\Composer\Patchset;

use Composer\DependencyResolver\Operation\InstallOperation;
use Composer\DependencyResolver\Operation\UninstallOperation;
use Composer\DependencyResolver\Operation\UpdateOperation;

use Composer\Installer\NoopInstaller;
use Composer\Repository\InstalledArrayRepository;
use Composer\Repository\RepositoryInterface;

class OperationResolver
{
    /**
     * Returns a repository that reflects the state after operations have been executed on the current one.
     *
     * @param RepositoryInterface $repository
     * @param array $operations
     * @return RepositoryInterface
     */
    public function resolveState(RepositoryInterface $repository, array $operations)
    {
        $packages = array_map(function($p) { return clone $p; }, $repository->getPackages());
        $installed = new InstalledArrayRepository($packages);
        $installer = new NoopInstaller();

        foreach ($operations as $operation) {
            if ($operation instanceof InstallOperation) {
                $installer->install($installed, $operation->getPackage());
            } elseif ($operation instanceof UpdateOperation) {
                $installer->update($installed, $operation->getInitialPackage(), $operation->getTargetPackage());
            } elseif ($operation instanceof UninstallOperation) {
                $installer->uninstall($installed, $operation->getPackage());
            }
        }

        return $installed;
    }
}