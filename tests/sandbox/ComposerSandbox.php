<?php

namespace Creativestyle\Composer\TestingSandbox;

use Composer\IO\ConsoleIO;
use Composer\IO\IOInterface;
use Composer\Package\Package;
use Composer\Util\Filesystem;
use Composer\Util\ProcessExecutor;
use Composer\Json\JsonFile;
use Composer\Factory as ComposerFactory;
use Composer\Repository\RepositoryInterface as ComposerRepositoryInterface;
use Composer\Repository\ArrayRepository as ComposerArrayRepository;
use Symfony\Component\Console\Helper\HelperSet;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\Output;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Process\PhpExecutableFinder;

/**
 * Sandbox helper for doing functional composer tests.
 *
 * Provides a fixture-based local repository and en environment with it's own composer cache, config.
 */
class ComposerSandbox
{
    /**
     * @var bool
     */
    protected static $debugOutputEnabled = false;

    /**
     * @var string
     */
    protected static $selfPackageVersion = '999.999.999';

    /**
     * Path to root dir of the project being tested
     *
     * @var string
     */
    private $selfPackageDir;

    /**
     * Where the package fixtures are located
     *
     * @var string
     */
    private $packageFixturesDir;

    /**
     * Base temporary dir.
     * 
     * @var string     
     */
    private $tempDir;

    /**
     * Root temporary dir for this class instance
     *
     * @var string
     */
    private $tempRootDir;

    /**
     * @var string
     */
    private $tempBinDir;

    /**
     * @var string
     */
    private $tempComposerHomeDir;

    /**
     * @var string
     */
    private $tempProjectDir;

    /**
     * @var string
     */
    private $tempRepositoryDir;

    /**
     * @var ProcessExecutor
     */
    private $executor;

    /**
     * @var Filesystem
     */
    private $filesystem;

    /**
     * @var ComposerRepositoryInterface
     */
    private $packages;

    /**
     * @var InputInterface
     */
    private $input;

    /**
     * @var OutputInterface
     */
    private $output;

    /**
     * @var IOInterface
     */
    private $io;

    /**
     * @var ComposerProjectSandbox[]
     */
    private $projectSandboxes = [];

    /**
     * @var array
     */
    private $composerExtraArgs = [];

    /**
     * @var string
     */
    private $composerBinary = null;

    /**
     * @var string
     */
    private $sandboxId;

    /**
     * @var string
     */
    private $phpBinary = null;

    /**
     * @var bool
     */
    private $cleanupDisabled = false;

    /**
     * Initialize a new sandbox directory.
     * 
     * Root dir will be created and removed automatically. It must not exist
     * and will be created. This is a security feature to prevent accidental 
     * removal of important directories.
     * 
     * Note that if $rootDir is set then $tempDir has no effect.
     * 
     * By default the $tempDir will be set to path provided by sys_get_temp_dir() 
     * or $HOME/.cache/composer-sandbox if the first one is not writable.
     * 
     * The path defined by $tempDir must exist and be writable. It might also
     * be needed to execute files from it, so in case it's a tmpfs mount make
     * sure it's not mounted with noexec option. It might be safer to use 
     * /var/tmp because of this. 
     * 
     * Some CI systems don't allow jobs to write to any global directories - in
     * this case you can override $tempDir globally by env var COMPOSER_SANDBOX_TMPDIR.
     * 
     * @param string $packageFixturesDir
     * @param string|null $sandboxId Unique identifier for this sandbox
     * @param string|null $selfPackageDir
     * @param string|null $rootDir Full path to directory where temporary sandbox files will be stored. 
     * @param string|null $tempDir Base path to temporary directory where sandboxes are stored.
     */
    public function __construct(
        $packageFixturesDir,
        $sandboxId = null,
        $selfPackageDir = null,
        $rootDir = null,
        $tempDir = null
    ) {

        $this->executor = new ProcessExecutor();
        $this->filesystem = new Filesystem($this->executor);

        $this->sandboxId = null !== $sandboxId ? $sandboxId : uniqid('sandbox-', true);

        if (null !== $rootDir) {
            if (file_exists($rootDir)) {
                throw new \InvalidArgumentException(
                    sprintf(
                        'Refusing to use an existing directory %s for the root dir!', 
                        $this->rootDir
                    )
                );
            }

            $this->rootDir = $rootDir;
            $this->tempDir = dirname($this->rootDir);
        } else {
            if (null !== $tempDir) {
                $this->tempDir = $tempDir;
            } elseif ($tempDir = getenv('COMPOSER_SANDBOX_TMPDIR')) {
                $this->tempDir = $tempDir;
            } else {
                $this->tempDir = sys_get_temp_dir();
               
                if (!is_writable($this->tempDir) && $tempDir = getenv('HOME')) {
                    $this->tempDir = $tempDir . '/.cache/composer-sandbox';

                    if (false === @mkdir($this->tempDir, 0755, true)) {
                        throw new \RuntimeException(sprintf(
                            'Could not find a usable temp dir, tried %s and %s.', 
                            sys_get_temp_dir(), 
                            $this->tempDir
                        ));
                    }
                }
            }

            $this->rootDir = rtrim($this->tempDir, '/') . '/' . $this->sandboxId;
        }

        if (!is_dir($this->tempDir) || !is_writable($this->tempDir)) {
            throw new \InvalidArgumentException(sprintf('Cannot create sandbox as the temp dir %s does not exist or is not writable.', $this->tempDir));
        }

        if ($composerExtraArgs = getenv('COMPOSER_SANDBOX_EXTRA_ARGS')) {
            $this->composerExtraArgs = explode(' ', $composerExtraArgs);
        }

        if ($composerBinary = getenv('COMPOSER_SANDBOX_COMPOSER')) {
            $this->composerBinary = $composerBinary;
        }

        if ($phpBinary = getenv('COMPOSER_SANDBOX_PHP')) {
            $this->phpBinary = $phpBinary;
        }

        if (getenv('COMPOSER_SANDBOX_DISABLE_CLEANUP')) {
            $this->disableCleanup();
        }

        if (null === $selfPackageDir) {
            $selfPackageDir = static::getRootProjectDir();
        }

        $this->input = new StringInput('');
        $this->output = static::$debugOutputEnabled
            ? new ConsoleOutput() 
            : new BufferedOutput(Output::VERBOSITY_NORMAL, static::isColorOutputSupported())
        ;

        $this->io = new ConsoleIO($this->input, $this->output, new HelperSet());

        $this->selfPackageDir = $selfPackageDir;
        $this->packageFixturesDir = $packageFixturesDir;

        if (is_dir($this->rootDir)) {
            $this->filesystem->removeDirectory($this->rootDir);
        }

        $this->rootDir = rtrim($this->rootDir, '/');
        $this->binDir = $this->rootDir . '/bin';
        $this->composerHomeDir = $this->rootDir . '/composer';
        $this->projectDir = $this->rootDir . '/project';
        $this->repositoryDir = $this->rootDir . '/repo';

        $this->init();
    }

