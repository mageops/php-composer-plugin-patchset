<?php

namespace Creativestyle\Composer\Patchset;

use Composer\Installer\InstallationManager;
use Composer\Package\PackageInterface;
use Composer\Util\ProcessExecutor;
use Psr\Log\LoggerInterface;

class PatchApplicator
{
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
     * @param string $targetDirectory
     * @param string $patchFile
     * @param int $stripPathComponents
     * @return bool
     */
    private function executePatchCommand($targetDirectory, $patchFile, $stripPathComponents)
    {
        if ($this->hasPatchCommand()) {
            $cmd = ['patch', '--posix', '--strip=' . $stripPathComponents, '--input='.$patchFile,  '--directory='.$targetDirectory];
        } else {
            $cmd = ['patch', '--posix', '--strip=' . $stripPathComponents, '--input='.$patchFile,  '--directory='.$targetDirectory];
        }

        return !$this->executeCommand($cmd);
    }

    public function applyPatch(Patch $patch, PackageInterface $sourcePackage, PackageInterface $targetPackage)
    {
        $targetDirectory = $this->pathResolver->getPackageInstallPath($targetPackage);
        $patchFilename = $this->pathResolver->getPatchSourceFilePath($sourcePackage, $patch);

        if (!$this->executePatchCommand($targetDirectory, $patchFilename, $patch->getStripPathComponents())) {
            throw new \RuntimeException('Could not apply patch: ' . $this->cmdErr);
        }

        $this->logger->notice(sprintf('Applied patch <info>%s:%s</info> [<comment>%s</comment>] (<comment>%s</comment>)',
            $patch->getSourcePackage(),
            $patch->getFilename(),
            $patch->getVersionConstraint(),
            $patch->getDescription()
        ));
    }
}