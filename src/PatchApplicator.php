<?php

namespace Creativestyle\Composer\Patchset;

use Composer\Installer\InstallationManager;
use Composer\Package\PackageInterface;
use Composer\Util\Filesystem;
use Composer\Util\ProcessExecutor;

use Creativestyle\Composer\Patchset\Exception\PatchApplicationFailedException;
use Psr\Log\LoggerInterface;

class PatchApplicator
{
    public const METHOD_PATCH = 'patch';
    public const METHOD_GIT = 'git';

    public const METHODS = [
        PatchApplicator::METHOD_PATCH,
        PatchApplicator::METHOD_GIT
    ];

    private Filesystem $filesystem;

    private string $lastCmd;
    private string $lastCmdOutput;
    private bool $hasPatch;

    public function __construct(
        protected LoggerInterface $logger,
        protected InstallationManager $installationManager,
        protected PathResolver $pathResolver,
        protected ProcessExecutor $executor
    ) {
        $this->filesystem = new Filesystem($this->executor);

        if (!$this->hasPatchCommand()) {
            $this->logger->warning('<warning>No `patch` command found, will fall-back to `git apply` for patching</warning>');
        }
    }

    private function executeCommand(array|string $cmd, ?string $cwd = null): int
    {
        if (is_array($cmd)) {
            $cmd = $cmd[0] . ' ' .implode(' ', array_map([ProcessExecutor::class, 'escape'], array_slice($cmd, 1)));
        }

        $output = '';

        $outputHandler = function($type, $buffer) use (&$output) {
            $output .= $buffer;
        };

        $returnCode = $this->executor->execute($cmd, $outputHandler, $cwd);

        $this->lastCmd = $cmd;
        $this->lastCmdOutput = $output;

        return $returnCode;
    }

    private function hasPatchCommand(): bool
    {
        if (!isset($this->hasPatch)) {
            $this->hasPatch = !$this->executeCommand('command -v patch');
        }

        return $this->hasPatch;
    }

    /**
     * @param string $method
     * @param string $targetDirectory
     * @param string $patchFile
     * @param int $stripPathComponents
     * @param bool $keepEmptyFiles
     * @return bool
     */
    private function executePatchCommand($method, $targetDirectory, $patchFile, $stripPathComponents, $keepEmptyFiles = false)
    {
        $cwd = null;

        if ($method === self::METHOD_PATCH && $this->hasPatchCommand()) {
            $cmd = ['patch', '--batch', '--forward', '--strip=' . $stripPathComponents, '--input='.$patchFile,  '--directory='.$targetDirectory];

            if (!$keepEmptyFiles) {
                $cmd[] = '--remove-empty-files';
            }

        } else {
            $cmd = ['git', 'apply', '-v', '-p' . $stripPathComponents, $patchFile];

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

    public function applyPatch(Patch $patch, PackageInterface $sourcePackage, PackageInterface $targetPackage): void
    {
        $targetDirectory = $this->pathResolver->getPackageInstallPath($targetPackage);
        $patchFilename = $this->pathResolver->getPatchSourceFilePath($sourcePackage, $patch);

        if (!$this->executePatchCommand($patch->getMethod(), $targetDirectory, $patchFilename, $patch->getStripPathComponents())) {
            $this->logger->notice(sprintf('<error>Failed to apply patch</error> <info>%s:%s</info> [<comment>%s</comment>] (<comment>%s</comment>) using <comment>%s</comment> method',
                $patch->getSourcePackage(),
                $patch->getFilename(),
                $patch->getVersionConstraint(),
                $patch->getDescription(),
                $patch->getMethod()
            ));

            throw new PatchApplicationFailedException($this->lastCmd, $this->lastCmdOutput);
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