    /**
     * Skips root directory and projects cleanup when cleanup methods are called.
     */
    public function disableCleanup()
    {
        $this->cleanupDisabled = true;
    }

    public static function getRootProjectDir()
    {
        return realpath(dirname(ComposerFactory::getComposerFile()));
    }

    public static function enableDebugOutput()
    {
        static::$debugOutputEnabled = true;
    }

    /**
     * Dev packages cannot be used because beginning with composer 2.0
     * they cannot be defined without source and we install current package
     * as dist in order to get all changes including those that are unstaged.
     */
    public static function getSelfPackageVersion()
    {
        return static::$selfPackageVersion;
    }

    public static function isColorOutputSupported($stream = STDERR)
    {
        // Follow https://no-color.org/
        if (isset($_SERVER['NO_COLOR']) || false !== getenv('NO_COLOR')) {
            return false;
        }

        if ('Hyper' === getenv('TERM_PROGRAM')) {
            return true;
        }

        if (\DIRECTORY_SEPARATOR === '\\') {
            return (\function_exists('sapi_windows_vt100_support')
                && @sapi_windows_vt100_support($stream))
                || false !== getenv('ANSICON')
                || 'ON' === getenv('ConEmuANSI')
                || 'xterm' === getenv('TERM');
        }

        if (\function_exists('stream_isatty')) {
            return stream_isatty($stream);
        }
        
        return posix_isatty($stream);
    }

    private function init()
    {
        if (null !== $this->packages) {
            throw new \RuntimeException('Already initialized');
        }

        $this->io->write(sprintf(
            "   ---> Setting up composer sandbox: <info>%s</info>", 
            $this->sandboxId
        ));

        $this->io->write(sprintf(
            "   ---> Target directory: <info>%s</info>", 
            $this->rootDir
        ));

        $this->io->write(sprintf(
            "   ---> Using package fixtures: <info>%s</info>", 
            $this->packageFixturesDir
        ));

        $this->filesystem->ensureDirectoryExists($this->rootDir);
        $this->filesystem->ensureDirectoryExists($this->binDir);
        $this->filesystem->ensureDirectoryExists($this->composerHomeDir);
        $this->filesystem->ensureDirectoryExists($this->projectDir);
        $this->filesystem->ensureDirectoryExists($this->repositoryDir);

        $this->packages = $this->gatherPackages();
        $this->buildRepository($this->packages, $this->repositoryDir);

        // This has to be done after repo is built as to not confuse composer/satis earlier
        $this->writeComposerConfig();
    }

