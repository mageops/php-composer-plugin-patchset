# Testing the plugin

## Composer sandbox

This plugin uses a special sandbox which allows it to run composer as a separate process during testing.

A fake local packagist repository is created from [package definitions](/tests/Functional/Fixtures/Packages) 
for the purpose of testing by using internally [Satis](https://github.com/composer/satis) as a library.

See the [ComposerSandbox](/tests/Functional/Fixtures/ComposerSandbox.php) class for the details.

## Running tests

Just start `vendor/bin/phpunit`.
If you want to see the output of commands executed during functional testing use the `--debug` switch:
```
vendor/bin/phpunit --debug
```

It's nice to also add the `--testdox` switch then.
   