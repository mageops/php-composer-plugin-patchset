<?php

namespace Creativestyle\Composer\Patchset;

use Composer\Package\AliasPackage;
use Composer\Package\Package;
use Composer\Package\PackageInterface;
use Composer\Package\RootPackageInterface;
use Composer\Repository\RepositoryInterface;

use Psr\Log\LoggerInterface;

class PatchCollector
{
    public function __construct(private LoggerInterface $logger) {}

    /**
     * @return Patch[]
     */
    public function collectFromRepository(RepositoryInterface $repository): array
    {
        $patches = [];
        $packages = $repository->getPackages();

        // Ensure the order of packages is always defined.
        // Usort is not stable so the CMP function shall never return 0 or rarely :P
        usort($packages, [$this, 'comparePackagesForSort']);

        foreach ($repository->getPackages() as $package) {
            if ($package instanceof AliasPackage) {
                continue;
            }

            $packagePatches = $this->collectFromPackage($package);
            $patches = array_merge($patches, $packagePatches);

            if (count($packagePatches)) {
                $this->logger->debug(
                    sprintf(
                        'Collected <comment>%d</comment> patches from <info>%s</info>',
                        count($packagePatches),
                        $package->getName()
                    )
                );
            }
        }

        $ignoredPatches = $this->collectIgnoredPatches($repository);

        return array_filter($patches, function ($callback) use ($ignoredPatches) {
            return !in_array($callback->getFilename(), $ignoredPatches);
        });
    }

    protected function comparePackagesForSort(PackageInterface $a, PackageInterface $b): int
    {
        if ($a->getName() === $b->getName()) {
            return strcmp($a->getVersion(), $b->getVersion());
        }

        return strcmp($a->getName(), $b->getName());
    }

    /**
     * @return Patch[]
     */
    protected function collectFromPackage(PackageInterface $package): array
    {
        if (!$this->isAValidPatchset($package)) {
            $this->logger->debug(sprintf('Package <info>%s</info> is not a patchset', $package->getName()));

            return [];
        }

        return $this->createPatches($package->getName(), $package->getExtra()['patchset']);
    }

    /**
     * @return bool
     */
    public function isAValidPatchset(PackageInterface $package): bool
    {
        return ($package instanceof RootPackageInterface || $package->getType() === 'patchset') && isset($package->getExtra()['patchset']);
    }

    /**
     * @return Patch[]
     */
    private function createPatches(string $sourcePackage, array $patchList): array
    {
        $patches = [];

        foreach ($patchList as $targetPackage => $packagePatches) {
            foreach ($packagePatches as $patchConfig) {
                $patches[] = Patch::createFromConfig($sourcePackage, $targetPackage, $patchConfig);
            }
        }

        return $patches;
    }

    private function collectIgnoredPatches(RepositoryInterface $repository): array
    {
        $ignoredPatches = [];

        foreach ($repository->getPackages() as $package) {
            $ignoredPatchesInPackage = $package->getExtra()['patchset-ignore'] ?? [];
            foreach ($ignoredPatchesInPackage as $ignoredPatchInPackage) {
                $this->logger->notice(sprintf('<error>IMPORTANT</error>: Patch will be skipped: <comment>%s</comment>', $ignoredPatchInPackage));
                $ignoredPatches[] = $ignoredPatchInPackage;
            }
        }

        return $ignoredPatches;
    }
}
