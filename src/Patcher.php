<?php

namespace Creativestyle\Composer\Patchset;

use Composer\DependencyResolver\Operation\InstallOperation;
use Composer\DependencyResolver\Operation\UninstallOperation;
use Composer\Installer\InstallationManager;
use Composer\Package\PackageInterface;

use Composer\Package\RootPackageInterface;
use Composer\Repository\ArrayRepository;
use Composer\Repository\RepositoryManager;
use Composer\Util\ProcessExecutor;
use Psr\Log\LoggerInterface;

class Patcher
{
    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var Patch[]
     */
    private $patches;

    /**
     * @var PatchCollector
     */
    private $collector;

    /**
     * @var OperationResolver
     */
    private $operationResolver;

    /**
     * @var PatchApplicator
     */
    private $applicator;

    /**
     * @var InstallationManager
     */
    private $installationManager;

    /**
     * @var RepositoryManager
     */
    private $repositoryManager;

    /**
     * @var PackagePatchApplication[]
     */
    private $targetPackageApplications;

    /**
     * @var PackagePatchApplication[]
     */
    private $installedPackageApplications;

    /**
     * @var PathResolver
     */
    private $pathResolver;

    /**
     * @var PackageApplicationRepository
     */
    private $packageApplicationRepository;

    /**
     * @var PackageInterface[]
     */
    private $packagesToReinstall;

    /**
     * @var PackageInterface[]
     */
    private $packagesToPatch;

    /**
     * @var ProcessExecutor
     */
    private $processExecutor;

    /**
     * @var ArrayRepository
     */
    private $installedRepository;

    /**
     * @var RootPackageInterface
     */
    private $rootPackage;

    /**
     * The constructor computes state, so it needs to be called only after all packages have been installed
     * and the local composer repository updated.
     *
     * @param LoggerInterface $logger
     * @param InstallationManager $installationManager
     * @param RepositoryManager $repositoryManager
     * @param ProcessExecutor $processExecutor
     * @param RootPackageInterface $rootPackage
     */
    public function __construct(
        LoggerInterface $logger,
        InstallationManager $installationManager,
        RepositoryManager $repositoryManager,
        ProcessExecutor $processExecutor,
        RootPackageInterface $rootPackage
    ) {
        $this->logger = $logger;
        $this->rootPackage = $rootPackage;
        $this->collector = new PatchCollector($this->logger);
        $this->operationResolver = new OperationResolver();
        $this->installationManager = $installationManager;
        $this->repositoryManager = $repositoryManager;
        $this->pathResolver = new PathResolver($installationManager);
        $this->processExecutor = $processExecutor;
        $this->installedRepository = $this->buildInstalledRepository();

        $this->applicator = new PatchApplicator(
            $this->logger,
            $this->installationManager,
            $this->pathResolver,
            $this->processExecutor
        );

        $this->packageApplicationRepository = new PackageApplicationRepository(
            $this->installedRepository,
            $this->installationManager,
            $this->pathResolver,
            $this->logger
        );

        $this->patches = $this->collectPatches();
        $this->targetPackageApplications = $this->computeTargetPackageApplications();
        $this->installedPackageApplications = $this->packageApplicationRepository->getPackageApplications();

        list($this->packagesToReinstall, $this->packagesToPatch) = $this->computeChanges();

    }

    /**
     * @return ArrayRepository
     */
    private function buildInstalledRepository()
    {
        $repo = new ArrayRepository(array_map(function(PackageInterface $package) {
            return clone $package;
        }, $this->repositoryManager->getLocalRepository()->getCanonicalPackages()));

        $rootPackage = clone $this->rootPackage;

        $repo->addPackage($rootPackage);

        return $repo;
    }

    /**
     * @return Patch[]
     */
    private function collectPatches()
    {
        return $this->collector->collectFromRepository(
           $this->installedRepository
        );
    }

    /**
     * @return PackagePatchApplication[]
     */
    private function computeTargetPackageApplications()
    {
        $packageApplications = [];
        $repo = $this->installedRepository;

        $patchesByPackage = [];

        foreach ($this->patches as $patch) {
            $targetPackageName = $patch->getTargetPackage();

            if (!isset($patchesByPackage)) {
                $patchesByPackage = [];
            }

            $patchesByPackage[$targetPackageName][] = $patch;
        }

        foreach ($patchesByPackage as $targetPackageName => $packagePatches) {
            /** @var PatchApplication[] $applications */
            $applications = [];

            $targetPackage = $repo->findPackage($targetPackageName, '*');

            if (null === $targetPackage) {
                // No package to patch, nothing to do
                continue;
            }

            /** @var Patch $patch */
            foreach ($packagePatches as $patch) {
                if ($patch->canBeAppliedTo($targetPackage)) {
                    $sourcePackage = $repo->findPackage($patch->getSourcePackage(), '*');

                    $applicationHash = $this->computeApplicationHash($sourcePackage, $patch);

                    if (isset($applications[$applicationHash])) {
                        $this->logger->notice(sprintf('Skipping patch <info>%s</info> (<comment>%s</comment> as it was already added by package <comment>%s</comment>',
                            $patch->getDescription(),
                            $patch->getSourcePackage(),
                            $applications[$applicationHash]->getSourcePackage()->getName()
                        ));
                    }

                    $applications[$applicationHash] = new PatchApplication(
                        $patch,
                        $sourcePackage,
                        $targetPackage,
                        $applicationHash
                    );
                }
            }

            $applications = array_values($applications);

            $packageApplications[$targetPackage->getName()] = new PackagePatchApplication($targetPackage, $applications);
        }

        return $packageApplications;
    }

