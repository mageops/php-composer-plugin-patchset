<?php

namespace Creativestyle\Composer\Patchset\Tests\Functional\Fixtures;

use Composer\IO\ConsoleIO;
use Composer\IO\IOInterface;
use Composer\Package\Package;
use Composer\Satis\Builder\PackagesBuilder;
use Composer\Satis\PackageSelection\PackageSelection;
use Composer\Util\Filesystem;
use Composer\Util\ProcessExecutor;
use Composer\Factory as ComposerFactory;
use Composer\Config as ComposerConfig;
use Composer\Repository\RepositoryInterface as ComposerRepositoryInterface;
use Composer\Repository\ArrayRepository as ComposerArrayRepository;
use Symfony\Component\Console\Helper\HelperSet;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Finder\Finder;

/**
 * Sandbox helper for doing functional composer tests.
 *
 * Provides a fixture-based local repository and en environment with it's own composer cache, config.
 */
class ComposerSandbox
{
    const DEFAULT_PACKAGE_FIXTURES_DIR = __DIR__ . '/packages';

    /**
     * @var bool
     */
    public static $debugOutputEnabled = false;

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
     * Root temporary dir for this class instance
     *
     * @var string
     */
    private $tempDir;

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
     * @var ProjectSandbox[]
     */
    private $projectSandboxes = [];

    /**
     * @param string|null $packageFixturesDir
     * @param string|null $selfPackageDir
     * @param string|null $tempDir
     */
    public function __construct(
        $packageFixturesDir = null,
        $selfPackageDir = null,
        $tempDir = null
    ) {
        $this->executor = new ProcessExecutor();
        $this->filesystem = new Filesystem($this->executor);

        if (null === $packageFixturesDir) {
            $packageFixturesDir = self::DEFAULT_PACKAGE_FIXTURES_DIR;
        }

        if (null === $tempDir) {
            $tempDir = sys_get_temp_dir() . '/' . uniqid('composer-plugin-patchset-test-fixtures');
        }

        if (null === $selfPackageDir) {
            $selfPackageDir = getcwd();
        }

        $this->input = new StringInput('');
        $this->output = static::$debugOutputEnabled ? new ConsoleOutput() : new NullOutput();
        $this->io = new ConsoleIO($this->input, $this->output, new HelperSet());

        $this->selfPackageDir = $selfPackageDir;
        $this->packageFixturesDir = $packageFixturesDir;

        $this->tempDir = $tempDir;
        $this->tempBinDir = $this->tempDir . '/bin';
        $this->tempComposerHomeDir = $this->tempDir . '/composer';
        $this->tempProjectDir = $this->tempDir . '/project';
        $this->tempRepositoryDir = $this->tempDir . '/repo';

        $this->init();
    }

    private function init()
    {
        if (null !== $this->packages) {
            throw new \RuntimeException('Already initialized');
        }

        $this->io->write(sprintf("\n--- Initializing composer sandbox with fixtures from <info>%s</info> ---\n", $this->packageFixturesDir));

        $this->filesystem->ensureDirectoryExists($this->tempDir);
        $this->filesystem->ensureDirectoryExists($this->tempBinDir);
        $this->filesystem->ensureDirectoryExists($this->tempComposerHomeDir);
        $this->filesystem->ensureDirectoryExists($this->tempProjectDir);
        $this->filesystem->ensureDirectoryExists($this->tempRepositoryDir);

        $this->packages = $this->gatherPackages();
        $this->buildRepository($this->packages, $this->tempRepositoryDir);

        // This has to be done after repo is built as to not confuse composer/satis earlier
        $this->writeProjectComposerConfig();
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
        $packageList[] = $this->collectPackage($this->selfPackageDir . '/composer.json', null, 'dev-master');

        return new ComposerArrayRepository($packageList);
    }

    /**
     * @param string $configFile
     * @param string|null $name
     * @param string|null $version
     * @return Package
     */
    private function collectPackage($configFile, $name = null, $version = null)
    {
        $configData = json_decode(file_get_contents($configFile), true);

        if (null === $configData) {
            throw new \RuntimeException("Could not decode JSON in ${$configFile}: " . json_last_error_msg());
        }

        if (null === $name) {
            if (!isset($configData['name'])) {
                throw new \RuntimeException("No package name found in ${$configFile}");
            }

            $name = $configData['name'];
        }

        if (null === $version) {
            if (!isset($configData['version'])) {
                throw new \RuntimeException("No package version found in ${$configFile}");
            }

            $version = $configData['version'];
        }

        return new FixturePackage(
            $name,
            $version,
            dirname($configFile),
            $configData
        );
    }

