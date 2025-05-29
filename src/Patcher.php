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
use Creativestyle\Composer\Patchset\Exception\PatchApplicationFailedException;
use Psr\Log\LoggerInterface;

class Patcher
{
    /**
     * @var Patch[]
     */
    private array $patches;

    private PatchCollector $collector;
    private PatchApplicator $applicator;
    private ArrayRepository $installedRepository;
    private PathResolver $pathResolver;
    private PackageApplicationRepository $packageApplicationRepository;

    /**
     * @var PackagePatchApplication[]
     */
    private array $targetPackageApplications;

    /**
     * @var PackagePatchApplication[]
     */
    private array $installedPackageApplications;

    /**
     * @var PackageInterface[]
     */
    private array $packagesToReinstall;

    /**
     * @var PackageInterface[]
     */
    private array $packagesToPatch;



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
        private LoggerInterface $logger,
        private InstallationManager $installationManager,
        private RepositoryManager $repositoryManager,
        private ProcessExecutor $processExecutor,
        private RootPackageInterface $rootPackage
    ) {
        $this->collector = new PatchCollector($this->logger);
        $this->pathResolver = new PathResolver($installationManager);
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

        [$this->packagesToReinstall, $this->packagesToPatch] = $this->computeChanges();

    }

    private function buildInstalledRepository(): ArrayRepository
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
    private function collectPatches(): array
    {
        return $this->collector->collectFromRepository(
           $this->installedRepository
        );
    }

    /**
     * @return PackagePatchApplication[]
     */
    private function computeTargetPackageApplications(): array
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

                    if (!$sourcePackage) {
                        $this->logger->debug(sprintf('Could not find source package %s (%s) for installed patch, it was removed probably',
                            $patch->getSourcePackage(),
                            $patch->getVersionConstraint(),
                        ));
                    }

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

            if (empty($applications)) {
                continue;
            }

            $applications = array_values($applications);

            $packageApplications[$targetPackage->getName()] = new PackagePatchApplication($targetPackage, $applications);
        }

        return $packageApplications;
    }

    private function computeApplicationHash(PackageInterface $sourcePackage, Patch $patch): string
    {
        $sourcePath = $this->pathResolver->getPatchSourceFilePath($sourcePackage, $patch);

        if (!is_readable($sourcePath)) {
            throw new \RuntimeException(sprintf('Patch source file "%s" does not exist', $sourcePath));
        }

        return sha1_file($sourcePath);
    }

    private function reinstallPackages(): void
    {
        $localRepo = $this->repositoryManager->getLocalRepository();

        foreach ($this->packagesToReinstall as $package) {
            if ($package instanceof RootPackageInterface) {
                $this->logger->warning(
                    sprintf('Root package patches have changed but cannot reinstall it, will apply only new patches. You should reinstall the whole project to be safe. Package: %s %s',
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

    private function applyPatches(): void
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

            /** @var PatchApplicationFailedException|null $applicationFailedException */
            $applicationFailedException = null;

            /** @var PatchApplication[] $processedPatchApplications */
            $processedPatchApplications = [];

            foreach ($packagePatchApplication->getApplications() as $patchApplication) {
                try {
                    $this->applicator->applyPatch(
                        $patchApplication->getPatch(),
                        $patchApplication->getSourcePackage(),
                        $patchApplication->getTargetPackage()
                    );
                } catch (PatchApplicationFailedException $exception) {
                    // Break out of the loop to save current state (already applied patches).
                    $applicationFailedException = $exception;
                    break;
                }

                $processedPatchApplications[] = $patchApplication;
            }

            $this->packageApplicationRepository->savePackageApplication(
                new PackagePatchApplication(
                    $packagePatchApplication->getTargetPackage(),
                    $processedPatchApplications
                )
            );

            if ($applicationFailedException) {
                // We still rethrow the exception after handling it gracefully
                throw $applicationFailedException;
            }
        }
    }

    /**
     * @return array[PackageInterface[], PackageInterface[]]
     */
    private function computeChanges(): array
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
     * @throws \Exception
     */
    public function patch(): void
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
    public function getTargetPackageApplications(): array
    {
        return $this->targetPackageApplications;
    }

    /**
     * @return PackagePatchApplication[]
     */
    public function getInstalledPackageApplications(): array
    {
        return $this->installedPackageApplications;
    }

    /**
     * @return PackageInterface[]
     */
    public function getPackagesToReinstall(): array
    {
        return $this->packagesToReinstall;
    }

    /**
     * @return PackageInterface[]
     */
    public function getPackagesToPatch(): array
    {
        return $this->packagesToPatch;
    }

    public function hasAnyActionsToPerform(): bool
    {
        return !empty($this->packagesToReinstall) || !empty($this->packagesToPatch);
    }
}
