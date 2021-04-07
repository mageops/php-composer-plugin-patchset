<?php

namespace Creativestyle\Composer\Patchset;

use Composer\Installer\InstallationManager;
use Composer\Package\PackageInterface;
use Composer\Package\RootPackageInterface;

class PathResolver
{
    const PATCH_APPLICATION_DATA_FILENAME = 'patches-applied.json';
    const PATCH_APPLICATION_DATA_LEGACY_FILENAME = 'composer.patches_applied.json';

    /**
     * @var InstallationManager
     */
    private $installationManager;

    public function __construct(
        InstallationManager $installationManager
    ) {
        $this->installationManager = $installationManager;
    }

    /**
     * @param PackageInterface $package
     * @return string
     */
    public function getPackageInstallPath(PackageInterface $package)
    {
        if ($package instanceof RootPackageInterface) {
            // This is not an ideal solution but should work for now.
            // I haven't found an easy way to get this information from composer itself.
            return getcwd();
        }

        $installer = $this->installationManager->getInstaller($package->getType());

        return $installer->getInstallPath($package);
    }

    /**
     * @param PackageInterface $sourcePackage
     * @param Patch $patch
     * @return string
     */
    public function getPatchSourceFilePath(PackageInterface $sourcePackage, Patch $patch)
    {
        return rtrim($this->getPackageInstallPath($sourcePackage), '/') . '/' . ltrim($patch->getFilename(), '/');
    }

    /**
     * @param PackageInterface $targetPackage
     * @return string
     */
    public function getPackageApplicationFilename(PackageInterface $targetPackage)
    {
        return rtrim($this->getPackageInstallPath($targetPackage), '/') . '/' . static::PATCH_APPLICATION_DATA_FILENAME;
    }
}