[![Build Status](https://travis-ci.org/creativestyle/composer-plugin-patchset.svg?branch=master)](https://travis-ci.org/creativestyle/composer-plugin-patchset)

Composer Plugin For Applying Patchsets
======================================

This plugin can automatically apply patches to any dependency of your project.

One of the most distinguishing features is that it can apply patches from special composer packages of type `patchset`.
This is quite convenient as you can store all your patches in one repository and apply them automatically on all
systems including developer's machines in a very predictable way.

It's (kind-of) an alternative to two other great plugins (differences will become apparent once you read __Features__):
     
 * [netresearch/composer-patches-plugin](https://github.com/netresearch/composer-patches-plugin)
 * [cweagans/composer-patches](https://github.com/cweagans/composer-patches)
 
 
## Features

 - Apply patches from dedicated composer packages (package your patchset!).
 - Each patch can have a version constraint (composer semver) checked against the target package.
   
   This means that you can (and should) have the patches fail the build if cannot be applied and still
   store patches for multiple package versions in the same patchset.
 - Apply patches using `patch` command and fall-back to `git apply` if not available.
 - Does not reinstall packages unnecessarily.
 - Reinstalls (cleans) packages which are patched but the patches have been removed guaranteeing a consistent
   state after multiple updates.
 - Will repatch packages even if **order** of patches for specific package (version) has changed.
 - Deduplicates patches on package level. 
 - Does not overly tie into composer internals. All patching will be done after the main update/installation
   process at once execution making it simpler and easier to analyze.
   
   This also means that you have the guarantee that the plugin / patches are at the latest version before
   the process even starts. Otherwise it's very tricky (if not impossible) to make the plugin behave
   consistently on the first composer install (e.g. no `vendor` dir at all) and the subsequent ones.
   
   Double composer update/install for build is not necessary.

### What it cannot do 

 This plugin will not download patches from external sources directly (http). I consider this a bad practice and will
 never support it. I won't even comment on downloading patches using unencrypted connection without SHA check. Also what
 if somebody wants to use your software in 2 years and the patches are no longer available?
 
 Also you will not be able to specify patches in any composer package. You have to use a dedicated packages for this 
 purpose. I can hardly imagine a legit use case when it would be desirable that installing package X will automatically 
 patch some other package Y in your project without explicitly being advertised as a patchset.
 
### What is missing

 - Patch the root package / files in root directory
 - Allow to specify patches in the root composer.json



## Running tests

Just start `vendor/bin/phpunit`.
If you want to see the output of commands executed during functional testing use the `--debug` switch:
```
vendor/bin/phpunit --debug
```

It's nice to also add the `--testdox` switch then.
   