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

    public function testThatPatchesAreNotReappliedOnUpdate()
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

    public function testThatLayeredPatchesAreAppliedInCorrectOrder()
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

    public function testThatPatchesAreDeduplicated()
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
}