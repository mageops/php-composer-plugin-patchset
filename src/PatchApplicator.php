<?php

namespace Creativestyle\Composer\Patchset;

use Composer\Installer\InstallationManager;
use Composer\Package\PackageInterface;
use Composer\Util\Filesystem;
use Composer\Util\ProcessExecutor;

use Psr\Log\LoggerInterface;

class PatchApplicator
{
    const METHOD_PATCH = 'patch';
    const METHOD_GIT = 'git';

    const METHODS = [
        PatchApplicator::METHOD_PATCH,
        PatchApplicator::METHOD_GIT
    ];

    /**
     * @var InstallationManager
     */
    private $installationManager;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var bool|null
     */
    private $hasPatch = null;

    /**
     * @var PathResolver
     */
    private $pathResolver;

    /**
     * @var ProcessExecutor
     */
    private $executor;

    /**
     * @var string
     */
    private $cmdErr;

    /**
     * @var Filesystem
     */
    private $filesystem;

    public function __construct(
        LoggerInterface $logger,
        InstallationManager $installationManager,
        PathResolver $pathResolver,
        ProcessExecutor $executor
    ) {
        $this->logger = $logger;
        $this->installationManager = $installationManager;
        $this->pathResolver = $pathResolver;
        $this->executor = $executor;
        $this->filesystem = new Filesystem($this->executor);

        if (!$this->hasPatchCommand()) {
            $this->logger->warning('<warning>No `patch` command found, will fall-back to `git apply` for patching</warning>');
        }
    }

    /**
     * @param array|string $cmd
     * @param string|null $cwd
     * @return int Return code
     */
    private function executeCommand($cmd, $cwd = null)
    {
        if (is_array($cmd)) {
            $cmd = $cmd[0] . ' ' .implode(' ', array_map([ProcessExecutor::class, 'escape'], array_slice($cmd, 1)));
        }

        $returnCode = $this->executor->execute($cmd, $output, $cwd);
        $this->cmdErr = $this->executor->getErrorOutput();

        return $returnCode;
    }

    /**
     * @return bool
     */
    private function hasPatchCommand()
    {
        if (null === $this->hasPatch) {
            $this->hasPatch = !$this->executeCommand('command -v patch');
        }

        return $this->hasPatch;
    }

    /**
     * @param string $method
     * @param string $targetDirectory
     * @param string $patchFile
     * @param int $stripPathComponents
     * @return bool
     */
    private function executePatchCommand($method, $targetDirectory, $patchFile, $stripPathComponents)
    {
        $cwd = null;

        if ($method === self::METHOD_PATCH && $this->hasPatchCommand()) {
            $cmd = ['patch', '--posix', '--strip=' . $stripPathComponents, '--input='.$patchFile,  '--directory='.$targetDirectory];
        } else {
            $cmd = ['git', 'apply', '-v', '-p' . $stripPathComponents, '--inaccurate-eof', '--ignore-whitespace', $patchFile];

            if (is_dir(rtrim($targetDirectory, '/') . '/.git')) {
                // Target dir is a git repo so apply relative to it - apparently some git versions have problems otherwise.
                // I haven't found problems with patching "subprepos" using git 2.x, however, this has been reported here:
                // - https://github.com/cweagans/composer-patches/issues/172
                // - https://stackoverflow.com/questions/24821431/git-apply-patch-fails-silently-no-errors-but-nothing-happens/27283285#27283285
                // - http://data.agaric.com/git-apply-does-not-work-from-within-local-checkout-unrelated-git-repository
                $cwd = $targetDirectory;
            } else {
                // If target directory is not a git repo apply relative to project root
                $rootDirectory = $this->filesystem->normalizePath(getcwd());
                $targetDirectory = $this->filesystem->normalizePath($targetDirectory);

                // Do this only if we're not patching the root package
                if ($rootDirectory !== $targetDirectory) {
                    $relativeTargetDirectory = $this->filesystem->findShortestPath($rootDirectory, $targetDirectory);
                    $cmd[] = '--directory=' . $relativeTargetDirectory;
                }
            }
        }

        return !$this->executeCommand($cmd, $cwd);
    }

    public function applyPatch(Patch $patch, PackageInterface $sourcePackage, PackageInterface $targetPackage)
    {
        $targetDirectory = $this->pathResolver->getPackageInstallPath($targetPackage);
        $patchFilename = $this->pathResolver->getPatchSourceFilePath($sourcePackage, $patch);

        if (!$this->executePatchCommand($patch->getMethod(), $targetDirectory, $patchFilename, $patch->getStripPathComponents())) {
            throw new \RuntimeException('Could not apply patch: ' . $this->cmdErr);
        }

        $this->logger->notice(sprintf('Applied patch <info>%s:%s</info> [<comment>%s</comment>] (<comment>%s</comment>) using <comment>%s</comment> method',
            $patch->getSourcePackage(),
            $patch->getFilename(),
            $patch->getVersionConstraint(),
            $patch->getDescription(),
            $patch->getMethod()
        ));
    }
}