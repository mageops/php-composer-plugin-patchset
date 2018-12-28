<?php

namespace Creativestyle\Composer\Patchset;

use Composer\Installer\InstallationManager;
use Composer\Package\PackageInterface;

class PathResolver
{
    const PATCH_APPLICATION_DATA_FILENAME = 'composer.patches_applied.json';

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