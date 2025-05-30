<?php

namespace Creativestyle\Composer\Patchset;

use Composer\Package\Package;
use Composer\Package\PackageInterface;
use Composer\Semver\Semver;

class Patch
{
    public function __construct(
        private string $sourcePackage,
        private string $targetPackage,
        private string $versionConstraint,
        private string $filename,
        private string $description,
        private int $stripPathComponents = 1,
        private string $method = PatchApplicator::METHOD_PATCH,
        private bool $keepEmptyFiles = false
    ) {}

    public static function createFromConfig(string $sourcePackage, string $targetPackage, array $config): Patch
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

    public function canBeAppliedTo(PackageInterface $package): bool
    {
        if ($package->getName() !== $this->targetPackage) {
            return false;
        }

        if (null !== $this->versionConstraint && !Semver::satisfies($package->getVersion(), $this->versionConstraint)) {
            return false;
        }

        return true;
    }

    public function getSourcePackage(): string
    {
        return $this->sourcePackage;
    }

    public function getTargetPackage(): string
    {
        return $this->targetPackage;
    }

    public function getVersionConstraint(): ?string
    {
        return $this->versionConstraint;
    }

    public function getFilename(): string
    {
        return $this->filename;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function getStripPathComponents(): int
    {
        return $this->stripPathComponents;
    }

    public function getKeepEmptyFiles(): bool
    {
        return $this->keepEmptyFiles;
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function toArray(): array
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
