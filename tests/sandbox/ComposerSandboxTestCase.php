<?php

namespace Creativestyle\Composer\TestingSandbox;

use PHPUnit\Framework\TestCase;


abstract class ComposerSandboxTestCase extends TestCase
{
    /**
     * @var ComposerSandbox
     */
    private $sandbox;

    /**
     * {@inheritdoc}
     */
    protected function setUp()
    {
        $this->sandbox = null;
    }

    /**
     * {@inheritdoc}
     */
    protected function tearDown()
    {
        /* Leave the sandbox temporary files in place if the test has
           failed so they can be inspected. */
        if (null !== $this->sandbox) {
            $this->sandbox->cleanup();
            fwrite(STDERR, $this->sandbox->flushOutputBuffer());
        }

        $this->sandbox = null;
    }

    /**
     * Returns a unique slug indentifying the current test within the scope
     * of the whole testsuite(s).
     * 
     * @return string
     */
    protected function getComposerSandboxIdentifier() 
    {
        return ltrim( 
            str_replace('\\', '/', get_class($this))  . '/' . $this->getName(),
            '/'
        );
    }

    /**
     * Returns path to package fixtures.
     * 
     * Override this method to configure a custom package fixtures directory.
     * 
     * @return string
     */
    protected function getComposerSandboxFixturesDir()
    {
        return ComposerSandbox::getRootProjectDir() . '/tests/fixtures/packages';
    }

    /**
     * Creates a fresh sandbox for the current test.
     * 
     * Override this method if you need to deeply customize it.
     */
    protected function createComposerSandbox()
    {
        if (isset($_SERVER['argv']) && in_array('--debug', $_SERVER['argv'])) {
            ComposerSandbox::enableDebugOutput();
        }
        
        if (boolval(getenv('COMPOSER_SANDBOX_TEST_DEBUG'))) {
            ComposerSandbox::enableDebugOutput();
        }

        $sb = new ComposerSandbox(
            $this->getComposerSandboxFixturesDir(),
            $this->getComposerSandboxIdentifier()            
        );
        $sb->disableCleanup();

        return $sb;
    }

    /**
     * Get composer sandbox for the current test.
     * 
     * New sandbox is created by calling `self::createComposerSandbox()`
     * when it's called the first time during the test.
     * 
     * @return ComposerSandbox
     */
    protected function getComposerSandbox()
    {
        if (null === $this->sandbox) {
            $this->sandbox = $this->createComposerSandbox();
        }

        return $this->sandbox;
    }

    /**
     * Removes all projects from the current sandbox so it can be reused
     * within the scope of the same test. 
     * 
     * This is rather a rare case and an anti-pattern, but provide in the
     * hope it will be useful.
     */
    protected function cleanComposerSandboxProjects()
    {
        if (null === $this->sandbox) {
            throw new ComposerSandboxSetupException('No projects in sandbox to clean!');
        }

        $this->sandbox->cleanupProjects();
    }

    /**
     * Creates a new sandboxed project for the current test.
     */
    protected function createComposerSandboxProject($packageName, $packageVersion = 'dev-master', array $packageConfig = [])
    {
        return $this->getComposerSandbox()->createProject($packageName, $packageVersion, $packageConfig);
    }

    /**
     * Asserts that the composer command has completed and the project in in working state (in terms of composer setup).
     * 
     * Checks that:
     *  - vendor dir is present
     *  - lockfile is present
     *  - command returned code 0
     * 
     * @param ComposerCommandResult $result
     */
    protected function assertThatComposerCommandResultWasSuccessful(ComposerCommandResult $result)
    {
        $this->assertTrue($result->getProject()->hasLockFile(), '`composer.lock` has been created');
        $this->assertTrue($result->getProject()->hasVendorsInstalled(), 'vendors have been installed');
        $this->assertTrue($result->isSuccessful(), sprintf('`composer %s` has been executed succesfully', $result->getComposerCommand()));
    }

    /**
     * Asserts that composer operation has completed and selected files have been applied.
     *
     * Applications should be an array of {relativePathToFileToBePatched} => {patchedVerificationStringToFindInFileContents}.
     *
     * @param ComposerCommandResult $result
     * @param array $expectedApplications
     * @param array $forbiddenApplications
     */
    protected function assertThatComposerCommandResultHasAppliedPatches(
        ComposerCommandResult $result,
        array $expectedApplications,
        array $forbiddenApplications = []
    ) {
        $this->assertThatComposerCommandResultWasSuccessful($result);

        foreach ($expectedApplications as $filePath => $expectedTexts) {
            $this->assertTrue($result->getProject()->hasFile($filePath), sprintf('file %s to be patched exists', $filePath));

            if (!is_array($expectedTexts)) {
                $expectedTexts = [$expectedTexts];
            }

            $fileContents = $result->getProject()->getFileContents($filePath);

            foreach ($expectedTexts as $expectedText) {
                $this->assertContains($expectedText, $fileContents, sprintf('file `%s` has been patched - contains patched in string `%s`', $filePath, $expectedText));
            }
        }

        foreach ($forbiddenApplications as $filePath => $forbiddenTexts) {
            if (!$result->getProject()->hasFile($filePath)) {
                /* It's expected that the file might not exists if patch creates it */
                continue;
            }

            if (!is_array($forbiddenTexts)) {
                $forbiddenTexts = [$forbiddenTexts];
            }

            $fileContents = $result->getProject()->getFileContents($filePath);

            foreach ($forbiddenTexts as $forbiddenText) {
                $this->assertNotContains($forbiddenText, $fileContents, sprintf('file `%s` has not been patched - does not contain patched in string `%s`', $filePath, $forbiddenText));
            }
        }
    }
}