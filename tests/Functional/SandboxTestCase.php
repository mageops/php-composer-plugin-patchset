<?php

namespace Creativestyle\Composer\Patchset\Tests\Functional;

use Creativestyle\Composer\Patchset\Tests\Functional\Fixtures\ComposerRun;
use PHPUnit\Framework\TestCase;
use Creativestyle\Composer\Patchset\Tests\Functional\Fixtures\ComposerSandbox;

abstract class SandboxTestCase extends TestCase
{
    public static ?ComposerSandbox $sandbox = null;

    public static function setUpBeforeClass(): void
    {
        ComposerSandbox::$debugOutputEnabled = isset($_SERVER['argv']) && in_array('--debug', $_SERVER['argv']);

        static::$sandbox = new ComposerSandbox();
    }

    protected function tearDown(): void
    {
        static::$sandbox->cleanupProjects();
    }

    protected function getSandbox(): ComposerSandbox
    {
        return static::$sandbox;
    }

    protected function assertThatComposerRunWasSuccessful(ComposerRun $composerRun): void
    {
        $this->assertTrue($composerRun->getProject()->hasLockFile(), '`composer.lock` has been created');
        $this->assertTrue($composerRun->getProject()->hasVendorsInstalled(), 'vendors have been installed');
        $this->assertTrue($composerRun->isSuccessful(), sprintf('`composer %s` has been executed succesfully', $composerRun->getComposerCommand()));
    }

    /**
     * Asserts that composer operation has completed and selected files have been applied.
     *
     * Applications should be an array of {relativePathToFileToBePatched} => {patchedVerificationStringToFindInFileContents}.
     *
     * @param ComposerRun $composerRun
     * @param array $expectedApplications
     * @param array $forbiddenApplications
     */
    protected function assertThatComposerRunHasAppliedPatches(
        ComposerRun $composerRun,
        array $expectedApplications,
        array $forbiddenApplications = []
    ) {
        $this->assertThatComposerRunWasSuccessful($composerRun);

        foreach ($expectedApplications as $filePath => $expectedTexts) {
            $this->assertTrue($composerRun->getProject()->hasFile($filePath), sprintf('file %s to be patched exists', $filePath));

            if (!is_array($expectedTexts)) {
                $expectedTexts = [$expectedTexts];
            }

            $fileContents = $composerRun->getProject()->getFileContents($filePath);

            foreach ($expectedTexts as $expectedText) {
                $this->assertContains($expectedText, $fileContents, sprintf('file `%s` has been patched - contains patched in string `%s`', $filePath, $expectedText));
            }
        }

        foreach ($forbiddenApplications as $filePath => $forbiddenTexts) {
            if (!$composerRun->getProject()->hasFile($filePath)) {
                /* It's expected that the file might not exists if patch creates it */
                continue;
            }

            if (!is_array($forbiddenTexts)) {
                $forbiddenTexts = [$forbiddenTexts];
            }

            $fileContents = $composerRun->getProject()->getFileContents($filePath);

            foreach ($forbiddenTexts as $forbiddenText) {
                $this->assertNotContains($forbiddenText, $fileContents, sprintf('file `%s` has not been patched - does not contain patched in string `%s`', $filePath, $forbiddenText));
            }
        }
    }

    public static function tearDownAfterClass(): void
    {
        static::$sandbox->cleanup();
        static::$sandbox = null;
    }
}
