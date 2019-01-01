<?php

namespace Creativestyle\Composer\Patchset\Tests\Functional;

class CoreFeatureTest extends SandboxTestCase
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

    const ROOT_PACKAGE_APPLICATIONS = [
        '/src/root-code.php' => '-is-patched'
    ];

    public function testSimplePatchingDuringFirstInstallWorks()
    {
        $project = $this->getSandbox()->createProjectSandBox('test/project-template', 'dev-master', [
            'require' => [
                'test/patchset'=> '~1.0',
                'test/package-a'=> 'dev-master',
                'creativestyle/composer-plugin-patchset'=> 'dev-master'
            ]
        ]);

        $run = $project->runComposerCommand('install');

        $this->assertThatComposerRunHasAppliedPatches($run, self::PACKAGEA_PATCH1_APPLICATIONS);
    }

    public function testPatchingPluginIsInstalledAndExecutedWhenPulledAsPatchsetDependency()
    {
        $project = $this->getSandbox()->createProjectSandBox('test/project-template', 'dev-master', [
            'require' => [
                'test/patchset'=> '~1.0',
                'test/package-a'=> 'dev-master',
            ]
        ]);

        $run = $project->runComposerCommand('install');

        $this->assertThatComposerRunHasAppliedPatches($run, self::PACKAGEA_PATCH1_APPLICATIONS);
    }

    public function testPatchesAreAppliedWhenPackageIsAddedToExistingProject()
    {
        $project = $this->getSandbox()->createProjectSandBox('test/project-template', 'dev-master', [
            'require' => [
                'test/patchset'=> '~1.0',
            ]
        ]);

        $installRun = $project->runComposerCommand('install');

        $this->assertThatComposerRunWasSuccessful($installRun);

        // At this point nothing to patch so no patches shall be applied
        $requireRun = $project->runComposerCommand('require', 'test/package-a', 'dev-master');

        $this->assertThatComposerRunHasAppliedPatches($requireRun, self::PACKAGEA_PATCH1_APPLICATIONS);
    }

    public function testPatchesAreNotReappliedOnUpdate()
    {
        $project = $this->getSandbox()->createProjectSandBox('test/project-template', 'dev-master', [
            'require' => [
                'test/patchset'=> '~1.0',
                'test/package-a'=> 'dev-master',
                'creativestyle/composer-plugin-patchset'=> 'dev-master'
            ]
        ]);

        $installRun = $project->runComposerCommand('install');

        $this->assertThatComposerRunHasAppliedPatches($installRun, self::PACKAGEA_PATCH1_APPLICATIONS);

        $updateRun = $project->runComposerCommand('update');

        $this->assertContains('No patches to apply or clean', $updateRun->getFullOutput(), 'no patches were removed', true);
        $this->assertNotContains('Applied patch', $updateRun->getFullOutput(), 'no patches were applied', true);
    }

    public function testLayeredPatchesAreAppliedInCorrectOrder()
    {
        $project = $this->getSandbox()->createProjectSandBox('test/project-template', 'dev-master', [
            'require' => [
                'test/patchset-extra'=> '~1.0',
                'test/package-a'=> 'dev-master',
                'creativestyle/composer-plugin-patchset'=> 'dev-master'
            ]
        ]);

        $installRun = $project->runComposerCommand('install');

        $this->assertThatComposerRunHasAppliedPatches($installRun,
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

        $project = $this->getSandbox()->createProjectSandBox('test/project-template', 'dev-master', [
            'require' => [
                'test/patchset'=> '~1.0',
                'test/patchset-extra'=> '~1.0',
                'test/package-a'=> 'dev-master',
                'creativestyle/composer-plugin-patchset'=> 'dev-master'
            ]
        ]);

        $installRun = $project->runComposerCommand('install');

        $this->assertThatComposerRunHasAppliedPatches($installRun,
            array_merge_recursive(
                self::PACKAGEA_PATCH1_APPLICATIONS,
                self::PACKAGEA_PATCH2_APPLICATIONS
            )
        );
    }

    public function testRemovingPatchsetWillRemovePatches()
    {
        // Same patch coming from multiple patchsets shall be applied only once

        $project = $this->getSandbox()->createProjectSandBox('test/project-template', 'dev-master', [
            'require' => [
                'test/patchset'=> '~1.0',
                'test/patchset-extra'=> '~1.0',
                'test/package-a'=> 'dev-master',
                'creativestyle/composer-plugin-patchset'=> 'dev-master'
            ]
        ]);

        $installRun = $project->runComposerCommand('install');

        $this->assertThatComposerRunHasAppliedPatches($installRun,
            array_merge_recursive(
                self::PACKAGEA_PATCH1_APPLICATIONS,
                self::PACKAGEA_PATCH2_APPLICATIONS
            )
        );

        $removeRun = $project->runComposerCommand('remove', 'test/patchset-extra');

        $this->assertContains('Reinstalling test/package-a', $removeRun->getFullOutput(), 'test/package-a has been reinstalled', true);

        // First patch is coming from the first patchset so it still should be applied
        $this->assertThatComposerRunHasAppliedPatches($removeRun,
            self::PACKAGEA_PATCH1_APPLICATIONS,
            self::PACKAGEA_PATCH2_APPLICATIONS
        );
    }

    public function testRootPackageCanBePatched()
    {
        $project = $this->getSandbox()->createProjectSandBox('test/project-template', 'dev-master', [
            'require' => [
                'test/patchset-root'=> '~1.0',
            ]
        ]);

        $installRun = $project->runComposerCommand('install');

        $this->assertThatComposerRunHasAppliedPatches($installRun, self::ROOT_PACKAGE_APPLICATIONS);
    }

    public function testRootCanDefinePatches()
    {
        $project = $this->getSandbox()->createProjectSandBox('test/project-template-patchset', 'dev-master', [
            'require' => [
                'test/package-a'=> 'dev-master',
                'creativestyle/composer-plugin-patchset'=> 'dev-master'
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

        $installRun = $project->runComposerCommand('install');
        $this->assertThatComposerRunHasAppliedPatches($installRun, self::PACKAGEA_PATCH1_APPLICATIONS);
    }

    public function testRootCanPatchItself()
    {
        $project = $this->getSandbox()->createProjectSandBox('test/project-template-patchset', 'dev-master', [
            'require' => [
                'test/package-a'=> 'dev-master',
                'creativestyle/composer-plugin-patchset'=> 'dev-master'
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

        $installRun = $project->runComposerCommand('install');
        $this->assertThatComposerRunHasAppliedPatches($installRun, self::ROOT_PACKAGE_APPLICATIONS);
    }

    public function testArbitraryNumberOfPathComponentsCanBeStripped()
    {
        $project = $this->getSandbox()->createProjectSandBox('test/project-template', 'dev-master', [
            'require' => [
                'test/patchset' => '2.0',
                'test/package-a' => 'dev-master',
            ]
        ]);

        $installRun = $project->runComposerCommand('install');

        $this->assertThatComposerRunHasAppliedPatches($installRun,
            array_merge_recursive(
                self::PACKAGEA_PATCH1_APPLICATIONS,
                self::PACKAGEA_PATCH2_APPLICATIONS
            )
        );
    }

    public function testGitApplyCanBeUsedForPatching()
    {
        $project = $this->getSandbox()->createProjectSandBox('test/project-template', 'dev-master', [
            'require' => [
                'test/patchset-git' => '1.0',
                'test/package-a' => 'dev-master',
            ]
        ]);

        $installRun = $project->runComposerCommand('install');

        $this->assertThatComposerRunHasAppliedPatches($installRun,
            array_merge_recursive(
                self::PACKAGEA_PATCH1_APPLICATIONS,
                self::PACKAGEA_PATCH2_APPLICATIONS,
                self::ROOT_PACKAGE_APPLICATIONS
            )
        );

        $this->assertContains('using git method', $installRun->getFullOutput(), 'patches were applied using git', true);
        $this->assertNotContains('using patch method', $installRun->getFullOutput(), 'no patches were applied using patch command', true);
    }
}