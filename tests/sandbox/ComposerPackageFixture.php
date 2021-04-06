<?php

namespace Creativestyle\Composer\TestingSandbox;

use Composer\Package\Package;
use Composer\Package\Dumper\ArrayDumper;
use Composer\Package\Loader\ArrayLoader;

class ComposerPackageFixture extends Package
{
    /**
     * @var ArrayDumper
     */
    private static $dumper;

    /**
     * @var ArrayLoader
     */
    private static $loader;

    /**
     * @var string
     */
    private $dataDir;

    /**
     * @param string $name
     * @param string $version
     * @param string $dataDir
     */
    protected function configure($dataDir = null)
    {
        $this->dataDir = $dataDir;
        
        if (null !== $this->dataDir) {
            $this->setDistType('path');
            $this->setDistUrl($dataDir);
        }

        $this->transportOptions = array_merge([
            'symlink' => false
        ], $this->transportOptions);
    }

    /**
     * @return ComposerPackageFixture
     */
    public static function createFromArray(array $config, $name = null, $version = null, $dataDir = null)
    {
        if (null !== $name) {
            $config['name'] = strtolower($name);
        }

        if (null !== $version) {
            $config['version'] = $version;
        }

        /** @var ComposerPackageFixture **/
        $package = static::getLoader()->load($config, self::class);
        $package->configure($dataDir);

        return $package;
    }

    /**
     * @return ArrayLoader
     */
    protected static function getLoader()
    {
        if (!self::$loader) {
            self::$loader = new ArrayLoader();
        }

        return self::$loader;
    }

    /**
     * @return ArrayDumper
     */
    protected function getDumper()
    {
        if (!self::$dumper) {
            self::$dumper = new ArrayDumper();
        }

        return self::$dumper;
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
    public function dumpToArray()
    {
        return $this->getDumper()->dump($this);
    }
}