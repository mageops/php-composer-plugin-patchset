<?php

namespace Pinkeen\ComposerPharInstaller;

final class ComposerPharInstallationError extends \ErrorException
{
    public static function handleUserErrors($callback) 
    {
        $errors = [];
        
        set_error_handler(function (...$args) use (&$errors) { $errors[] = $args; }, E_USER_NOTICE | E_USER_ERROR);

        $return = @$callback();

        restore_error_handler();

        $code = is_int($return) && $return > 0 ? intval($return) : 0;

        if (!empty($errors)) {
            array_reverse($errors);

            $prevException = null;

            foreach ($errors as $error) {
                list($severity, $message, $filename, $lineno) = $error;
                $prevException = new static($message, $code, $severity, $filename, $lineno, $prevException);
            }

            throw $prevException;
        }

        return $return;
    }
}

final class ComposerPharInstaller
{
    const INSTALLER_URL = 'https://getcomposer.org/installer';
    const INSTALLER_SIG_URL = 'https://composer.github.io/installer.sig';
    const COMPOSER_HOME_ENV = 'COMPOSER_HOME';

    private static $composerHome = null;
    private static $installer = null;
    private static $instance = null;
    private static $verbose = false;

    /**
     * @return string
     */
    private static function lastError()
    {
        if (null === $err = error_get_last()) {
            return 'unknown error';
        }

        error_clear_last();

        return sprintf("%s (%s on line %d)", $err['message'], $err['file'], $err['line']);
    }

    /**
     * @return bool
     */
    private static function isTTY()
    {
        if (!defined('STDOUT')) {
            return false;
        }

        if (\function_exists('stream_isatty')) {
            return stream_isatty(STDOUT);
        }
        
        return posix_isatty(STDOUT);
    }

    /**
     * Removes the temporary installer file if it was succesfully downloaded.
     */
    private static function cleanup() 
    {
        if (!self::$installer) {
            return;
        }

        if (false === @unlink(self::$installer)) {
            trigger_error(sprintf("Failed to unlink installer file: %s", self::$installer), E_WARNING);
        } elseif (self::$verbose) {
            fprintf(STDERR, "Deleted cached installer file: %s \n", self::$installer);
        }

        self::$installer = null;
    }

    /**
     * @return array|null
     */
    private static function argv() 
    {
        if (!isset($argv) && isset($_SERVER['argv'])) {
            $argv = $_SERVER['argv'];
        } 
    
        if (!isset($argv) || !is_array($argv) || empty($argv)) {
            trigger_error("Could not determine script arguments");

            return null;
        }

        return $argv;
    }

    private static function createCommandString(...$elements)
    {
        $elements = array_map('trim', $elements);
        $elements = array_filter($elements, function($el) { return strlen($el); });
        return implode(' ', array_map('escapeshellarg', $elements));
    }

    /**
     * @param array|string $args
     * @param string $version
     * @param string $installDir
     * @param string $filename
     * @param string $force
     * @return string[]
     */
    private static function createCommandArgs($args = [], $version = null, $installDir = null, $filename = null, $force = false)
    {
        if (is_array($args)) {
            $args = self::createCommandString(...$args);
        }

        $has = function($arg) use ($args) { return 0 === stripos($args, $arg); };
        
        $extra = [];

        if (null !== $version && !$has('--version')) {
            if (in_array($version, ['1', '~1', '^1']) && !$has('--1')) {
                $extra[] = '--1';
            } elseif (in_array($version, ['2', '~2', '^2']) && !$has('--2')) {
                $extra[] = '--2';
            } else {
                $extra[] = "--version=$version";
            }
        }

        if (null !== $filename && !$has('--filename')) {
            $extra[] = "--filename=$filename";
        }

        if (null !== $installDir && !$has('--install-dir')) {
            $extra[] = "--install-dir=$installDir";
        }

        if ($force && !$has('--force')) {
            $extra[] = "--force";
        }        
            
        return trim($args . ' ' . self::createCommandString(...$extra));
    }

