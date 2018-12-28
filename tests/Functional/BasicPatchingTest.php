<?php

namespace Creativestyle\Composer\Patchset\Tests\Functional;

class BasicPatchingTest extends SandboxTestCase
{
    public function testSimplePatchingDuringFirstInstallWorks()
    {
        $project = $this->getSandbox()->createProjectSandBox('test/project-template', 'dev-master', [
            'require' => [
                'test/patchset'=> '~1.0',
                'test/simple-package'=> 'dev-master',
                'creativestyle/composer-plugin-patchset'=> 'dev-master'
            ]
        ]);

        $run = $project->runComposerCommand('install');

        $this->assertThatComposerRunHasAppliedPatches($run, [
            '/vendor/test/simple-package/src/test.php' => 'patched-in-echo'
        ]);
    }

    public function testPatchingPluginIsInstalledAndExecutedWhenPulledAsPatchsetDependency()
    {
        $project = $this->getSandbox()->createProjectSandBox('test/project-template', 'dev-master', [
            'require' => [
                'test/patchset'=> '~1.0',
                'test/simple-package'=> 'dev-master',
            ]
        ]);

        $run = $project->runComposerCommand('install');

        $this->assertThatComposerRunHasAppliedPatches($run, [
            '/vendor/test/simple-package/src/test.php' => 'patched-in-echo'
        ]);
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
        $requireRun = $project->runComposerCommand('require', 'test/simple-package', 'dev-master');

        $this->assertThatComposerRunHasAppliedPatches($requireRun, [
            '/vendor/test/simple-package/src/test.php' => 'patched-in-echo'
        ]);
    }
}