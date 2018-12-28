<?php

namespace Creativestyle\Composer\Patchset\Tests\Functional\Fixtures;

use Composer\IO\IOInterface;
use Composer\Util\Filesystem;
use Composer\Util\ProcessExecutor;
use Symfony\Component\Process\Process;

class ProjectSandbox
{
    const DEFAULT_COMPOSER_BINARY = 'composer';

    /**
     * @var string
     */
    private $composerBinary = self::DEFAULT_COMPOSER_BINARY;

    /**
     * @var string
     */
    private $rootDir;

    /**
     * @var ComposerSandbox
     */
    private $repository;

    /**
     * @var string
     */
    private $packageName;

    /**
     * @var string
     */
    private $packageVersion;

    /**
     * @var ProcessExecutor
     */
    private $executor;

    /**
     * @var Filesystem
     */
    private $filesystem;

    /**
     * @var array
     */
    private $config;

    /**
     * @var IOInterface
     */
    private $io;

    /**
     * @param string $packageName
     * @param string $packageVersion
     * @param array $config
     * @param ComposerSandbox $repository
     * @param ProcessExecutor $executor
     * @param Filesystem $filesystem
     * @param IOInterface $io
     */
    public function __construct(
        $packageName,
        $packageVersion,
        array $config = [],
        ComposerSandbox $repository,
        ProcessExecutor $executor,
        Filesystem $filesystem,
        IOInterface $io
    ) {
        $this->packageName = $packageName;
        $this->packageVersion = $packageVersion;

        $this->repository = $repository;
        $this->executor = $executor;
        $this->filesystem = $filesystem;

        $this->io = $io;

        $this->config = $config;
        $this->rootDir = $repository->getTempProjectDir() . '/'  . $this->packageName . '/' . uniqid();

        $this->init();
    }

    private function init()
    {
        $this->installProject();
        $this->writeComposerJson();
    }

    private function installProject()
    {
        $dataDir = $this->repository->getPackageDataDir($this->packageName, $this->packageVersion);

        if (null === $dataDir) {
            $this->filesystem->ensureDirectoryExists($this->rootDir);
        } else {
            // Found package, install its contents
            $this->filesystem->copy($dataDir, $this->rootDir);
        }
    }

    private function writeComposerJson()
    {
        $config = [];

        if (file_exists($this->getConfigPath())) {
            // Use existing composer.json as template if there's a fixture for this package
            $config = json_decode(file_get_contents($this->getConfigPath()), true);
        }

        $config = array_merge($config, [
            'name' => $this->packageName,
            'version' => $this->packageVersion
        ]);

        $config = array_merge($config, $this->config);

        file_put_contents($this->getConfigPath(), json_encode($config, JSON_PRETTY_PRINT + JSON_UNESCAPED_SLASHES + JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param string $cmd
     * @param string[] $args
     * @return ComposerRun
     */
    public function runComposerCommand($cmd, ...$args)
    {
        $this->io->write(sprintf("\n--- Running <info>composer %s</info> in project sandbox <info>%s</info> (<comment>%s</comment>) ---\n",
            join(' ', array_merge([$cmd], $args)),
            $this->packageName,
            $this->packageVersion
        ));

        $_ENV['COMPOSER_HOME'] = $this->repository->getTempComposerHomeDir();

        // Always mirror the packages instead of symlinking as the we don't the patches
        // to be applied to our source fixtures and the project templates modified.
        $_ENV['COMPOSER_MIRROR_PATH_REPOS'] = '1';

        $cmdElements = array_merge([$this->composerBinary, $cmd], $args);
        $cmdString = $cmdElements[0] . ' ' .implode(' ', array_map([ProcessExecutor::class, 'escape'], array_slice($cmdElements, 1)));

        $fullOut = '';
        $stdErr = '';
        $stdOut = '';

        $io = $this->io;

        $outputHandler = function($type, $buffer) use (&$fullOut, &$stdErr, &$stdOut, $io) {
            $fullOut .= $buffer;

            if ($type === Process::ERR) {
                $stdErr .= $buffer;
                $io->writeError($buffer, false);
            } else {
                $stdOut .= $buffer;
                $io->write($buffer, false);
            }
        };

        $returnCode = $this->executor->execute($cmdString,$outputHandler, $this->rootDir);

        // Write an empty line for better output readability
        $this->io->write('');

        return new ComposerRun($this, $cmd, $this->rootDir, $returnCode, $fullOut, $stdOut, $stdErr);
    }

    /**
     * @param string $composerBinary
     */
    public function setComposerBinary($composerBinary)
    {
        $this->composerBinary = $composerBinary;
    }

    /**
     * @return string
     */
    public function getConfigPath()
    {
        return $this->rootDir . '/composer.json';
    }

    /**
     * @param string $relativePath
     * @return string
     */
    public function getAbsolutePath($relativePath)
    {
        return rtrim($this->rootDir, '/') . '/' . ltrim($relativePath, '/');
    }

    /**
     * @param string $relativePath
     * @return bool
     */
    public function hasFile($relativePath)
    {
        $path = $this->getAbsolutePath($relativePath);

        return file_exists($path) && is_file($path);
    }

    /**
     * @param string $relativePath
     * @return bool
     */
    public function hasDir($relativePath)
    {
        $path = $this->getAbsolutePath($relativePath);

        return file_exists($path) && is_dir($path);
    }

    /**
     * @param string $relativePath
     * @return string|null
     */
    public function getFileContents($relativePath)
    {
        if (!$this->hasFile($relativePath)) {
            return null;
        }

        return file_get_contents($this->getAbsolutePath($relativePath));
    }

    /**
     * @return bool
     */
    public function hasLockFile()
    {
        return $this->hasFile('/composer.lock');
    }

    /**
     * @return bool
     */
    public function hasVendorsInstalled()
    {
        return $this->hasFile('/vendor/autoload.php');
    }

    public function cleanup()
    {
        $this->filesystem->removeDirectory($this->rootDir);
    }
}