    private function gatherPackages()
    {
        $configFinder = Finder::create()
            ->in($this->packageFixturesDir)
            ->files()
            ->name('composer.json');

        $configs = array_map('strval', iterator_to_array($configFinder));
        $packageList = array_map([$this, 'collectPackage'], $configs);

        // Add self as dev-master
        $packageList[] = $this->collectPackage(
            $this->selfPackageDir . '/composer.json', 
            null, 
            static::$selfPackageVersion,
            [
                'non-feature-branches' => 'latest'
            ]
        );

        return new ComposerArrayRepository($packageList);
    }

    /**
     * @param string $configFile
     * @param string|null $name
     * @param string|null $version
     * @return Package
     */
    private function collectPackage($configFile, $name = null, $version = null, array $extraConfig = [])
    {
        $packageJson = new JsonFile($configFile);

        return ComposerPackageFixture::createFromArray(
            array_merge($packageJson->read(), $extraConfig),
            $name, 
            $version, 
            dirname($configFile)
        );
    }

    /**
     * @return array
     */
    private function buildBaseComposerConfig()
    {
        return [
            'config' => [
                'home' => $this->getComposerHomeDir(),
                'cache-dir' => $this->getComposerHomeDir() . '/cache',
                'data-dir' => $this->getComposerHomeDir() . '/data',
            ]
        ];
    }

    /**
     * @return array
     */
    private function buildComposerConfig()
    {
        return array_merge(
            $this->buildBaseComposerConfig(),
            [
                'repositories' => [
                    [
                        'type' => 'composer',
                        'url' => 'file://' . $this->getRepositoryDir()
                    ],
                    [
                        // Never search packagist, this will speed the tests up by large margin
                        'packagist.org' => false
                    ]
                ]
            ]
        );
    }

    /**
     * @param ComposerArrayRepository $packages
     * @param string $repoDir
     */
    private function buildRepository($packages, $repoDir)
    {
        $repository = [];

        /** @var ComposerPackageFixture $package */
        foreach ($packages->getPackages() as $package) {
            $repository['packages'][$package->getName()][$package->getPrettyVersion()] 
                = $package->dumpToArray();
        }

        $packagesJson = new JsonFile($repoDir . '/packages.json');
        $packagesJson->write($repository);
    }


    private function writeComposerConfig()
    {
        $composerJson = new JsonFile($this->getComposerConfigFilePath());
        $composerJson->write($this->buildComposerConfig());
    }

    /**
     * @param string $name Package name to use (must exist in the fixtures repo).
     * @param string $version Version constraint used to find the template package in fixtures repo.
     * @param array $config Data to be written to composer.json
     * @param string $testRunName Override dir name to something readable
     * @return ComposerProjectSandbox
     */
    public function createProject($packageName, $packageVersion = '*', array $packageConfig = [])
    {
        $project = new ComposerProjectSandbox(
            $packageName,
            $packageVersion,
            $packageConfig,
            $this,
            $this->executor,
            $this->filesystem,
            $this->io
        );

        $project->setPhpBinary($this->phpBinary);
        $project->setComposerBinary($this->composerBinary);
        $project->setComposerExtraArgs($this->composerExtraArgs);

        $this->projectSandboxes[] = $project;

        return $project;
    }

    /**
     * @return string
     */
    public function getBinDir()
    {
        return $this->binDir;
    }

    /**
     * @return string
     */
    public function getComposerHomeDir()
    {
        return $this->composerHomeDir;
    }

    /**
     * @return string
     */
    public function getProjectsDir()
    {
        return $this->projectDir;
    }

    /**
     * @return string
     */
    public function getRepositoryDir()
    {
        return $this->repositoryDir;
    }

    /**
     * @param string $packageName
     * @param string $packageVersion
     * @return string|null
     */
    public function getPackageDataDir($packageName, $packageVersion = '*')
    {
        /** @var ComposerPackageFixture $package */
        $package = $this->packages->findPackage($packageName, $packageVersion);

        if (!$package) {
            return null;
        }

        return $package->getDataDir();
    }

    /**
     * @return string
     */
    public function getComposerConfigFilePath()
    {
        return $this->getComposerHomeDir() . '/config.json';
    }

    public function cleanupProjects()
    {
        if ($this->cleanupDisabled) {
            return;
        }

        foreach ($this->projectSandboxes as $projectSandbox) {
            $projectSandbox->cleanup();
        }

        $this->projectSandboxes = [];
    }

    public function cleanup()
    {
        if ($this->cleanupDisabled) {
            $this->io->write(sprintf(
                "   ---> Ignoring cleanup - keeping output buffer and root dir: <info>%s</info>", 
                $this->rootDir
            ));

            return;
        }

        $this->cleanupProjects();
        $this->filesystem->removeDirectory($this->rootDir);
        $this->cleanOutputBuffer();
    }


    public function cleanOutputBuffer()
    {
        if ($this->output instanceof BufferedOutput) {
            $this->output->fetch();
        }
    }

    public function flushOutputBuffer($stderr = true)
    {
        if ($this->output instanceof BufferedOutput) {
            fwrite($stderr ? STDERR : STDOUT, $this->output->fetch());
        }
    }
}