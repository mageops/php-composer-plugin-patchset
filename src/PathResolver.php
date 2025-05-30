<?php

namespace Creativestyle\Composer\Patchset;

use Composer\Installer\InstallationManager;
use Composer\Package\PackageInterface;
use Composer\Package\RootPackageInterface;

class PathResolver
{
    public const PATCH_APPLICATION_DATA_FILENAME = 'composer.patches_applied.json';

    public function __construct(
        private InstallationManager $installationManager
    ) {}

    public function getPackageInstallPath(PackageInterface $package): ?string
    {
        if ($package instanceof RootPackageInterface) {
            // This is not an ideal solution but should work for now.
            // I haven't found an easy way to get this information from composer itself.
            return getcwd();
        }

        $installer = $this->installationManager->getInstaller($package->getType());

        return $installer->getInstallPath($package);
    }

    public function getPatchSourceFilePath(PackageInterface $sourcePackage, Patch $patch): string
    {
        return rtrim($this->getPackageInstallPath($sourcePackage) ?? '', '/') . '/' . ltrim($patch->getFilename(), '/');
    }

    public function getPackageApplicationFilename(PackageInterface $targetPackage): string
    {
        return rtrim($this->getPackageInstallPath($targetPackage) ?? '', '/') . '/' . static::PATCH_APPLICATION_DATA_FILENAME;
    }
}
