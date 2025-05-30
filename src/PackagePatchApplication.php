<?php

namespace Creativestyle\Composer\Patchset;

use Composer\Package\PackageInterface;

/**
 * Represents a group of patches to be applied (or previously applied) to a single package installation.
 */
class PackagePatchApplication
{
    private string $hash;

    /**
     * @param PatchApplication[] $applications
     */
    public function __construct(private PackageInterface $targetPackage, private array $applications)
    {
        $this->validate($targetPackage, $applications);

        $this->hash = $this->computeHash($targetPackage, $applications);
    }

    /**
     * @return PatchApplication[]
     */
    public function getApplications(): array
    {
        return $this->applications;
    }

    public function getTargetPackage(): PackageInterface
    {
        return $this->targetPackage;
    }

    /**
     * @param PatchApplication[] $applications
     */
    private function validate(PackageInterface $targetPackage, array $applications): void
    {
        foreach ($applications as $application) {
            if (!$application->getPatch()->canBeAppliedTo($targetPackage)) {
                throw new \InvalidArgumentException('The package "%s" does not support this patch application', $targetPackage->getName());
            }
        }
    }

    public function getHash(): string
    {
        return $this->hash;
    }

    /**
     * @param PatchApplication[] $applications
     */
    private function computeHash(PackageInterface $targetPackage, array $applications): string
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
