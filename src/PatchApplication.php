<?php

namespace Creativestyle\Composer\Patchset;

use Composer\Package\PackageInterface;

/**
 * Represents a singular patch application
 */
class PatchApplication
{
    /**
     * @var Patch
     */
    private $patch;

    /**
     * @var PackageInterface
     */
    private $sourcePackage;

    /**
     * @var PackageInterface
     */
    private $targetPackage;

    /**
     * @var string
     */
    private $hash;

    /**
     * @param Patch $patch
     * @param PackageInterface $sourcePackage
     * @param PackageInterface $targetPackage
     * @param string $hash
     */
    public function __construct(
        Patch $patch,
        PackageInterface $sourcePackage,
        PackageInterface $targetPackage,
        $hash
    ) {
        $this->patch = $patch;
        $this->sourcePackage = $sourcePackage;
        $this->targetPackage = $targetPackage;
        $this->hash = $hash;
    }

    /**
     * @return string
     */
    public function getHash()
    {
        return $this->hash;
    }

    /**
     * @return Patch
     */
    public function getPatch()
    {
        return $this->patch;
    }

    /**
     * @return PackageInterface
     */
    public function getSourcePackage()
    {
        return $this->sourcePackage;
    }

    /**
     * @return PackageInterface
     */
    public function getTargetPackage()
    {
        return $this->targetPackage;
    }
}