    /**
     * @param string $argstring
     * @param bool $passthru
     * @return int
     */
    private static function doInstall($argstring, $passthru = false) 
    {
        if (self::$installer && file_exists(self::$installer)) {
            printf(STDERR, "Using cached installer script: %s \n", self::$installer);
            $installer = self::$installer;
        } else {
            $installer = tempnam(sys_get_temp_dir(), 'composer-setup-') . '.php';
        
            if (self::$verbose) {
                fprintf(STDERR, "Starting installer script download: %s -> %s \n", self::INSTALLER_URL, $installer);
            }

            if (false == @copy(self::INSTALLER_URL, $installer)) {
                trigger_error(sprintf("Failed to download composer installer from %s to %s:\n %s", 
                    self::INSTALLER_URL, 
                    $installer, 
                    self::lastError()
                ));

                goto failure;
            }

            if (false === $sig = @file_get_contents(self::INSTALLER_SIG_URL)) {
                trigger_error(sprintf(
                    "Failed to download composer signature from %s:\n %s", 
                    self::INSTALLER_SIG_URL,
                    self::lastError()
                ));

                goto failure;
            }

            if (self::$verbose) {
                fprintf(STDERR, "Downloaded installer signature from: %s == %s \n", self::INSTALLER_SIG_URL, $sig);
            }

            if ($sig !== $hash = hash_file('sha384', $installer)) {
                trigger_error(sprintf(
                    "Composer installer signature verification mismatch - %s != %s", $sig, $hash
                ));

                goto failure;
            }

            if (self::$verbose) {
                fprintf(STDERR, "Installer file signature verification is a match! \n");
            }
        }

        /* Hack so destructor is called for cleanup without having
           to fiddle with shutdown handler. */
        self::$instance = new self();
        self::$installer = $installer;
        
        if (self::$composerHome) {
            if (self::$verbose) {
                fprintf(STDERR, "Set COMPOSER_HOME=%s \n", self::$composerHome);
            }

            $oldComposerHome = getenv(self::COMPOSER_HOME_ENV);
            putenv(self::COMPOSER_HOME_ENV . '=' . self::$composerHome);
        }

        $cmd = trim(self::createCommandString(PHP_BINARY, $installer) . ' ' . $argstring);

        if (self::$verbose) {
            fprintf(STDERR, "Execute composer installer command: %s \n", $cmd);
        }

        if ($passthru) {
            if (self::$verbose) {
                fprintf(STDERR, "...in passthrough mode.\n", $cmd);
            }

            @passthru($cmd, $code);
        } else {
            @exec($cmd, $out, $code);
        }

        if (self::$composerHome) {
            if ($oldComposerHome) {
                putenv(self::COMPOSER_HOME_ENV . '=' .  $oldComposerHome);
            } else {
                putenv(self::COMPOSER_HOME_ENV);
            }
        }

        if ($code !== 0) {
            trigger_error(sprintf(
                "Composer installer failed (code: %d): %s: %s", 
                $code, 
                $cmd, 
                implode("\n", $out)
            ));

            goto failure;
        }

        if (self::$verbose) {
            fprintf(STDERR, "Composer installer exited successfully! \n");
        }

        return 0;

        failure:
            self::cleanup();

            if (isset($code) && $code != 0) {
                return $code;
            }

            return 99;
    }

    /**
     * Should be called on program termination for a dummy singleton instance.
     */
    public function __destruct() 
    {
        self::cleanup();
    }

    /**
     * Executes callback setting custom error reporting and display.
     */
    private static function withErrorReporting($callback, $level = ~E_ALL & (E_ERROR | E_USER_ERROR | E_USER_NOTICE))
    {
        $displayErrors = @ini_set('display_errors', 0);
        $errorReporting = @error_reporting($level);

        $return = $callback();

        @error_reporting($errorReporting);

        if (false !== $displayErrors) {
            @ini_set('display_errors', $displayErrors);
        }

        return $return;
    }

