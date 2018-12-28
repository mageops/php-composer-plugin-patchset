<?php

namespace Creativestyle\Composer\Patchset\Tests\Functional\Fixtures;

use Composer\Package\Package;
use Composer\Semver\VersionParser;

class FixturePackage extends Package
{
    /**
     * @var string
     */
    private $dataDir;

    /**
     * @var array
     */
    private $config;

    /**
     * @param string $name
     * @param string $version
     * @param string $dataDir
     * @param string $config
     */
    public function __construct($name, $version, $dataDir, $config)
    {
        $versionParser = new VersionParser();

        parent::__construct($name, $versionParser->normalize($version), $version);

        $this->setDistType('path');
        $this->setDistUrl($dataDir);

        $this->dataDir = $dataDir;
        $this->config = $config;
    }

    /**
     * @return string
     */
    public function getDataDir()
    {
        return $this->dataDir;
    }

    /**
     * @return array
     */
    public function getComposerConfig()
    {
        return $this->config;
    }

    /**
     * @return array
     */
    public function buildPackageRepositoryData()
    {
        return array_merge($this->config, [
            'name' => $this->name,
            'version' => $this->prettyVersion,
            'dist' => [
                'type' => 'path',
                'url' => $this->dataDir,
            ],
            'transport-options' => [
                'symlink' => false
            ]
        ]);
    }
}