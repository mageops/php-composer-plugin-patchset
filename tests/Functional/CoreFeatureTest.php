<?php

namespace Creativestyle\Composer\Patchset\Tests\Functional;

use Creativestyle\Composer\TestingSandbox\ComposerSandbox;
use Creativestyle\Composer\TestingSandbox\ComposerSandboxTestCase;

class CoreFeatureTest extends ComposerSandboxTestCase
{
    const PACKAGEA_PATCH1_APPLICATIONS = [
        '/vendor/test/package-a/src/test.php' => 'patched-in-echo'
    ];

    const PACKAGEA_PATCH2_APPLICATIONS = [
        '/vendor/test/package-a/src/test.php' => [
            'layered-patch-line-1',
            'layered-patch-line-2',
            'layered-patch-line-3'
        ]
    ];

    const PACKAGEA_NON_POSIX_PATCHSET_APPLICATIONS = [
        '/vendor/test/package-a/file-added-by-patch.md' => 'This file has been created by patch'
    ];

    const ROOT_PACKAGE_APPLICATIONS = [
        '/src/root-code.php' => '-is-patched'
    ];

    public function testSimplePatchingDuringFirstInstallWorks()
    {
        $project = $this->createComposerSandboxProject('test/project-template', '*', [
            'require' => [
                'test/patchset' => '~1.0',
                'test/package-a' => '@dev',
                'creativestyle/composer-plugin-patchset' => ComposerSandbox::getSelfPackageVersion()
            ]
        ]);

        $run = $project->runComposerCommand('install');

        $this->assertThatComposerCommandResultHasAppliedPatches($run, self::PACKAGEA_PATCH1_APPLICATIONS);
    }

    public function testPatchingPluginIsInstalledAndExecutedWhenPulledAsPatchsetDependency()
    {
        $project = $this->createComposerSandboxProject('test/project-template', '*', [
            'require' => [
                'test/patchset' => '~1.0',
                'test/package-a' => '@dev',
            ]
        ]);

        $run = $project->runComposerCommand('update');

        $this->assertThatComposerCommandResultHasAppliedPatches($run, self::PACKAGEA_PATCH1_APPLICATIONS);
    }

    public function testPatchesAreAppliedWhenPackageIsAddedToExistingProject()
    {
        $project = $this->createComposerSandboxProject('test/project-template', '*', [
            'require' => [
                'test/patchset' => '~1.0',
            ]
        ]);

        $installRun = $project->runComposerCommand('install');

        $this->assertThatComposerCommandResultWasSuccessful($installRun);

        // At this point nothing to patch so no patches shall be applied
        $requireRun = $project->runComposerCommand('require', 'test/package-a', '*');

        $this->assertThatComposerCommandResultHasAppliedPatches($requireRun, self::PACKAGEA_PATCH1_APPLICATIONS);
    }

    public function testPatchesAreNotReappliedOnUpdate()
    {
        $project = $this->createComposerSandboxProject('test/project-template', '*', [
            'require' => [
                'test/patchset' => '~1.0',
                'test/package-a' => '@dev',
                'creativestyle/composer-plugin-patchset' => ComposerSandbox::getSelfPackageVersion()
            ]
        ]);

        $installRun = $project->runComposerCommand('install');

        $this->assertThatComposerCommandResultHasAppliedPatches($installRun, self::PACKAGEA_PATCH1_APPLICATIONS);

        $updateRun = $project->runComposerCommand('update');

        $this->assertContains('No patches to apply or clean', $updateRun->getFullOutput(), 'no patches were removed', true);
        $this->assertNotContains('Applied patch', $updateRun->getFullOutput(), 'no patches were applied', true);
    }

    public function testLayeredPatchesAreAppliedInCorrectOrder()
    {
        $project = $this->createComposerSandboxProject('test/project-template', '*', [
            'require' => [
                'test/patchset-extra' => '~1.0',
                'test/package-a' => '@dev',
                'creativestyle/composer-plugin-patchset' => ComposerSandbox::getSelfPackageVersion()
            ]
        ]);

        $installRun = $project->runComposerCommand('install');

        $this->assertThatComposerCommandResultHasAppliedPatches($installRun,
            array_merge_recursive(
                self::PACKAGEA_PATCH1_APPLICATIONS,
                self::PACKAGEA_PATCH2_APPLICATIONS
            )
        );

        $updateRun = $project->runComposerCommand('update');

        $this->assertContains('No patches to apply or clean', $updateRun->getFullOutput(), 'no patches were removed', true);
        $this->assertNotContains('Applied patch', $updateRun->getFullOutput(), 'no patches were applied', true);
    }

    public function testPatchesAreDeduplicated()
    {
        // Same patch coming from multiple patchsets shall be applied only once

        $project = $this->createComposerSandboxProject('test/project-template', '*', [
            'require' => [
                'test/patchset' => '~1.0',
                'test/patchset-extra' => '~1.0',
                'test/package-a' => '@dev',
                'creativestyle/composer-plugin-patchset' => ComposerSandbox::getSelfPackageVersion()
            ]
        ]);

        $installRun = $project->runComposerCommand('update');

        $this->assertThatComposerCommandResultHasAppliedPatches($installRun,
            array_merge_recursive(
                self::PACKAGEA_PATCH1_APPLICATIONS,
                self::PACKAGEA_PATCH2_APPLICATIONS
            )
        );
    }

