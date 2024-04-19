<?php

namespace Creativestyle\Composer\Patchset;

use Composer\IO\IOInterface;
use Composer\IO\ConsoleIO;
use Psr\Log\LogLevel;

class IOLogger extends ConsoleIO
{
    const VERBOSITY_LEVEL_MAP = [
        LogLevel::DEBUG => IOInterface::DEBUG,
        LogLevel::INFO => IOInterface::NORMAL,
        LogLevel::NOTICE => IOInterface::QUIET,
        LogLevel::WARNING => IOInterface::QUIET,
        LogLevel::ERROR => IOInterface::QUIET,
        LogLevel::CRITICAL => IOInterface::QUIET,
        LogLevel::ALERT => IOInterface::QUIET,
        LogLevel::EMERGENCY => IOInterface::QUIET
    ];

    const ERROR_LEVELS = [
        LogLevel::ERROR,
        LogLevel::CRITICAL,
        LogLevel::ALERT,
        LogLevel::EMERGENCY,
    ];

    /**
     * @var IOInterface
     */
    private $io;

    /**
     * @param IOInterface $io
     */
    public function __construct(IOInterface $io)
    {
        $this->io = $io;
    }

    /**
     * {@inheritdoc}
     */
    public function log($level, $message, array $context = []): void
    {
        $verbosity = self::VERBOSITY_LEVEL_MAP[$level];

        if (in_array($level, self::ERROR_LEVELS)) {
            $this->io->writeError($message, true, $verbosity);
        } else {
            $this->io->write($message, true, $verbosity);
        }
    }
}