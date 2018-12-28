<?php

namespace Creativestyle\Composer\Patchset;

use Composer\Package\Package;
use Composer\Package\PackageInterface;
use Composer\Semver\Semver;

class Patch
{
    /**
     * Name of the patchset package the patch came from.
     *
     * @var string
     */
    private $sourcePackage;

    /**
     * Name of the package to be patched.
     *
     * @var string
     */
    private $targetPackage;

    /**
     * Short description of the patch
     *
     * @var string|null
     */
    private $description;

    /**
     * Version constraint that the package version will be checked against.
     *
     * @var string|null
     */
    private $versionConstraint;

    /**
     * Patch file location.
     *
     * @var string
     */
    private $filename;

    /**
     * @param $sourcePackage
     * @param string $targetPackage
     * @param string $versionConstraint
     * @param string $filename
     * @param string $description
     */
    public function __construct(
        $sourcePackage,
        $targetPackage,
        $versionConstraint,
        $filename,
        $description
    ) {
        $this->sourcePackage = $sourcePackage;
        $this->targetPackage = $targetPackage;
        $this->versionConstraint = $versionConstraint;
        $this->filename = $filename;
        $this->description = $description;
    }

    /**
     * @param string $sourcePackage
     * @param string $targetPackage
     * @param array $config
     * @return Patch
     */
    public static function createFromConfig($sourcePackage, $targetPackage, array $config)
    {
        $config = array_merge([
            'version_constraint' => '*',
            'description' => null,
        ], $config);

        return new static(
            $sourcePackage,
            $targetPackage,
            $config['version_constraint'],
            $config['filename'],
            $config['description']
        );
    }

    public static function createFromArray(array $data)
    {

        return new static(
            $data['source_package'],
            $data['target_package'],
            $data['version_constraint'],
            $data['filename'],
            $data['description']
        );
    }

    /**
     * @param PackageInterface $package
     * @return bool
     */
    public function canBeAppliedTo(PackageInterface $package)
    {
        if ($package->getName() !== $this->targetPackage) {
            return false;
        }

        if (null !== $this->versionConstraint && !Semver::satisfies($package->getVersion(), $this->versionConstraint)) {
            return false;
        }

        return true;
    }

    /**
     * @return string
     */
    public function getSourcePackage()
    {
        return $this->sourcePackage;
    }

    /**
     * @return string
     */
    public function getTargetPackage()
    {
        return $this->targetPackage;
    }

    /**
     * @return string|null
     */
    public function getVersionConstraint()
    {
        return $this->versionConstraint;
    }

    /**
     * @return string
     */
    public function getFilename()
    {
        return $this->filename;
    }

    /**
     * @return string|null
     */
    public function getDescription()
    {
        return $this->description;
    }

    public function toArray()
    {
        return [
            'source_package' => $this->sourcePackage,
            'target_package' => $this->targetPackage,
            'version_constraint' => $this->versionConstraint,
            'filename' => $this->filename,
            'description' => $this->description,
        ];
    }
}