    /**
     * In case of errors an E_USER_NOTICE will be triggered and positive number indicating error code returned.
     * 
     * You can silence it with @self::install() and get the error message with error_get_last() or setup and error handler.
     * 
     * @param string|null $version Specific version to install. Special values (exact) '1' and '2' install the latest v1 and v2 respectively.
     * @param string|null $installDir Target installation directory.
     * @param string|null $filename Name of the installed command.
     * @param string|null $force Force installation.
     * @param string|null $args Extra args to pass to composer setup command.
     * @return bool Returns true on success.
     */
    public static function install($version = null, $installDir = null, $filename = null, $force = false, $args = []) 
    {
        $argstring = self::createCommandArgs($args, ...func_get_args());

        return 0 !== self::withErrorReporting(
            function() use($argstring) { return @self::doInstall($argstring); }
        );
    }

    /**
     * Same as the install method but never returns on error throwin an exception instead.
     * 
     * @see self::install()
     * 
     * @param string $version
     * @param string $installDir
     * @param string $filename
     * @param string $force
     * @param string $args
     * @return void
     * 
     * @throws ComposerPharInstallationError
     */
    public static function mustInstall($version = null, $installDir = null, $filename = null, $force = false, $args = []) 
    {
        $argstring = self::createCommandArgs($args, ...func_get_args());

        return ComposerPharInstallationError::handleUserErrors(
            function() use($argstring) { return @self::doInstall($argstring); }
        );
    }

    /**
     * Override the composer home directory path passed by env var to the installer.
     * 
     * @param string $composerHome Path to composer home directory.
     */
    public static function setComposerHome($composerHome)
    {
        self::$composerHome = $composerHome;
    }


    /**
     * Extra verbose logging to stderr
     */
    public static function enableVerboseOutput()
    {
        self::$verbose = true;
    }

    /**
     * Returns true if running in interactive CLI SAPI.
     * 
     * @return bool
     */
    public static function interactive()
    {
        return defined('STDIN') && function_exists('php_sapi_name') && php_sapi_name() === 'cli';
    }

    /**
     * Reads CLI script invocation arguments and passes them to composer installer verbatim.
     * 
     * Never returns but exits the script with appropriate error code.
     * 
     * @return void
     */
    public static function passthru()
    {
        if (!self::interactive()) {
            trigger_error(sprintf("Method %s:%s must be invoked from interactive CLI", self::class, __FUNCTION__), E_USER_ERROR);
        }

        if (null !== $argv = @self::argv()) {
            $argv = array_slice($argv, 1);
        } else {
            $argv = func_get_args();
        }

        if (self::isTTY()) {
            array_unshift($argv, '--ansi');
        }

        $argstring = self::createCommandArgs($argv);

        try {
            exit(ComposerPharInstallationError::handleUserErrors(function() use ($argstring) {
                return @self::doInstall($argstring, true);
            }));
        } catch (ComposerPharInstallationError $exception) {
            fwrite(STDERR, sprintf("\e[1;31mFatal:\e[0;31m Composer installation failed with code %d!\n\e[1;31mError:\e[0m %s\n", $exception->getCode(), $exception->getMessage()));
            exit($exception->getCode());
        }
    }

    /**
     * Returns true if being invoked as script or included from a cli-invoked script.
     * 
     * @return bool
     */
    public static function script()
    {
        if (null === $argv = @self::argv()) {
            return false;
        }

        $scriptPath = isset($_SERVER["SCRIPT_FILENAME"]) 
            ? $_SERVER["SCRIPT_FILENAME"] 
            : __FILE__;

        return realpath($argv[0]) === realpath($scriptPath);
    }
}

if (!defined('COMPOSER_PHAR_INSTALLER_NO_AUTO_PASSTHRU')) {
    // If invoked or included directly as an interactive CLI script just passthrough args verbatim and exit on errors
    if (ComposerPharInstaller::script() && ComposerPharInstaller::interactive()) {
        if (getenv('COMPOSER_PHAR_INSTALLER_DEBUG')) {
            ComposerPharInstaller::enableVerboseOutput();
        }
        
        ComposerPharInstaller::passthru();
    }
}


