# Basic Usage

There are two ways to specify and store patches, you can use any of them or both.

## Create *patchset* composer package

_Use this way to easily distribute your patchset and use it in multiple projects_

 1. Create a new composer package and change the package type in `composer.json` to `patchset` 
   so it looks like this:
   
   ```json
    {
        "name": "your-vendor/your-patchset-name",
        "type": "patchset",
        "version": "1.0",
        "require": {
            "creativestyle/composer-plugin-patchset": "^2.0"
        }
    } 
   ```
   
 2. Place your diff patch files in any directories inside the package you want
 3. Define the patches in `composer.json` like this:
   
   ```json
    {
        "name": "your-vendor/your-patchset-name",
        "type": "patchset",
        "version": "1.0",
        "require": {
            "creativestyle/composer-plugin-patchset": "^1.0"
        },
        "extra": {
            "patchset": {
                "some-vendor-a/package-to-patch": [
                    {
                        "description": "Short patch description that is mandatory",
                        "filename": "path/to/the-patch-file-relative-to-patchset-root.diff"
                    },
                    {
                        "description": "Patches with no version specified will be always applied",
                        "filename": "second-patch-for-the-same-package.diff"
                    }
                ],
                "some-vendor-b/package-to-patch": [
                    {
                        "description": "Patch some other package",
                        "filename": "patches/your-other-package-patch.diff",
                    }
                ]
            }
        }
    }
   ```   

## Define patches in your root package

Apart from defining patches in a `patchset` type package, you can also do that in your main `composer.json`.

Just add the `"extra": {"patchset": {}}` configuration the same way as in a patchset package.

## Skipping selected patches

In some cases, you want to skip some patches from applying (for example when you use centralized patches repository, but when you don't know want to apply specific patch(es) for one project. You can do it by adding `patchset-ignore` node in `extra` section:

```json

"extra": {
        "patchset-ignore": [
            "patches/patch-that-you-want-to-ignore.patch"
        ],
        "patchset": {
            "some-vendor-a/package-to-patch": [
            ...
        ]
        ...
```


# JSON configuration reference 

## Patch properties

#### `description` (string, mandatory)

Short description (or name) of your patch. Keep it short as it will be displayed during patching.

#### `filename` (string, mandatory)

Path to your diff file relative to the root of your patchset. Patches can be stored anywhere within
the package.

#### `version-constraint` (string, default: `"*"`)

A composer semver version constraint. If specified then the patch application will be attempted 
only if the target package's version matches the constraint.

See [composer documenation](https://getcomposer.org/doc/articles/versions.md) for exact syntax reference.

#### `strip-path-components` (integer, default: `1`)

How many leading path components to strip from filenames in diff when applying the patch.

This has the same effect as the `-p{x}` switch of the patch command, where `{x}` is the value of this parameter.

For 99% of the patches this will be the default `1`. Set this only if your patch was generated in some non-standard way.

#### `method` (string, default: `"patch"`)

There are two methods of application: `patch` and `git`.

By default the library will attempt to use the `patch` binary and fall back to `git apply` if not available.

If you want to force using `git apply` for patches that are compatible only with `git` you can do so
with this parameter on patch level.

#### `keep-empty-files` (bool, default: `false`)

_Warning!_ This setting has effect only when using the `patch` method.

By default the empty files will be removed by passing `--remove-empty-files` switch to the `patch` command.

This allows to create patches which remove files.

If you want to override this behaviour per-patch set this parameter to `true`.

# Notable use-cases

## Applying patches to root package (root project folder)

Define patches as you normally would. If your root package is not named the you should use special
`__root__` as package name.

**CAUTION!** Since root package cannot be reinstalled once patches are applied they cannot be removed!
This means that if you want to remove patches applied to root package you should reinstall the whole
project manually. The plugin will warn you when this is the case but will not fail. It will try
to apply any new root package patches though.

__New patches will be applied to the root package but then _application order_ is not guaranteed.__

