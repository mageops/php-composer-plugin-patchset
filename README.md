[![Build Status](https://travis-ci.org/creativestyle/composer-plugin-patchset.svg?branch=master)](https://travis-ci.org/creativestyle/composer-plugin-patchset)

Composer Plugin For Applying Patchsets
======================================

This plugin can automatically apply patches to any dependency of your project.

One of the most distinguishing features is that it can apply patches from special composer packages of type `patchset`.
This is quite convenient as you can store all your patches in one repository and apply them automatically on all
systems including developer's machines in a very predictable way. Also you can easily distribute your patchset to the
whole community via packagist.

It's (kind-of) an alternative to two other great plugins (differences will become apparent once you read __Features__):
     
 * [netresearch/composer-patches-plugin](https://github.com/netresearch/composer-patches-plugin)
 * [cweagans/composer-patches](https://github.com/cweagans/composer-patches)
 
 
**Continue reading this document for general description or skip to:**

 * [Usage Documentation](docs/usage.md)
 * [Creating Patches Howto](docs/creating-patches.md)
 * [Testing](docs/testing.md)
 
## Feature comparison table

| Feature                                                   | creativestyle/composer-plugin-patchset    | cweagans/composer-patches 1.x  | netresearch/composer-patches-plugin |
| --------------------------------------------------------- | :---------------------------------------: | :----------------------------: | :---------------------------------: |
| Apply patch collection stored in a composer package       | yes                                       | no                             | no                                  |
| Deduplicate patches                                       | yes                                       | no                             | TBD                                 |
| Guarantees proper application on the first install        | yes                                       | no                             | TBD                                 |
| Full functional test-suite for all features               | yes                                       | no                             | no tests at all                     |
| PHP Version Support confirmed by tests                    | 5.6+                                      | 5.3+                           | no information                      |
| Apply patches directly from remote locations              | no (no support planned)                   | yes                            | yes                                 |
| Specify target package version constraints                | yes                                       | no                             | yes                                 |
| Uninstall removed patches in all cases<sup>1</sup>        | yes                                      | no                             | TBD                                 |
| Reapply package patches if order has changed              | yes                                       | TBD                            | TBD                                 |
| Choose application method (git/patch) per patch           | yes                                       | TBD                            | TBD                                 |
| Apply patches to root package/directory                   | yes                                       | no                             | TBD                                 |

_<sup>1</sup> Root package patching is the exception, please see [applying patches to root package](docs/usage.md#applying-patches-to-root-package-root-project-folder) in usage docs._


## Feature description

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
   
## Use Git for applying patches

By default the library will try to use the `patch` command if not available and fall back to `git` otherwise.
You can force using git for each patch (see: [Usage Documentation](docs/usage.md)).

There were some problems using `git apply` reported in other plugins for packages that were not installed
as source (did not have a git repo; `.git` dir). I was not able to reproduce this problem with git 2.X - however -
a workaround for this potential problem has been implemented:

    - If the target package has `.git` directory, then the patches are applied relative to target package root
    - If no git repo in target package then patches are applied from root project directory
   
## Chicken or egg problem

Patching via composer plugin has one big problem - you cannot catch all events on the first install.
Furthermore applying patches on package install/remove is very error prone as you can never predict
conflicts with other plugins. Therefor gathering and applying patches before everything was actually installed
carries the risk of producing invalid state at the end. This plugin takes a different approach - it performs
all actions at once, after the installation/update was performed, just before autoload dump (in case patching changes it).

This guarantees a consistent state as the plugin compares the current state with the desired one and peforms only 
the actions necessary to get there.

**This has one drawback, if your project uses any composer plugins that copy files from vendors to the root
of your project then you should patch the root package because the patch applications will happen after those
plugins have done their work so patching source files in vendor will have no effect.**


## Why no remote patches

 This plugin will not download patches from external sources directly (http). I consider this a bad practice and will
 never support it. I won't even comment on downloading patches using unencrypted connection without SHA check. Also what
 if somebody wants to use your software in 2 years and the patches are no longer available?
 
 Also you will not be able to specify patches in any composer package. You have to use a dedicated packages for this 
 purpose. I can hardly imagine a legit use case when it would be desirable that installing package X will automatically 
 patch some other package Y in your project without explicitly being advertised as a patchset.





