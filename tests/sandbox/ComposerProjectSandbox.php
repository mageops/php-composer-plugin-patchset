<?php

namespace Creativestyle\Composer\TestingSandbox;

use Composer\IO\IOInterface;
use Composer\Util\Filesystem;
use Composer\Util\ProcessExecutor;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\PhpExecutableFinder;

class ComposerProjectSandbox
{
    const DEFAULT_PHP_BINARY = PHP_BINARY;
    const DEFAULT_COMPOSER_BINARY = 'composer';

    /**
     * @var string
     */
    private $phpBinary;

    /**
     * @var string
     */
    private $composerBinary = self::DEFAULT_COMPOSER_BINARY;

    /**
     * @var array
     */
    private $composerExtraArgs = [];

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
     * @var PhpExecutableFinder
     */
    private $phpExecutableFinder;

    /**
     * @var ExecutableFinder
     */
    private $composerExecutableFinder;

    /**
     * @param string $packageName
     * @param string|null $packageVersion
     * @param array $config
     * @param ComposerSandbox $repository
     * @param ProcessExecutor $executor
     * @param Filesystem $filesystem
     * @param IOInterface $io
     */
    public function __construct(
        $packageName,
        $packageVersion,
        array $config,
        ComposerSandbox $repository,
        ProcessExecutor $executor,
        Filesystem $filesystem,
        IOInterface $io
    ) {
        $this->phpExecutableFinder = new PhpExecutableFinder();
        $this->composerExecutableFinder = new ExecutableFinder();
        $this->composerExecutableFinder->setSuffixes(['.phar']);

        $this->packageName = $packageName;
        $this->packageVersion = $packageVersion;

        $this->repository = $repository;
        $this->executor = $executor;
        $this->filesystem = $filesystem;

        $this->io = $io;

        $this->config = $config;
        $this->rootDir = $repository->getProjectsDir() . '/'  . $this->packageName; 

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

        $config = array_merge($config, $this->config);

        file_put_contents($this->getConfigPath(), json_encode($config, JSON_PRETTY_PRINT + JSON_UNESCAPED_SLASHES + JSON_UNESCAPED_UNICODE));
    }

    protected function getComposerBinary()
    {
        if (is_executable($this->composerBinary)) {
            return $this->composerBinary;
        }

        return $this->composerExecutableFinder->find($this->composerBinary);
    }

    protected function getPhpBinary()
    {
        if ($this->phpBinary) {
            return $this->phpBinary;
        }

        return $this->phpExecutableFinder->find(false);
    }

    protected function getPhpCommandElements()
    {
        return [
            $this->getPhpBinary(), 
            '-d memory_limit=-1'
        ];
    }

    protected function getComposerCommandBaseArgs()
    {
        return [
            $this->getComposerBinary(),
            ComposerSandbox::isColorOutputSupported() ? '--ansi' : '--no-ansi',
            '--no-cache'
        ];
    }

    /**
     * @param string $cmd
     * @param string[] $args
     * @return ComposerCommandResult
     */
    public function runComposerCommand($cmd, ...$args)
    {
        $_ENV['PATH'] = $this->repository->getBinDir() . ':' . getenv('PATH');
        $_ENV['COMPOSER_HOME'] = $this->repository->getComposerHomeDir();
        $_ENV['COMPOSER_BIN_DIR'] = $this->repository->getBinDir();
        
        // Always mirror the packages instead of symlinking as the we don't want 
        // the patches to be applied to our source fixture files.
        $_ENV['COMPOSER_MIRROR_PATH_REPOS'] = '1';
        $_ENV['COMPOSER_NO_INTERACTION'] = '1';
        $_ENV['COMPOSER_PROCESS_TIMEOUT'] = '300';
        $_ENV['COMPOSER_DEBUG_EVENTS'] = '1';
        $_ENV['COMPOSER_DISABLE_NETWORK'] = '1';
        
        $cmdElements = array_merge(
            $this->getPhpCommandElements(),
            $this->getComposerCommandBaseArgs(),
            $this->composerExtraArgs,
            [$cmd],
            $args
        );

        $cmdElements = array_map('trim', $cmdElements);
        $cmdElements = array_filter($cmdElements, 'strlen');

        $cmdString = implode(' ', array_map([ProcessExecutor::class, 'escape'], $cmdElements));

        $this->io->write(sprintf("   ---> Project sandbox: <info>%s</info> [<comment>%s</comment>]",
            $this->packageName,
            $this->packageVersion
        ));

        $this->io->write(sprintf("   ---> Working directory: <info>%s</info>",
            $this->rootDir
        ));
        
        $this->io->write(sprintf("   ---> Using PHP <info>%s</info>: <comment>%s</comment>",
            $this->getPhpBinary(),
            trim(strtok(@shell_exec($this->getPhpBinary() . ' --version'), "\n"))
        ));

        $this->io->write(sprintf("   ---> Using composer <info>%s</info>: <comment>%s</comment>",
            $this->getComposerBinary(),
            trim(strtok(@shell_exec($this->getComposerBinary() . ' --version --no-plugins --no-ansi --no-interaction'), "\n"))
        ));

        $this->io->write(sprintf("   ---> Executing command: <info>%s</info> \n",
            $cmdString
        ));

        $fullOut = '';
        $stdErr = '';
        $stdOut = '';

        $outputHandler = function($type, $buffer) use (&$fullOut, &$stdErr, &$stdOut) {
            static $outPrefix = '   <<   ';
            static $errPrefix = '   !!   ';

            if ($type === Process::ERR) {
                $this->io->writeRaw((empty($fullOut) ? $errPrefix : '') . str_replace("\n", "\n" . $errPrefix, $buffer), false);
                $stdErr .= $buffer;
            } else {
                $this->io->writeRaw((empty($fullOut) ? $outPrefix : '') . str_replace("\n", "\n" . $outPrefix, $buffer), false);
                $stdOut .= $buffer;
            }

            $fullOut .= $buffer;
        };

        $returnCode = $this->executor->execute($cmdString, $outputHandler, $this->rootDir);

        $this->io->write('');
        $this->io->write(sprintf("\n   <--- Composer command %s with code <comment>%s</comment>\n",
            $returnCode === 0 ? '<info>succeeded</info>' : '<comment>failed</comment>',
            $returnCode
        ));

        if ($returnCode !== 0) {
            $this->repository->disableCleanup();
        }

        return new ComposerCommandResult($this, $cmd, $this->rootDir, $returnCode, $fullOut, $stdOut, $stdErr);
    }

    /**
     * @param string $composerBinary
     */
    public function setPhpBinary($phpBinary = null)
    {
        if (null === $phpBinary) {
            $this->phpBinary = self::DEFAULT_PHP_BINARY;
        } else {
            $this->phpBinary = $phpBinary;
        }
    }

    /**
     * @param string $composerBinary
     */
    public function setComposerBinary($composerBinary = null)
    {
        if (null === $composerBinary) {
            $this->composerBinary = self::DEFAULT_COMPOSER_BINARY;
        } else {
            $this->composerBinary = $composerBinary;
        }
    }

    /**
     * @param array $composerExtraArgs
     */
    public function setComposerExtraArgs(array $composerExtraArgs = [])
    {
        $this->composerExtraArgs = $composerExtraArgs;
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