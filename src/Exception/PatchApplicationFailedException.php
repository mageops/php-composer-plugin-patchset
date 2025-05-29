<?php

namespace Creativestyle\Composer\Patchset\Exception;

use Throwable;

class PatchApplicationFailedException extends PatchingException
{
    private string $cmd;
    private string $cmdOutput;

    public function __construct(string $cmd, string $cmdOutput, string $message = "", int $code = 0, ?Throwable $previous = null)
    {
        if (empty($message)) {
            $message = "Could not apply patch - command \"$cmd\" failed with: \n$cmdOutput";
        }

        parent::__construct($message, $code, $previous);

        $this->cmd = $cmd;
        $this->cmdOutput = $cmdOutput;
    }

    public function getCmd(): string
    {
        return $this->cmd;
    }

    public function getCmdOutput(): string
    {
        return $this->cmdOutput;
    }
}