    public function testRemovingPatchsetWillRemovePatches()
    {
        // Same patch coming from multiple patchsets shall be applied only once

        $project = $this->createComposerSandboxProject('test/project-template', '*', [
            'require' => [
                'test/patchset' => '~1.0',
                'test/patchset-extra' => '~1.0',
                'test/package-a' => '@dev',
                'creativestyle/composer-plugin-patchset' => ComposerSandbox::getSelfPackageVersion()
            ]
        ]);

        $installRun = $project->runComposerCommand('update');

        $this->assertThatComposerCommandResultHasAppliedPatches($installRun,
            array_merge_recursive(
                self::PACKAGEA_PATCH1_APPLICATIONS,
                self::PACKAGEA_PATCH2_APPLICATIONS
            )
        );

        $removeRun = $project->runComposerCommand('remove', 'test/patchset-extra');

        $this->assertContains('Reinstalling test/package-a', $removeRun->getFullOutput(), 'test/package-a has been reinstalled', true);

        // First patch is coming from the first patchset so it still should be applied
        $this->assertThatComposerCommandResultHasAppliedPatches($removeRun,
            self::PACKAGEA_PATCH1_APPLICATIONS,
            self::PACKAGEA_PATCH2_APPLICATIONS
        );
    }

    public function testRootPackageCanBePatched()
    {
        $project = $this->createComposerSandboxProject('test/project-template', '*', [
            'require' => [
                'test/patchset-root' => '~1.0',
            ]
        ]);

        $installRun = $project->runComposerCommand('update');

        $this->assertThatComposerCommandResultHasAppliedPatches($installRun, self::ROOT_PACKAGE_APPLICATIONS);
    }

    public function testRootCanDefinePatches()
    {
        $project = $this->createComposerSandboxProject('test/project-template-patchset', '*', [
            'require' => [
                'test/package-a' => '@dev',
                'creativestyle/composer-plugin-patchset' => ComposerSandbox::getSelfPackageVersion()
            ],
            'extra' => [
                'patchset' => [
                    'test/package-a' => [
                        [
                            'description' => 'Patch in echo in the middle',
                            'filename' => 'patches/package-a-patch-1.diff'
                        ]
                    ]
                ]
            ]
        ]);

        $installRun = $project->runComposerCommand('update');
        $this->assertThatComposerCommandResultHasAppliedPatches($installRun, self::PACKAGEA_PATCH1_APPLICATIONS);
    }

    public function testRootCanPatchItself()
    {
        $project = $this->createComposerSandboxProject('test/project-template-patchset', '*', [
            'require' => [
                'test/package-a' => '@dev',
                'creativestyle/composer-plugin-patchset' => ComposerSandbox::getSelfPackageVersion()
            ],
            'extra' => [
                'patchset' => [
                    'test/project-template-patchset' => [
                        [
                            'description' => 'Patch project root',
                            'filename' => 'patches/root-project-patch-1.diff'
                        ]
                    ]
                ]
            ]
        ]);

        $installRun = $project->runComposerCommand('update');
        $this->assertThatComposerCommandResultHasAppliedPatches($installRun, self::ROOT_PACKAGE_APPLICATIONS);
    }

    public function testArbitraryNumberOfPathComponentsCanBeStripped()
    {
        $project = $this->createComposerSandboxProject('test/project-template', '*', [
            'require' => [
                'test/patchset' => '2.0',
                'test/package-a' => '@dev',
            ]
        ]);

        $installRun = $project->runComposerCommand('update');

        $this->assertThatComposerCommandResultHasAppliedPatches($installRun,
            array_merge_recursive(
                self::PACKAGEA_PATCH1_APPLICATIONS,
                self::PACKAGEA_PATCH2_APPLICATIONS
            )
        );
    }

    public function testGitApplyCanBeUsedForPatching()
    {
        $project = $this->createComposerSandboxProject('test/project-template', '*', [
            'require' => [
                'test/patchset-git' => '1.0',
                'test/package-a' => '@dev',
            ]
        ]);

        $installRun = $project->runComposerCommand('update');

        $this->assertThatComposerCommandResultHasAppliedPatches($installRun,
            array_merge_recursive(
                self::PACKAGEA_PATCH1_APPLICATIONS,
                self::PACKAGEA_PATCH2_APPLICATIONS,
                self::ROOT_PACKAGE_APPLICATIONS
            )
        );

        $this->assertContains('using git method', $installRun->getFullOutput(), 'patches were applied using git', true);
        $this->assertNotContains('using patch method', $installRun->getFullOutput(), 'no patches were applied using patch command', true);
    }

    public function testPatchesCanCreateNewFiles()
    {
        $project = $this->createComposerSandboxProject('test/project-template', '*', [
            'require' => [
                'test/patchset-non-posix' => '~1.0',
                'test/package-a' => '@dev',
                'creativestyle/composer-plugin-patchset' => ComposerSandbox::getSelfPackageVersion()
            ]
        ]);

        $run = $project->runComposerCommand('update');

        $this->assertThatComposerCommandResultHasAppliedPatches($run, self::PACKAGEA_NON_POSIX_PATCHSET_APPLICATIONS);
    }
}