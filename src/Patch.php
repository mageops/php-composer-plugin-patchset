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
     * @var int
     */
    private $stripPathComponents = 1;

    /**
     * @var string
     */
    private $method = PatchApplicator::METHOD_PATCH;

    /**
     * @var bool
     */
    private $keepEmptyFiles;

    /**
     * @param $sourcePackage
     * @param string $targetPackage
     * @param string $versionConstraint
     * @param string $filename
     * @param string $description
     * @param int $stripPathComponents
     * @param string $method
     * @param bool $keepEmptyFiles
     */
    public function __construct(
        $sourcePackage,
        $targetPackage,
        $versionConstraint,
        $filename,
        $description,
        $stripPathComponents = 1,
        $method = PatchApplicator::METHOD_PATCH,
        $keepEmptyFiles = false
    ) {
        $this->sourcePackage = $sourcePackage;
        $this->targetPackage = $targetPackage;
        $this->versionConstraint = $versionConstraint;
        $this->filename = $filename;
        $this->description = $description;
        $this->stripPathComponents = $stripPathComponents;
        $this->method = $method;
        $this->keepEmptyFiles = $keepEmptyFiles;
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
            'version-constraint' => '*',
            'description' => null,
            'strip-path-components' => 1,
            'method' => PatchApplicator::METHOD_PATCH,
            'keep-empty-files' => false,
        ], $config);

        if (!in_array($config['method'], PatchApplicator::METHODS)) {
            throw new \RuntimeException(sprintf('Unsupported patch application method "%s" in patchset "%s", use one of %s',
                $config['method'],
                $sourcePackage,
                join(', ', PatchApplicator::METHODS)
            ));
        }

        return new static(
            $sourcePackage,
            $targetPackage,
            $config['version-constraint'],
            $config['filename'],
            $config['description'],
            $config['strip-path-components'],
            $config['method'],
            $config['keep-empty-files']
        );
    }

    public static function createFromArray(array $data)
    {
        $data = array_merge([
            'version_constraint' => '*',
            'description' => null,
            'strip_path_components' => 1,
            'method' => PatchApplicator::METHOD_PATCH,
            'keep_empty_files' => false,
        ], $data);

        return new static(
            $data['source_package'],
            $data['target_package'],
            $data['version_constraint'],
            $data['filename'],
            $data['description'],
            $data['strip_path_components'],
            $data['method'],
            $data['keep_empty_files']
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

    /**
     * @return int
     */
    public function getStripPathComponents()
    {
        return $this->stripPathComponents;
    }

    /**
     * @return bool
     */
    public function getKeepEmptyFiles()
    {
        return $this->keepEmptyFiles;
    }

    /**
     * @return string
     */
    public function getMethod()
    {
        return $this->method;
    }

    public function toArray()
    {
        return [
            'source_package' => $this->sourcePackage,
            'target_package' => $this->targetPackage,
            'version_constraint' => $this->versionConstraint,
            'filename' => $this->filename,
            'description' => $this->description,
            'strip_path_components' => $this->stripPathComponents,
            'method' => $this->method,
            'keep_empty_files' => $this->keepEmptyFiles,
        ];
    }
}