    /**
     * @return array
     */
    private function buildBaseComposerConfig()
    {
        return [
            'config' => [
                'home' => $this->getTempComposerHomeDir(),
                'cache-dir' => $this->getTempComposerHomeDir() . '/cache',
                'data-dir' => $this->getTempComposerHomeDir() . '/data',
            ]
        ];
    }

    /**
     * @param ComposerArrayRepository $packages
     * @return array
     */
    private function buildSatisComposerConfig($packages)
    {
        return array_merge($this->buildBaseComposerConfig(), [
            'repositories' => $this->buildRepositoriesConfig($packages),
        ]);
    }

    /**
     * @param ComposerArrayRepository $packages
     * @return array
     */
    private function buildSatisConfig($packages)
    {
        return array_merge($this->buildSatisComposerConfig($packages), [
            'name' => 'Testing Satis Repository'
        ]);
    }

    /**
     * @return array
     */
    private function buildProjectComposerConfig()
    {
        return array_merge(
            $this->buildBaseComposerConfig(),
            [
                'repositories' => [
                    [
                        'type' => 'composer',
                        'url' => 'file://' . $this->getTempRepositoryDir()
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
        // Let's do not pull all packages from packagist, this would make this slow as hell
        unset(ComposerConfig::$defaultRepositories['packagist'], ComposerConfig::$defaultRepositories['packagist.org']);

        $satisConfig = $this->buildSatisConfig($packages);
        $composerConfig = $this->buildSatisComposerConfig($packages);

        $composer = ComposerFactory::create($this->io, $composerConfig);

        $packageSelection = new PackageSelection(
            $this->output,
            $repoDir,
            $satisConfig,
            false
        );

        $builder = new PackagesBuilder(
            $this->output,
            $repoDir,
            $satisConfig,
            false
        );

        $builder->dump($packageSelection->select($composer, true));
    }


    /**
     * @param ComposerArrayRepository $packages
     * @return array
     */
    private function buildRepositoriesConfig($packages)
    {
        $repos = [];

        /** @var FixturePackage $package */
        foreach ($packages->getPackages() as $package) {
            $repos[] = [
                'type' => 'package',
                'package' => $package->buildPackageRepositoryData()
            ];
        }

        return $repos;
    }

    private function writeProjectComposerConfig()
    {
        file_put_contents(
            $this->getComposerConfigFilePath(),
            json_encode($this->buildProjectComposerConfig(), JSON_PRETTY_PRINT + JSON_UNESCAPED_SLASHES + JSON_UNESCAPED_UNICODE)
        );
    }

    /**
     * @param string $name
     * @param string $version
     * @param array $config Data to be written to composer.json
     * @return ProjectSandbox
     */
    public function createProjectSandBox($name, $version = 'dev-master', array $config = [])
    {
        $project = new ProjectSandbox(
            $name,
            $version,
            $config,
            $this,
            $this->executor,
            $this->filesystem,
            $this->io
        );

        $this->projectSandboxes[] = $project;

        return $project;
    }

    /**
     * @return string
     */
    public function getTempBinDir()
    {
        return $this->tempBinDir;
    }

    /**
     * @return string
     */
    public function getTempComposerHomeDir()
    {
        return $this->tempComposerHomeDir;
    }

    /**
     * @return string
     */
    public function getTempProjectDir()
    {
        return $this->tempProjectDir;
    }

    /**
     * @return string
     */
    public function getTempRepositoryDir()
    {
        return $this->tempRepositoryDir;
    }

    /**
     * @param string $packageName
     * @param string $packageVersion
     * @return string|null
     */
    public function getPackageDataDir($packageName, $packageVersion = '*')
    {
        /** @var FixturePackage $package */
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
        return $this->getTempComposerHomeDir() . '/config.json';
    }

    public function cleanupProjects()
    {
        foreach ($this->projectSandboxes as $projectSandbox) {
            $projectSandbox->cleanup();
        }

        $this->projectSandboxes = [];
    }

    public function cleanup()
    {
        $this->cleanupProjects();
        $this->filesystem->removeDirectory($this->tempDir);
    }
}