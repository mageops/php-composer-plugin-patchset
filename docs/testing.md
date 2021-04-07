# Testing the plugin

## Composer sandbox

This plugin uses a special sandbox which allows it to run composer as a separate process during testing which creates:
- Fake local composer repository in `/repo/packages.json` with all packages defined [package fixtures](/tests/Functional/Fixtures/Packages/).
- Isolated `COMPOSER_HOME` with configuration in `/composer/config.json` that disabled packagist and configures the above repository.
- Project directories in `/project/{test_run}` used for storing dynamically constructed
  project used for testing.

See the [ComposerSandbox](/tests/Functional/Fixtures/ComposerSandbox.php) class for the details.

## Running tests

Just start `vendor/bin/phpunit`.
If you want to see the output of commands executed during functional testing use the `--debug` switch:
```
vendor/bin/phpunit --debug
```

It's nice to also add the `--testdox` switch then.

### Override test sandbox settings via environment variables

#### `COMPOSER_SANDBOX_TMPDIR="{{ sys_get_temp_dir() }}"` - Base temporary dir

_Path to base directory used to store temporary files needed for running the tests._

> **Warning!** Never place `TEST_TMP` dir inside your project's working tree as this
> will cause composer to attempt recursively install its own files and all
> hell breaks loose.
#### `COMPOSER_SANDBOX_PHP="{{ PHP_BIN }}"` - Path to php binary

_Path to php used for running composer._

#### `COMPOSER_SANDBOX_COMPOSER="composer"` - Path to composer

_Path to composer binary to use for running the tests._

#### `COMPOSER_SANDBOX_EXTRA_ARGS=""` - Extra arguments for composer

_String that will be appended to every invocation of composer command._

> **Warning!** Arguments passed through env vars must must be space-delimited
> and each argument cannot contain space characters even if they are escaped.

#### `COMPOSER_SANDBOX_TEST_DEBUG` - Enable extra debugging features

If enabled sandbox directories of failed tests are preserved and composer
command output is flushed for every test.

#### `COMPOSER_SANDBOX_DISABLE_CLEANUP` - Preserve sandbox temp dirs

Do not remove the directory after running a test.
  