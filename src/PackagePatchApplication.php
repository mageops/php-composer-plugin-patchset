<?php

namespace Creativestyle\Composer\Patchset;

use Composer\Package\PackageInterface;

/**
 * Represents a group of patches to be applied (or previously applied) to a single package installation.
 */
class PackagePatchApplication
{
    /**
     * @var PatchApplication[]
     */
    private $applications;

    /**
     * @var PackageInterface
     */
    private $targetPackage;

    /**
     * @var string
     */
    private $hash;

    /**
     * @param PackageInterface $targetPackage
     * @param PatchApplication[] $applications
     */
    public function __construct(PackageInterface $targetPackage, array $applications)
    {
        $this->validate($targetPackage, $applications);

        $this->applications = $applications;
        $this->targetPackage = $targetPackage;
        $this->hash = $this->computeHash($targetPackage, $applications);
    }

    /**
     * @return PatchApplication[]
     */
    public function getApplications()
    {
        return $this->applications;
    }

    /**
     * @return PackageInterface
     */
    public function getTargetPackage()
    {
        return $this->targetPackage;
    }

    /**
     * @param PackageInterface $targetPackage
     * @param PatchApplication[] $applications
     */
    private function validate(PackageInterface $targetPackage, array $applications)
    {
        foreach ($applications as $application) {
            if (!$application->getPatch()->canBeAppliedTo($targetPackage)) {
                throw new \InvalidArgumentException('The package "%s" does not support this patch application', $targetPackage->getName());
            }
        }
    }

    /**
     * @return string
     */
    public function getHash()
    {
        return $this->hash;
    }

    /**
     * @param PackageInterface $targetPackage
     * @param PatchApplication[] $applications
     * @return string
     */
    private function computeHash(PackageInterface $targetPackage, array $applications)
    {
        return sha1(
            $targetPackage->getSourceReference() .
            implode('-',
                array_map(function(PatchApplication $application) {
                    return $application->getHash();
                }, $applications)
            )
        );
    }
}