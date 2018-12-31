<?php

namespace Creativestyle\Composer\Patchset;

use Composer\Installer\InstallationManager;
use Composer\Package\AliasPackage;
use Composer\Package\PackageInterface;
use Composer\Repository\RepositoryInterface;
use Psr\Log\LoggerInterface;

class PackageApplicationRepository
{
    /**
     * @var RepositoryInterface
     */
    private $installedRepository;

    /**
     * @var InstallationManager
     */
    private $installationManager;
    /**
     * @var PathResolver
     */
    private $pathResolver;
    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param RepositoryInterface $installedRepository
     * @param InstallationManager $installationManager
     * @param PathResolver $pathResolver
     * @param LoggerInterface $logger
     */
    public function __construct(
        RepositoryInterface $installedRepository,
        InstallationManager $installationManager,
        PathResolver $pathResolver,
        LoggerInterface $logger
    ) {
        $this->installedRepository = $installedRepository;
        $this->installationManager = $installationManager;
        $this->pathResolver = $pathResolver;
        $this->logger = $logger;
    }

    /**
     * @return PackagePatchApplication[]
     */
    public function getPackageApplications()
    {
        $applications = [];

        foreach ($this->installedRepository->getPackages() as $package) {
            if ($package instanceof AliasPackage) {
                continue;
            }

            if (null !== $application = $this->getPackageApplication($package)) {
                $applications[$package->getName()] = $application;
            }
        }

        return $applications;
    }

    /**
     * @param PackageInterface $targetPackage
     * @return PackagePatchApplication
     */
    public function getPackageApplication(PackageInterface $targetPackage)
    {
        $dataFile = $this->pathResolver->getPackageApplicationFilename($targetPackage);

        if (!file_exists($dataFile)) {
            return null;
        }

        if (!is_readable($dataFile)) {
            throw new \RuntimeException('Cannot read applied patches data file "%s"', $dataFile);
        }

        $data = json_decode(file_get_contents($dataFile), true);

        return $this->createPackagePatchApplication($targetPackage, $data);
    }

    /**
     * @param PackagePatchApplication $packagePatchApplication
     */
    public function savePackageApplication(PackagePatchApplication $packagePatchApplication)
    {
        $targetPackage = $packagePatchApplication->getTargetPackage();
        $dataFile = $this->pathResolver->getPackageApplicationFilename($targetPackage);

        if (file_exists($dataFile)) {
            if (!is_writable($dataFile)) {
                throw new \RuntimeException(sprintf('Cannot write applied patches data file "%s"', $dataFile));
            }
        } elseif (!is_writable(dirname($dataFile))) {
            throw new \RuntimeException(sprintf('Package directory is not writable "%s"', dirname($dataFile)));
        }

        file_put_contents($dataFile,
            $this->encodeData($this->transformPackagePatchApplicationToArray($packagePatchApplication))
        );
    }

    /**
     * @param array $data
     * @return string
     */
    private function encodeData(array $data)
    {
        return json_encode($data,
            JSON_PRETTY_PRINT + JSON_UNESCAPED_SLASHES + JSON_UNESCAPED_UNICODE
        );
    }

    /**
     * @param PackageInterface $targetPackage
     * @param array $data
     * @return PackagePatchApplication
     */
    private function createPackagePatchApplication(PackageInterface $targetPackage, array $data)
    {
        return new PackagePatchApplication($targetPackage,
            array_map([$this, 'createPatchApplication'], $data['patches'])
        );
    }

    /**
     * @param array $data
     * @return PatchApplication
     */
    private function createPatchApplication(array $data)
    {
        $patch = Patch::createFromArray($data['patch']);

        $sourcePackage = $this->installedRepository->findPackage(
            $data['source_package']['name'],
            $data['source_package']['version']
        );

        if (!$sourcePackage) {
            $this->logger->debug(sprintf('Could not find source package %s (%s) for installed patch, it was removed probably',
                $data['source_package']['name'],
                $data['source_package']['version']
            ));
        }

        $targetPackage = $this->installedRepository->findPackage(
            $data['target_package']['name'],
            $data['target_package']['version']
        );

        if (!$targetPackage) {
            throw new \RuntimeException(sprintf('Could not find target package %s (%s) for installed patch',
                $data['target_package']['name'],
                $data['target_package']['version']
            ));
        }

        return new PatchApplication($patch, $sourcePackage, $targetPackage, $data['hash']);
    }

    /**
     * @param PackagePatchApplication $packageApplication
     * @return array
     */
    public function transformPackagePatchApplicationToArray(PackagePatchApplication $packageApplication)
    {
        return [
            'hash' => $packageApplication->getHash(),
            'patches' => array_map(
                [$this, 'transformPatchApplicationToArray'],
                $packageApplication->getApplications()
            )
        ];
    }

    /**
     * @param PatchApplication $application
     * @return array
     */
    public function transformPatchApplicationToArray(PatchApplication $application)
    {
        return [
            'hash' => $application->getHash(),
            'target_package' => [
                'name' => $application->getTargetPackage()->getName(),
                'version' => $application->getTargetPackage()->getVersion(),
                'ref' => $application->getTargetPackage()->getSourceReference(),
            ],
            'source_package' => [
                'name' => $application->getSourcePackage()->getName(),
                'version' => $application->getSourcePackage()->getVersion(),
                'ref' => $application->getSourcePackage()->getSourceReference(),
            ],
            'patch' => $application->getPatch()->toArray()
        ];
    }
}