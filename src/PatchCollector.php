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
    /**
     * @var LoggerInterface
     */
    private $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * @param RepositoryInterface $repository
     * @return Patch[]
     */
    public function collectFromRepository(RepositoryInterface $repository)
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
                $this->logger->debug(sprintf('Collected <comment>%d</comment> patches from <info>%s</info>',
                    count($packagePatches),
                    $package->getName())
                );
            }
        }

        $ignoredPatches = $this->collectIngoredPatches($repository);

        $patches = array_filter($patches, function($callback) use ($ignoredPatches) {
            foreach($ignoredPatches as $ignoredPatch){
                if($ignoredPatch == $callback->getFilename()){
                    return false;
                }
            }

            return true;
        });

        return $patches;
    }

    /**
     * @param PackageInterface $a
     * @param PackageInterface $b
     * @return int
     */
    protected function comparePackagesForSort(PackageInterface $a, PackageInterface $b)
    {
        if ($a->getName() === $b->getName()) {
            return strcmp($a->getVersion(), $b->getVersion());
        }

        return strcmp($a->getName(), $b->getName());
    }

    /**
     * @param PackageInterface $package
     * @return Patch[]
     */
    protected function collectFromPackage(PackageInterface $package)
    {
        if (!$this->isAValidPatchset($package)) {
            $this->logger->debug(sprintf('Package <info>%s</info> is not a patchset', $package->getName()));

            return [];
        }

        return $this->createPatches($package->getName(), $package->getExtra()['patchset']);
    }

    /**
     * @param PackageInterface $package
     * @return bool
     */
    public function isAValidPatchset(PackageInterface $package)
    {
        return ($package instanceof RootPackageInterface || $package->getType() === 'patchset') && isset($package->getExtra()['patchset']);
    }

    /**
     * @param string $sourcePackage
     * @param array $patchList
     * @return Patch[]
     */
    private function createPatches($sourcePackage, array $patchList)
    {
        $patches = [];

        foreach ($patchList as $targetPackage => $packagePatches) {
            foreach ($packagePatches as $patchConfig) {
                $patches[] = Patch::createFromConfig($sourcePackage, $targetPackage, $patchConfig);
            }
        }

        return $patches;
    }

    private function collectIngoredPatches(RepositoryInterface $repository){
        $ignoredPatches = [];

        foreach($repository->getPackages() as $package){
            $ignoredPatchesInPackage = $package->getExtra()['patchset-ignore'] ?? [];
            foreach($ignoredPatchesInPackage as $ignoredPatchInPackage){
                $this->logger->notice(sprintf('<error>IMPORTANT</error>: Patch will be skipped: <comment>%s</comment>', $ignoredPatchInPackage));
                $ignoredPatches[] = $ignoredPatchInPackage;
            }
        }

        return $ignoredPatches;
    }
}
