<?php

namespace Creativestyle\Composer\Patchset\Exception;

use Throwable;

class PatchApplicationFailedException extends PatchingException
{
    /**
     * @var string
     */
    private $cmd;

    /**
     * @var string
     */
    private $cmdOutput;

    public function __construct($cmd, $cmdOutput, $message = "", $code = 0, Throwable $previous = null)
    {
        if (empty($message)) {
            $message = "Could not apply patch - command \"$cmd\" failed with: \n$cmdOutput";
        }

        parent::__construct($message, $code, $previous);

        $this->cmd = $cmd;
        $this->cmdOutput = $cmdOutput;
    }

    /**
     * @return string
     */
    public function getCmd()
    {
        return $this->cmd;
    }

    /**
     * @return string
     */
    public function getCmdOutput()
    {
        return $this->cmdOutput;
    }
}