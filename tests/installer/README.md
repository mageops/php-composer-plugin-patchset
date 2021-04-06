# Single file, portable composer PHAR installer

Useful for automating composer installation from CLI, CI script or from
an PHP application or a testsuite.

Downloads and verifies the official composer installer script transparently
and allows for easy execution from code or from CLI by passing all args verbatim.


## Usage as CLI script

### Call directly

```shell
php ComposerPharInstaller.php --help
```

### Include or autoload

#### Create a `setup-composer` file...

...using direct include:

```php
#!/usr/bin/env php
<?php include __DIR__ . '/path/to/ComposerPharInstaller.php';
```

...or using the autoloader:

```php
#!/usr/bin/env php
<?php

include __DIR__ . '/../vendor/autoload.php';

use Pinkeen\ComposerPharInstaller\ComposerPharInstaller;
```

> **Note:** The `use` clause is everything you need.

#### Make it executable and then just call it:

```shell
chmod +x ./setup-composer
./setup-composer --help
```

### Disable auto-passthrough 

The `passthru()` is called automatically when CLI interactive script invocation 
is detected. You can prevent it by this define before inclusion:

```php
define('COMPOSER_PHAR_INSTALLER_NO_AUTO_PASSTHRU', 1);
```


## Programmatic usage

These are the basic methods of the `ComposerPharInstaller` class you will need 
most of the time. Take a look at the source for some additionall options.

```php
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
public static function install($version = null, $installDir = null, $filename = null, $force = false);

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
public static function mustInstall($version = null, $installDir = null, $filename = null, $force = false);
```
