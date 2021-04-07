<?php

namespace Creativestyle\Composer\Patchset;

use Composer\Installer\InstallationManager;
use Composer\Package\AliasPackage;
use Composer\Package\PackageInterface;
use Composer\Repository\RepositoryInterface;
use Composer\Package\Dumper\ArrayDumper;
use Composer\Package\Loader\ArrayLoader;
use Composer\Json\JsonFile;
use Composer\Semver\Semver;
use Psr\Log\LoggerInterface;

class PackageApplicationRepository
{
    /**
     * @var RepositoryInterface
     */
    private $installedRepository;

    /**
     * @var InstallationManager
     */
    private $installationManager;

    /**
     * @var PathResolver
     */
    private $pathResolver;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var ArrayDumper
     */
    private $packageDumper;

    /**
     * @var ArrayLoader
     */
    private $packageLoader;

    /**
     * @param RepositoryInterface $installedRepository
     * @param InstallationManager $installationManager
     * @param PathResolver $pathResolver
     * @param LoggerInterface $logger
     */
    public function __construct(
        RepositoryInterface $installedRepository,
        InstallationManager $installationManager,
        PathResolver $pathResolver,
        LoggerInterface $logger
    ) {
        $this->installedRepository = $installedRepository;
        $this->installationManager = $installationManager;
        $this->pathResolver = $pathResolver;
        $this->logger = $logger;

        $this->packageDumper = new ArrayDumper();
        $this->packageLoader = new ArrayLoader();
    }

    /**
     * @return PackagePatchApplication[]
     */
    public function getPackageApplications()
    {
        $applications = [];

        foreach ($this->installedRepository->getPackages() as $package) {
            if ($package instanceof AliasPackage) {
                continue;
            }

            if (null !== $application = $this->getPackageApplication($package)) {
                $applications[$package->getName()] = $application;
            }
        }

        return $applications;
    }

    /**
     * @param PackageInterface $targetPackage
     * @return PackagePatchApplication
     */
    public function getPackageApplication(PackageInterface $targetPackage)
    {
        $dataFile = $this->pathResolver->getPackageApplicationFilename($targetPackage);

        if (!file_exists($dataFile)) {
            return null;
        }

        if (!is_readable($dataFile)) {
            throw new \RuntimeException('Cannot Loaded applied patches data file "%s"', $dataFile);
        }

        $data = json_decode(file_get_contents($dataFile), true);

        return $this->createPackagePatchApplication($targetPackage, $data);
    }

    /**
     * @param PackagePatchApplication $packagePatchApplication
     */
    public function savePackageApplication(PackagePatchApplication $packagePatchApplication)
    {
        $targetPackage = $packagePatchApplication->getTargetPackage();
        $dataFile = $this->pathResolver->getPackageApplicationFilename($targetPackage);

        if (file_exists($dataFile)) {
            if (!is_writable($dataFile)) {
                throw new \RuntimeException(sprintf('Cannot write applied patches data file "%s"', $dataFile));
            }
        } elseif (!is_writable(dirname($dataFile))) {
            throw new \RuntimeException(sprintf('Package directory is not writable "%s"', dirname($dataFile)));
        }

        file_put_contents($dataFile,
            $this->encodeData($this->transformPackagePatchApplicationToArray($packagePatchApplication))
        );
    }

    /**
     * @param array $data
     * @return string
     */
    private function encodeData(array $data)
    {
        return json_encode($data,
            JSON_PRETTY_PRINT + JSON_UNESCAPED_SLASHES + JSON_UNESCAPED_UNICODE
        );
    }

    /**
     * @param PackageInterface $targetPackage
     * @param array $data
     * @return PackagePatchApplication
     */
    private function createPackagePatchApplication($targetPackage, array $data)
    {
        return new PackagePatchApplication($targetPackage, array_map(
            function($patchData) use ($targetPackage) {
                return $this->createPatchApplication($targetPackage, $patchData);
            }, 
            $data['patches']
        ));
    }