    /**
     * @param PackageInterface $sourcePackage
     * @param Patch $patch
     * @return string
     */
    private function computeApplicationHash(PackageInterface $sourcePackage, Patch $patch)
    {
        $sourcePath = $this->pathResolver->getPatchSourceFilePath($sourcePackage, $patch);

        if (!is_readable($sourcePath)) {
            throw new \RuntimeException(sprintf('Patch source file "%s" does not exist', $sourcePath));
        }

        return sha1_file($sourcePath);
    }

    private function reinstallPackages()
    {
        $localRepo = $this->repositoryManager->getLocalRepository();

        foreach ($this->packagesToReinstall as $package) {
            if ($package instanceof RootPackageInterface) {
                $this->logger->warning(sprintf('Root package patches have changed but cannot reinstall it, will apply only new patches. You should reinstall the whole project to be safe.',
                    $package->getName(),
                    $package->getPrettyVersion()
                ));

                continue;
            }

            $this->logger->notice(sprintf('Reinstalling <info>%s</info> (<comment>%s</comment>) for re-patch',
                $package->getName(),
                $package->getPrettyVersion()
            ));

            $this->installationManager->uninstall($localRepo, new UninstallOperation($package));
            $this->installationManager->install($localRepo, new InstallOperation($package));
        }
    }

    private function applyPatches()
    {
        foreach ($this->targetPackageApplications as $packagePatchApplication) {
            if (!array_key_exists($packagePatchApplication->getTargetPackage()->getName(), $this->packagesToPatch)) {
                $this->logger->debug(sprintf('Not patching <info>%s</info> (<comment>%s</comment>) as it is up-to-date',
                    $packagePatchApplication->getTargetPackage()->getName(),
                    $packagePatchApplication->getTargetPackage()->getPrettyVersion()
                ));

                continue;
            }

            $this->logger->notice(sprintf('Applying patches to <info>%s</info> (<comment>%s</comment>)',
                $packagePatchApplication->getTargetPackage()->getName(),
                $packagePatchApplication->getTargetPackage()->getPrettyVersion()
            ));

            foreach ($packagePatchApplication->getApplications() as $patchApplication) {
                $this->applicator->applyPatch(
                    $patchApplication->getPatch(),
                    $patchApplication->getSourcePackage(),
                    $patchApplication->getTargetPackage()
                );
            }

            $this->packageApplicationRepository->savePackageApplication($packagePatchApplication);
        }
    }

    private function applyPatchesToPackage(PackagePatchApplication $packagePatchApplication)
    {

    }

    private function applyPatchesToRootPackage(PackagePatchApplication $packagePatchApplication)
    {

    }

    /**
     * @return PackageInterface[]
     */
    private function computeChanges()
    {
        $affectedPackages = array_unique(array_merge(
            array_keys($this->installedPackageApplications),
            array_keys($this->targetPackageApplications)
        ));

        $packagesToBeReinstalled = [];
        $packagesToBePatched = [];

        foreach ($affectedPackages as $packageName) {
            $targetApplication = isset($this->targetPackageApplications[$packageName]) ? $this->targetPackageApplications[$packageName] : null;
            $installedApplication = isset($this->installedPackageApplications[$packageName]) ? $this->installedPackageApplications[$packageName] : null;

            if ($targetApplication && !$installedApplication) {
                $this->logger->debug(sprintf('Package <info>%s</info> has pending patches - schedule for patching', $packageName));

                $packagesToBePatched[$packageName] = $targetApplication->getTargetPackage();
            } elseif (!$targetApplication && $installedApplication) {
                $this->logger->debug(sprintf('Package <info>%s</info> has no pending patches, but some installed - schedule for reinstall to clear them', $packageName));

                $packagesToBeReinstalled[$packageName] = $installedApplication->getTargetPackage();
            } elseif ($targetApplication->getHash() !== $installedApplication->getHash()) {
                $this->logger->debug(sprintf('Different installed patchset hash for <info>%s</info> - scheduled for re-patch', $packageName));

                $packagesToBePatched[$packageName] = $targetApplication->getTargetPackage();
                $packagesToBeReinstalled[$packageName] = $targetApplication->getTargetPackage();
            } else {
                $this->logger->debug(sprintf('Package <info>%s</info> has installed patches up to date', $packageName));
            }
        }

        return [$packagesToBeReinstalled, $packagesToBePatched];
    }

    /**
     * Executes the whole patching process
     */
    public function patch()
    {
        $this->reinstallPackages();
        $this->applyPatches();

        if (!$this->hasAnyActionsToPerform()) {
            $this->logger->notice('<info>No patches to apply or clean</info>');
        }
    }

    /**
     * @return PackagePatchApplication[]
     */
    public function getTargetPackageApplications()
    {
        return $this->targetPackageApplications;
    }

    /**
     * @return PackagePatchApplication[]
     */
    public function getInstalledPackageApplications()
    {
        return $this->installedPackageApplications;
    }

    /**
     * @return PackageInterface[]
     */
    public function getPackagesToReinstall()
    {
        return $this->packagesToReinstall;
    }

    /**
     * @return PackageInterface[]
     */
    public function getPackagesToPatch()
    {
        return $this->packagesToPatch;
    }

    /**
     * @return bool
     */
    public function hasAnyActionsToPerform()
    {
        return !empty($this->packagesToReinstall) || !empty($this->packagesToPatch);
    }
}