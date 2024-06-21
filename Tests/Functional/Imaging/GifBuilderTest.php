<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

namespace TYPO3\CMS\Frontend\Tests\Functional\Imaging;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;
use TYPO3\CMS\Frontend\Controller\TypoScriptFrontendController;
use TYPO3\CMS\Frontend\Imaging\GifBuilder;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class GifBuilderTest extends FunctionalTestCase
{
    private function setupFullTestEnvironment(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/sys_file_storage.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->setUpBackendUser(1);
        $GLOBALS['TYPO3_REQUEST'] = (new ServerRequest('https://www.example.com/'))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);
        $GLOBALS['TSFE'] = $this->createMock(TypoScriptFrontendController::class);
        GeneralUtility::mkdir_deep(Environment::getPublicPath() . '/fileadmin');
    }

    /**
     * Sets up Environment to simulate Composer mode and a cli request
     */
    private function simulateCliRequestInComposerMode(): void
    {
        Environment::initialize(
            Environment::getContext(),
            true,
            true,
            Environment::getProjectPath(),
            Environment::getPublicPath() . '/public',
            Environment::getVarPath(),
            Environment::getConfigPath(),
            Environment::getCurrentScript(),
            Environment::isWindows() ? 'WINDOWS' : 'UNIX'
        );
    }

    public static function fileExtensionDataProvider(): array
    {
        return [
            'jpg' => ['jpg'],
            'png' => ['png'],
            'gif' => ['gif'],
            'webp' => ['webp'],
        ];
    }

    #[DataProvider('fileExtensionDataProvider')]
    #[Test]
    public function buildSimpleGifBuilderImageInComposerMode(string $fileExtension): void
    {
        $this->simulateCliRequestInComposerMode();

        $conf = [
            'XY' => '10,10',
            'format' => $fileExtension,
        ];

        $gifBuilder = new GifBuilder();
        $gifBuilder->start($conf, []);
        $imageResource = $gifBuilder->gifBuild();

        self::assertFileDoesNotExist(Environment::getProjectPath() . '/' . $imageResource->getPublicUrl());
        self::assertFileExists(Environment::getPublicPath() . '/' . $imageResource->getPublicUrl());
        self::assertEquals($fileExtension, $imageResource->getExtension());
    }

    #[Test]
    public function buildImageInCommandLineInterfaceAndComposerMode(): void
    {
        $this->simulateCliRequestInComposerMode();
        $this->setupFullTestEnvironment();

        copy(
            __DIR__ . '/../Fixtures/Images/kasper-skarhoj1.jpg',
            Environment::getPublicPath() . '/fileadmin/kasper-skarhoj-gifbuilder.jpg'
        );

        $contentObjectRenderer = new ContentObjectRenderer();
        $result = $contentObjectRenderer->cObjGetSingle(
            'IMAGE',
            [
                'file' => 'GIFBUILDER',
                'file.' => [
                    'XY' => '[10.w],[10.h]',
                    'format' => 'jpg',

                    '10' => 'IMAGE',
                    '10.' => [
                        'file' => 'fileadmin/kasper-skarhoj-gifbuilder.jpg',
                    ],
                ],
            ]
        );
        self::assertStringStartsWith('<img src="typo3temp/assets/images/csm_kasper-skarhoj-gifbuilder_', $result);
    }

    #[Test]
    public function getImageResourceInCommandLineInterfaceAndComposerMode(): void
    {
        $this->simulateCliRequestInComposerMode();
        $this->setupFullTestEnvironment();

        copy(
            __DIR__ . '/../Fixtures/Images/kasper-skarhoj1.jpg',
            Environment::getPublicPath() . '/fileadmin/kasper-skarhoj-gifbuilder-imageresource.jpg'
        );

        $contentObjectRenderer = new ContentObjectRenderer();
        $result = $contentObjectRenderer->cObjGetSingle(
            'IMG_RESOURCE',
            [
                'file' => 'GIFBUILDER',
                'file.' => [
                    'XY' => '[10.w],[10.h]',
                    'format' => 'jpg',

                    '10' => 'IMAGE',
                    '10.' => [
                        'file' => 'fileadmin/kasper-skarhoj-gifbuilder-imageresource.jpg',
                    ],
                ],
            ]
        );
        self::assertStringStartsWith('typo3temp/assets/images/csm_kasper-skarhoj-gifbuilder-imageresource_', $result);
    }

    #[Test]
    public function buildImageWithMaskInCommandLineInterfaceAndComposerMode(): void
    {
        $this->simulateCliRequestInComposerMode();
        $this->setupFullTestEnvironment();

        copy(
            __DIR__ . '/../Fixtures/Images/kasper-skarhoj1.jpg',
            Environment::getPublicPath() . '/fileadmin/kasper-skarhoj-gifbuilder.jpg'
        );

        $contentObjectRenderer = new ContentObjectRenderer();
        $result = $contentObjectRenderer->cObjGetSingle(
            'IMAGE',
            [
                'file' => 'GIFBUILDER',
                'file.' => [
                    'XY' => '[10.w],[10.h]',
                    'format' => 'jpg',

                    '10' => 'IMAGE',
                    '10.' => [
                        'file' => 'fileadmin/kasper-skarhoj-gifbuilder.jpg',
                    ],
                    '20' => 'IMAGE',
                    '20.' => [
                        'offset' => '0,500',
                        'XY' => '[mask.w],40',

                        'file' => 'GIFBUILDER',
                        'file.' => [
                            'XY' => '400,60',
                            'backColor' => '#cccccc',
                        ],

                        'mask' => 'GIFBUILDER',
                        'mask.' => [
                            'XY' => '[10.w]+55,60',
                            'backColor' => '#cccccc',

                            '10' => 'TEXT',
                            '10.' => [
                                'text' => 'Kasper Skårhøj',
                                'fontColor' => '#111111',
                                'fontSize' => '20',
                                'offset' => '20,40',
                            ],
                        ],
                    ],
                ],
            ]
        );
        self::assertStringStartsWith('<img src="typo3temp/', $result);
    }

    /**
     * Check hashes of Images overlayed with other images are idempotent
     */
    #[Test]
    public function overlayImagesHasStableHash(): void
    {
        $this->setupFullTestEnvironment();

        copy(
            __DIR__ . '/../Fixtures/Images/kasper-skarhoj1.jpg',
            Environment::getPublicPath() . '/fileadmin/kasper-skarhoj1.jpg'
        );

        $storageRepository = $this->get(StorageRepository::class)->findByUid(1);
        $file = $storageRepository->getFile('kasper-skarhoj1.jpg');

        self::assertInstanceOf(File::class, $file);
        self::assertFalse($file->isMissing());

        $conf = [
            'XY' => '[10.w],[10.h]',
            'format' => 'jpg',
            'quality' => 88,
            '10' => 'IMAGE',
            '10.' => [
                'file' => $file,
                'file.' => [
                    'width' => 300,
                ],
            ],
            '30' => 'IMAGE',
            '30.' => [
                'file' => $file,
                'file.' => [
                    'align' => 'l,t',
                    'width' => 100,
                ],
            ],
        ];

        $gifBuilder = new GifBuilder();
        $gifBuilder->start($conf, []);
        $setup1 = $gifBuilder->setup;
        $imageResource1 = $gifBuilder->gifBuild();

        // Recreate a fresh GifBuilder instance, to catch inconsistencies in hashing for different instances
        $gifBuilder = new GifBuilder();
        $gifBuilder->start($conf, []);
        $setup2 = $gifBuilder->setup;
        $imageResource2 = $gifBuilder->gifBuild();

        self::assertSame($setup1, $setup2, 'The Setup resulting from two equal configurations must be equal');
        self::assertSame($imageResource1->getPublicUrl(), $imageResource2->getPublicUrl());
    }
}