    /**
     * @param PackageInterface|null $loadedPackage
     * @param string $versionConstraint
     * @return PackageInterface|null
     */
    private function matchLoadedPackageToInstalled($loadedPackage, $versionConstraint = null)
    {
        if ($package = $this->installedRepository->findPackage($loadedPackage->getName(), $loadedPackage->getVersion())) {
            return $package;
        }
        
        if ($loadedPackage->getPrettyVersion() 
            && $package = $this->installedRepository->findPackage($loadedPackage->getName(), $loadedPackage->getPrettyVersion())) {
            return $package;
        }

        if ($loadedPackage->getFullPrettyVersion()
            && $package = $this->installedRepository->findPackage($loadedPackage->getName(), $loadedPackage->getFullPrettyVersion())) {
            return $package;
        }

        if ($versionConstraint && $package = $this->installedRepository->findPackage($loadedPackage->getName(), $versionConstraint)) {
            return $package;
        }

        return null;
    }

    /**
     * @param PackageInterface $targetPackageInstalled
     * @param PackageInterface $targetPackageLoaded
     * @param Patch $patchLoaded
     * @return PackageInterface|null
     */
    private function matchTargetLoadedPackageToInstalled($targetPackageInstalled, $targetPackageLoaded, $patchLoaded)
    {
        if ($targetPackageInstalled->getName() === $targetPackageLoaded->getName()) {
            if ($targetPackageInstalled->getVersion() === $targetPackageLoaded->getVersion()) {
                return $targetPackageInstalled;
            }

            if ($targetPackageInstalled->getPrettyVersion() === $targetPackageLoaded->getPrettyVersion()) {
                return $targetPackageInstalled;
            }

            if ($patchLoaded->getVersionConstraint() && Semver::satisfies($targetPackageInstalled->getVersion(), $patchLoaded->getVersionConstraint())) {
                return $targetPackageInstalled;
            }

            $this->logger->warning(sprintf(
                'Could not find find installed package (%s) matching version (%s) loaded loaded from applied patch.' .
                'Name and location checks out, but it might indicate a potential problem. Continuing...',
                $targetPackageInstalled->getPrettyName(),
                $targetPackageLoaded->getPrettyName()
            ));
        }

        return $this->matchLoadedPackageToInstalled($targetPackageLoaded, $patchLoaded->getVersionConstraint());
    }

    /**
     * @param PackageInterface $targetPackage
     * @param array $data
     * @return PatchApplication
     */
    private function createPatchApplication($targetPackage, array $data)
    {
        $patch = Patch::createFromArray($data['patch']);

        $sourcePackageLoaded = $this->loadPackageFromArray($data['source_package']);
        $targetPackageLoaded = $this->loadPackageFromArray($data['target_package']);

        if (!$sourcePackageInstalled = $this->matchLoadedPackageToInstalled($sourcePackageLoaded)) {
            $this->logger->debug(sprintf(
                'Could not find source package %s for installed patch, it was removed probably',
                $sourcePackageLoaded->getPrettyName()
            ));
        }


        if (!$targetPackageInstalled = $this->matchTargetLoadedPackageToInstalled($targetPackage, $targetPackageLoaded, $patch)) {
            throw new \LogicException(sprintf(
                'Could not find target package %s for installed patch. This should not happen.',
                $targetPackageLoaded->getPrettyName()
            ));
        }

        return new PatchApplication($patch, $sourcePackageInstalled, $targetPackageInstalled, $data['hash']);
    }

    /**
     * @param PackagePatchApplication $packageApplication
     * @return array
     */
    public function transformPackagePatchApplicationToArray(PackagePatchApplication $packageApplication)
    {
        return [
            'hash' => $packageApplication->getHash(),
            'patches' => array_map(
                [$this, 'transformPatchApplicationToArray'],
                $packageApplication->getApplications()
            )
        ];
    }

    /**
     * @param PackageInterface $package
     * @return array
     */
    public function dumpPackageToArray($package) 
    {
        return $this->packageDumper->dump($package);
    }

    /**
     * @param array $packageData
     * @return PackageInterface
     */
    public function loadPackageFromArray($packageData) 
    {
        return $this->packageLoader->load($packageData);
    }

    /**
     * @param PatchApplication $application
     * @return array
     */
    public function transformPatchApplicationToArray(PatchApplication $application)
    {
        return [
            'hash' => $application->getHash(),
            'target_package' => $this->dumpPackageToArray($application->getTargetPackage()),
            'source_package' => $this->dumpPackageToArray($application->getSourcePackage()),
            'patch' => $application->getPatch()->toArray()
        ];
    }
}