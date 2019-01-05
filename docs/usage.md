## Usage

### Create *patchset* composer package

### Define patches in your root package

### Applying patches to root package (root project folder)

Define patches as you normally would. If your root package is not named the you should use special
`__root__` as package name.

**CAUTION** Since root package cannot be reinstalled once patches are applied they cannot be removed!
This means that if you want to remove patches applied to root package you should reinstall the whole
project manually. The plugin will warn you when this is the case but will not fail. It will try
to apply any new root package patches though.

__New patches will be applied to the root package but then _application order_ is not guaranteed.__

### Options

#### Strip defined number of components when applying patch (`-pX`)