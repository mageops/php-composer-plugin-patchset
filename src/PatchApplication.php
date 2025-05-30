<?php

declare(strict_types=1);

namespace Creativestyle\Composer\Patchset;

use Composer\Package\PackageInterface;

class PatchApplication
{
    public function __construct(
        private Patch $patch,
        private PackageInterface $sourcePackage,
        private PackageInterface $targetPackage,
        private string $hash
    ) {}

    public function getHash(): string
    {
        return $this->hash;
    }

    public function getPatch(): Patch
    {
        return $this->patch;
    }

    public function getSourcePackage(): PackageInterface
    {
        return $this->sourcePackage;
    }

    public function getTargetPackage(): PackageInterface
    {
        return $this->targetPackage;
    }
}
