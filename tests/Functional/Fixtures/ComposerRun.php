<?php

namespace Creativestyle\Composer\Patchset\Tests\Functional\Fixtures;

class ComposerRun
{
    /**
     * @var string
     */
    private $workingDir;

    /**
     * @var int
     */
    private $returnCode;

    /**
     * @var string
     */
    private $stdOut;

    /**
     * @var string
     */
    private $stdErr;

    /**
     * @var string
     */
    private $fullOut;

    /**
     * The command executed
     *
     * @var string
     */
    private $composerCommand;
    /**
     * @var ProjectSandbox
     */
    private $project;

    /**
     * @param ProjectSandbox $project
     * @param string $composerCommand
     * @param string $workingDir
     * @param int $returnCode
     * @param string $fullOut
     * @param string $stdOut
     * @param string $stdErr
     */
    public function __construct(ProjectSandbox $project, $composerCommand, $workingDir, $returnCode, $fullOut, $stdOut, $stdErr)
    {
        $this->project = $project;
        $this->composerCommand = $composerCommand;
        $this->workingDir = $workingDir;
        $this->returnCode = $returnCode;
        $this->fullOut = $this->stripOutputeDecoration($fullOut);
        $this->stdOut = $this->stripOutputeDecoration($stdOut);
        $this->stdErr = $this->stripOutputeDecoration($stdErr);
    }

    /**
     * @param string
     * @return string
     */
    private function stripOutputeDecoration($buffer)
    {
        return preg_replace("/\033\[[^m]*m/", '', $buffer);
    }

    /**
     * @return string
     */
    public function getWorkingDir()
    {
        return $this->workingDir;
    }

    /**
     * @return int
     */
    public function getReturnCode()
    {
        return $this->returnCode;
    }

    /**
     * @return bool
     */
    public function isSuccessful()
    {
        return 0 === $this->returnCode;

    }

    /**
     * @return bool
     */
    public function hasFailed()
    {
        return 0 !== $this->returnCode;
    }

    /**
     * @return string
     */
    public function getOutput()
    {
        return $this->stdOut;
    }

    /**
     * @return string
     */
    public function getFullOutput()
    {
        return $this->fullOut;
    }

    /**
     * @return string
     */
    public function getErrorOutput()
    {
        return $this->stdErr;
    }

    /**
     * @return bool
     */
    public function hasEmptyErrorOutput()
    {
        return strlen(trim($this->stdErr)) === 0;
    }

    /**
     * @return string
     */
    public function getComposerCommand()
    {
        return $this->composerCommand;
    }

    /**
     * @return ProjectSandbox
     */
    public function getProject()
    {
        return $this->project;
    }
}