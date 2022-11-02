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

namespace TYPO3\CMS\Frontend\Tests\Functional\SiteHandling;

use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Core\Bootstrap;
use TYPO3\CMS\Core\Domain\Repository\PageRepository;
use TYPO3\TestingFramework\Core\Functional\Framework\DataHandling\Scenario\DataHandlerFactory;
use TYPO3\TestingFramework\Core\Functional\Framework\DataHandling\Scenario\DataHandlerWriter;
use TYPO3\TestingFramework\Core\Functional\Framework\Frontend\Internal\TypoScriptInstruction;
use TYPO3\TestingFramework\Core\Functional\Framework\Frontend\InternalRequest;
use TYPO3\TestingFramework\Core\Functional\Framework\Frontend\InternalRequestContext;

class SlugLinkGeneratorTest extends AbstractTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->writeSiteConfiguration(
            'acme-com',
            $this->buildSiteConfiguration(1000, 'https://acme.com/'),
            [
                $this->buildDefaultLanguageConfiguration('EN', 'https://acme.us/'),
                $this->buildLanguageConfiguration('FR', 'https://acme.fr/', ['EN']),
                $this->buildLanguageConfiguration('FR-CA', 'https://acme.ca/', ['FR', 'EN']),
            ]
        );
        $this->writeSiteConfiguration(
            'products-acme-com',
            $this->buildSiteConfiguration(1300, 'https://products.acme.com/')
        );
        $this->writeSiteConfiguration(
            'blog-acme-com',
            $this->buildSiteConfiguration(2000, 'https://blog.acme.com/')
        );
        $this->writeSiteConfiguration(
            'john-blog-acme-com',
            $this->buildSiteConfiguration(2110, 'https://blog.acme.com/john/')
        );
        $this->writeSiteConfiguration(
            'jane-blog-acme-com',
            $this->buildSiteConfiguration(2120, 'https://blog.acme.com/jane/')
        );
        $this->writeSiteConfiguration(
            'archive-acme-com',
            $this->buildSiteConfiguration(3000, 'https://archive.acme.com/'),
            [
                $this->buildDefaultLanguageConfiguration('EN', '/'),
                $this->buildLanguageConfiguration('FR', 'https://archive.acme.com/fr/', ['EN']),
                $this->buildLanguageConfiguration('FR-CA', 'https://archive.acme.com/ca/', ['FR', 'EN']),
            ]
        );
        $this->writeSiteConfiguration(
            'common-collection',
            $this->buildSiteConfiguration(7000, 'https://common.acme.com/')
        );
        $this->writeSiteConfiguration(
            'usual-collection',
            $this->buildSiteConfiguration(8000, 'https://usual.acme.com/')
        );

        $this->withDatabaseSnapshot(
            function () {
                $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
                $backendUser = $this->setUpBackendUser(1);
                Bootstrap::initializeLanguageObject();
                $scenarioFile = __DIR__ . '/Fixtures/SlugScenario.yaml';
                $factory = DataHandlerFactory::fromYamlFile($scenarioFile);
                $writer = DataHandlerWriter::withBackendUser($backendUser);
                $writer->invokeFactory($factory);
                static::failIfArrayIsNotEmpty($writer->getErrors());
                $this->setUpFrontendRootPage(1000, ['typo3/sysext/frontend/Tests/Functional/SiteHandling/Fixtures/LinkGenerator.typoscript'], ['title' => 'ACME Root']);
                $this->setUpFrontendRootPage(2000, ['typo3/sysext/frontend/Tests/Functional/SiteHandling/Fixtures/LinkGenerator.typoscript'], ['title' => 'ACME Blog']);
            },
            function () {
                $this->setUpBackendUser(1);
                Bootstrap::initializeLanguageObject();
            }
        );
    }

    public function linkIsGeneratedDataProvider(): array
    {
        $instructions = [
            // acme.com -> acme.com (same site)
            ['https://acme.us/', 1100, 1000, '/welcome'], // shortcut page is resolved directly
            ['https://acme.us/', 1100, 1100, '/welcome'],
            ['https://acme.us/', 1100, 1200, '/features'],
            ['https://acme.us/', 1100, 1210, '/features/frontend-editing/'],
            ['https://acme.us/', 1100, 404, '/404'],
            // acme.com -> products.acme.com (nested sub-site)
            ['https://acme.us/', 1100, 1300, 'https://products.acme.com/products'],
            ['https://acme.us/', 1100, 1310, 'https://products.acme.com/products/planets'],
            // acme.com -> blog.acme.com (different site)
            ['https://acme.us/', 1100, 2000, 'https://blog.acme.com/authors'], // recursive shortcut page is resolved directly
            ['https://acme.us/', 1100, 2100, 'https://blog.acme.com/authors'],
            ['https://acme.us/', 1100, 2110, 'https://blog.acme.com/john/john'],
            ['https://acme.us/', 1100, 2111, 'https://blog.acme.com/john/about-john'],
            // blog.acme.com -> acme.com (different site)
            ['https://blog.acme.com/', 2100, 1000, 'https://acme.us/welcome'], // shortcut page is resolved directly
            ['https://blog.acme.com/', 2100, 1100, 'https://acme.us/welcome'],
            ['https://blog.acme.com/', 2100, 1200, 'https://acme.us/features'],
            ['https://blog.acme.com/', 2100, 1210, 'https://acme.us/features/frontend-editing/'],
            ['https://blog.acme.com/', 2100, 404, 'https://acme.us/404'],
            // blog.acme.com -> products.acme.com (different sub-site)
            ['https://blog.acme.com/', 2100, 1300, 'https://products.acme.com/products'],
            ['https://blog.acme.com/', 2100, 1310, 'https://products.acme.com/products/planets'],
        ];

        return $this->keysFromTemplate(
            $instructions,
            '%2$d->%3$d'
        );
    }

    /**
     * @test
     * @dataProvider linkIsGeneratedDataProvider
     */
    public function linkIsGenerated(string $hostPrefix, int $sourcePageId, int $targetPageId, string $expectation): void
    {
        $response = $this->executeFrontendSubRequest(
            (new InternalRequest($hostPrefix))
                ->withPageId($sourcePageId)
                ->withInstructions([
                    $this->createTypoLinkUrlInstruction([
                        'parameter' => $targetPageId,
                    ]),
                ])
        );

        self::assertSame($expectation, (string)$response->getBody());
    }

    public function linkIsGeneratedFromMountPointDataProvider(): array
    {
        $instructions = [
            // acme.com -> acme.com (same site)
            ['https://acme.us/', [7100, 1700], 7110, 1000, '/welcome'], // shortcut page is resolved directly
            ['https://acme.us/', [7100, 1700], 7110, 1100, '/welcome'],
            ['https://acme.us/', [7100, 1700], 7110, 1200, '/features'],
            ['https://acme.us/', [7100, 1700], 7110, 1210, '/features/frontend-editing/'],
            ['https://acme.us/', [7100, 1700], 7110, 404, '/404'],
            // acme.com -> products.acme.com (nested sub-site)
            ['https://acme.us/', [7100, 1700], 7110, 1300, 'https://products.acme.com/products'],
            ['https://acme.us/', [7100, 1700], 7110, 1310, 'https://products.acme.com/products/planets'],
            // acme.com -> blog.acme.com (different site)
            ['https://acme.us/', [7100, 1700], 7110, 2000, 'https://blog.acme.com/authors'], // shortcut page is resolved directly
            ['https://acme.us/', [7100, 1700], 7110, 2100, 'https://blog.acme.com/authors'],
            ['https://acme.us/', [7100, 1700], 7110, 2110, 'https://blog.acme.com/john/john'],
            ['https://acme.us/', [7100, 1700], 7110, 2111, 'https://blog.acme.com/john/about-john'],
            // blog.acme.com -> acme.com (different site)
            ['https://blog.acme.com/', [7100, 2700], 7110, 1000, 'https://acme.us/welcome'], // shortcut page is resolved directly
            ['https://blog.acme.com/', [7100, 2700], 7110, 1100, 'https://acme.us/welcome'],
            ['https://blog.acme.com/', [7100, 2700], 7110, 1200, 'https://acme.us/features'],
            ['https://blog.acme.com/', [7100, 2700], 7110, 1210, 'https://acme.us/features/frontend-editing/'],
            ['https://blog.acme.com/', [7100, 2700], 7110, 404, 'https://acme.us/404'],
            // blog.acme.com -> products.acme.com (different sub-site)
            ['https://blog.acme.com/', [7100, 2700], 7110, 1300, 'https://products.acme.com/products'],
            ['https://blog.acme.com/', [7100, 2700], 7110, 1310, 'https://products.acme.com/products/planets'],
        ];

        return $this->keysFromTemplate(
            $instructions,
            '%3$d->%4$d (mount:%2$s)',
            static function (array $items) {
                array_splice(
                    $items,
                    1,
                    1,
                    [implode('->', $items[1])]
                );
                return $items;
            }
        );
    }

    /**
     * @test
     * @dataProvider linkIsGeneratedFromMountPointDataProvider
     */
    public function linkIsGeneratedFromMountPoint(string $hostPrefix, array $pageMount, int $sourcePageId, int $targetPageId, string $expectation): void
    {
        $response = $this->executeFrontendSubRequest(
            (new InternalRequest($hostPrefix))
                ->withMountPoint(...$pageMount)
                ->withPageId($sourcePageId)
                ->withInstructions([
                    $this->createTypoLinkUrlInstruction([
                        'parameter' => $targetPageId,
                    ]),
                ])
        );

        self::assertSame($expectation, (string)$response->getBody());
    }

    public function linkIsGeneratedForLanguageDataProvider(): array
    {
        $instructions = [
            // acme.com -> acme.com (same site)
            ['https://acme.us/', 1100, 1100, 0, '/welcome'],
            ['https://acme.us/', 1100, 1100, 1, 'https://acme.fr/bienvenue'],
            ['https://acme.us/', 1100, 1100, 2, 'https://acme.ca/bienvenue'],
            ['https://acme.us/', 1100, 1101, 0, 'https://acme.fr/bienvenue'],
            ['https://acme.us/', 1100, 1102, 0, 'https://acme.ca/bienvenue'],
            // acme.com -> products.acme.com (nested sub-site)
            ['https://acme.us/', 1100, 1300, 0, 'https://products.acme.com/products'],
            ['https://acme.us/', 1100, 1310, 0, 'https://products.acme.com/products/planets'],
            // acme.com -> products.acme.com (nested sub-site, l18n_cfg=1)
            ['https://acme.us/', 1100, 1410, 0, ''],
            ['https://acme.us/', 1100, 1410, 1, 'https://acme.fr/acme-dans-votre-region/groupes'],
            ['https://acme.us/', 1100, 1410, 2, 'https://acme.ca/acme-dans-votre-quebec/groupes'],
            ['https://acme.us/', 1100, 1411, 0, 'https://acme.fr/acme-dans-votre-region/groupes'],
            ['https://acme.us/', 1100, 1412, 0, 'https://acme.ca/acme-dans-votre-quebec/groupes'],
            // acme.com -> archive (outside site)
            ['https://acme.us/', 1100, 3100, 0, 'https://archive.acme.com/statistics'],
            ['https://acme.us/', 1100, 3100, 1, 'https://archive.acme.com/fr/statistics'],
            ['https://acme.us/', 1100, 3100, 2, 'https://archive.acme.com/ca/statistics'],
            ['https://acme.us/', 1100, 3101, 0, 'https://archive.acme.com/fr/statistics'],
            ['https://acme.us/', 1100, 3102, 0, 'https://archive.acme.com/ca/statistics'],
            // blog.acme.com -> acme.com (different site)
            ['https://blog.acme.com/', 2100, 1100, 0, 'https://acme.us/welcome'],
            ['https://blog.acme.com/', 2100, 1100, 1, 'https://acme.fr/bienvenue'],
            ['https://blog.acme.com/', 2100, 1100, 2, 'https://acme.ca/bienvenue'],
            ['https://blog.acme.com/', 2100, 1101, 0, 'https://acme.fr/bienvenue'],
            ['https://blog.acme.com/', 2100, 1102, 0, 'https://acme.ca/bienvenue'],
            // blog.acme.com -> archive (outside site)
            ['https://blog.acme.com/', 2100, 3100, 0, 'https://archive.acme.com/statistics'],
            ['https://blog.acme.com/', 2100, 3100, 1, 'https://archive.acme.com/fr/statistics'],
            ['https://blog.acme.com/', 2100, 3100, 2, 'https://archive.acme.com/ca/statistics'],
            ['https://blog.acme.com/', 2100, 3101, 0, 'https://archive.acme.com/fr/statistics'],
            ['https://blog.acme.com/', 2100, 3102, 0, 'https://archive.acme.com/ca/statistics'],
            // blog.acme.com -> products.acme.com (different sub-site)
            ['https://blog.acme.com/', 2100, 1300, 0, 'https://products.acme.com/products'],
            ['https://blog.acme.com/', 2100, 1310, 0, 'https://products.acme.com/products/planets'],
        ];

        return $this->keysFromTemplate(
            $instructions,
            '%2$d->%3$d (lang:%4$d)'
        );
    }

    /**
     * @test
     * @dataProvider linkIsGeneratedForLanguageDataProvider
     */
    public function linkIsGeneratedForLanguageWithLanguageProperty(string $hostPrefix, int $sourcePageId, int $targetPageId, int $targetLanguageId, string $expectation): void
    {
        $response = $this->executeFrontendSubRequest(
            (new InternalRequest($hostPrefix))
                ->withPageId($sourcePageId)
                ->withInstructions([
                    $this->createTypoLinkUrlInstruction([
                        'parameter' => $targetPageId,
                        'language' => $targetLanguageId,
                    ]),
                ])
        );

        self::assertSame($expectation, (string)$response->getBody());
    }

    public function linkIsGeneratedWithQueryParametersDataProvider(): array
    {
        $instructions = [
            // acme.com -> acme.com (same site)
            ['https://acme.us/', 1100, 1000, '/welcome?testing%5Bvalue%5D=1&cHash=f42b850e435f0cedd366f5db749fc1af'], // shortcut page is resolved directly
            ['https://acme.us/', 1100, 1100, '/welcome?testing%5Bvalue%5D=1&cHash=f42b850e435f0cedd366f5db749fc1af'],
            ['https://acme.us/', 1100, 1200, '/features?testing%5Bvalue%5D=1&cHash=784e11c50ea1a13fd7d969df4ec53ea3'],
            ['https://acme.us/', 1100, 1210, '/features/frontend-editing/?testing%5Bvalue%5D=1&cHash=ccb7067022b9835ebfd8f720722bc708'],
            ['https://acme.us/', 1100, 404, '/404?testing%5Bvalue%5D=1&cHash=864e96f586a78a53452f3bf0f4d24591'],
            // acme.com -> products.acme.com (nested sub-site)
            ['https://acme.us/', 1100, 1300, 'https://products.acme.com/products?testing%5Bvalue%5D=1&cHash=dbd6597d72ed5098cce3d03eac1eeefe'],
            ['https://acme.us/', 1100, 1310, 'https://products.acme.com/products/planets?testing%5Bvalue%5D=1&cHash=e64bfc7ab7dd6b70d161e4d556be9726'],
            // acme.com -> blog.acme.com (different site)
            ['https://acme.us/', 1100, 2000, 'https://blog.acme.com/authors?testing%5Bvalue%5D=1&cHash=d23d74cb50383f8788a9930ec8ba679f'], // shortcut page is resolved directly
            ['https://acme.us/', 1100, 2100, 'https://blog.acme.com/authors?testing%5Bvalue%5D=1&cHash=d23d74cb50383f8788a9930ec8ba679f'],
            ['https://acme.us/', 1100, 2110, 'https://blog.acme.com/john/john?testing%5Bvalue%5D=1&cHash=bf25eea89f44a9a79dabdca98f38a432'],
            ['https://acme.us/', 1100, 2111, 'https://blog.acme.com/john/about-john?testing%5Bvalue%5D=1&cHash=42dbaeb9172b6b1ca23b49941e194db2'],
            // blog.acme.com -> acme.com (different site)
            ['https://blog.acme.com/', 2100, 1000, 'https://acme.us/welcome?testing%5Bvalue%5D=1&cHash=f42b850e435f0cedd366f5db749fc1af'], // shortcut page is resolved directly
            ['https://blog.acme.com/', 2100, 1100, 'https://acme.us/welcome?testing%5Bvalue%5D=1&cHash=f42b850e435f0cedd366f5db749fc1af'],
            ['https://blog.acme.com/', 2100, 1200, 'https://acme.us/features?testing%5Bvalue%5D=1&cHash=784e11c50ea1a13fd7d969df4ec53ea3'],
            ['https://blog.acme.com/', 2100, 1210, 'https://acme.us/features/frontend-editing/?testing%5Bvalue%5D=1&cHash=ccb7067022b9835ebfd8f720722bc708'],
            ['https://blog.acme.com/', 2100, 404, 'https://acme.us/404?testing%5Bvalue%5D=1&cHash=864e96f586a78a53452f3bf0f4d24591'],
            // blog.acme.com -> products.acme.com (different sub-site)
            ['https://blog.acme.com/', 2100, 1300, 'https://products.acme.com/products?testing%5Bvalue%5D=1&cHash=dbd6597d72ed5098cce3d03eac1eeefe'],
            ['https://blog.acme.com/', 2100, 1310, 'https://products.acme.com/products/planets?testing%5Bvalue%5D=1&cHash=e64bfc7ab7dd6b70d161e4d556be9726'],
        ];

        return $this->keysFromTemplate(
            $instructions,
            '%2$d->%3$d'
        );
    }

    /**
     * @test
     * @dataProvider linkIsGeneratedWithQueryParametersDataProvider
     */
    public function linkIsGeneratedWithQueryParameters(string $hostPrefix, int $sourcePageId, int $targetPageId, string $expectation): void
    {
        $response = $this->executeFrontendSubRequest(
            (new InternalRequest($hostPrefix))
                ->withPageId($sourcePageId)
                ->withInstructions([
                    $this->createTypoLinkUrlInstruction([
                        'parameter' => $targetPageId,
                        'additionalParams' => '&testing[value]=1',
                    ]),
                ])
        );

        self::assertSame($expectation, (string)$response->getBody());
    }

    public function linkIsGeneratedForRestrictedPageDataProvider(): array
    {
        $instructions = [
            ['https://acme.us/', 1100, 1510, 0, ''],
            // ['https://acme.us/', 1100, 1511, 0, ''], // @todo Fails, not expanded to sub-pages
            ['https://acme.us/', 1100, 1512, 0, ''],
            ['https://acme.us/', 1100, 1515, 0, ''],
            ['https://acme.us/', 1100, 1520, 0, ''],
            // ['https://acme.us/', 1100, 1521, 0, ''], // @todo Fails, not expanded to sub-pages
            //
            ['https://acme.us/', 1100, 1510, 1, '/my-acme/whitepapers'],
            ['https://acme.us/', 1100, 1511, 1, '/my-acme/whitepapers/products'],
            ['https://acme.us/', 1100, 1512, 1, '/my-acme/whitepapers/solutions'],
            ['https://acme.us/', 1100, 1515, 1, ''],
            ['https://acme.us/', 1100, 1520, 1, ''],
            // ['https://acme.us/', 1100, 1521, 1, ''], // @todo Fails, not expanded to sub-pages
            //
            ['https://acme.us/', 1100, 1510, 2, '/my-acme/whitepapers'],
            ['https://acme.us/', 1100, 1511, 2, '/my-acme/whitepapers/products'],
            ['https://acme.us/', 1100, 1512, 2, ''],
            ['https://acme.us/', 1100, 1515, 2, '/my-acme/whitepapers/research'],
            ['https://acme.us/', 1100, 1520, 2, '/my-acme/forecasts'],
            ['https://acme.us/', 1100, 1521, 2, '/my-acme/forecasts/current-year'],
            //
            ['https://acme.us/', 1100, 1510, 3, '/my-acme/whitepapers'],
            ['https://acme.us/', 1100, 1511, 3, '/my-acme/whitepapers/products'],
            ['https://acme.us/', 1100, 1512, 3, '/my-acme/whitepapers/solutions'],
            ['https://acme.us/', 1100, 1515, 3, '/my-acme/whitepapers/research'],
            ['https://acme.us/', 1100, 1520, 3, '/my-acme/forecasts'],
            ['https://acme.us/', 1100, 1521, 3, '/my-acme/forecasts/current-year'],
        ];

        return $this->keysFromTemplate(
            $instructions,
            '%2$d->%3$d (user:%4$d)'
        );
    }

    /**
     * @test
     * @dataProvider linkIsGeneratedForRestrictedPageDataProvider
     */
    public function linkIsGeneratedForRestrictedPage(string $hostPrefix, int $sourcePageId, int $targetPageId, int $frontendUserId, string $expectation): void
    {
        $response = $this->executeFrontendSubRequest(
            (new InternalRequest($hostPrefix))
                ->withPageId($sourcePageId)
                ->withInstructions([
                    $this->createTypoLinkUrlInstruction([
                        'parameter' => $targetPageId,
                    ]),
                ]),
            (new InternalRequestContext())->withFrontendUserId($frontendUserId)
        );

        self::assertSame($expectation, (string)$response->getBody());
    }

    public function linkIsGeneratedForRestrictedPageUsingLoginPageDataProvider(): array
    {
        $instructions = [
            // no frontend user given
            ['https://acme.us/', 1100, 1510, 1500, 0, '/my-acme?pageId=1510&cHash=119c4870e323bb7e8c9fae2941726b0d'],
            // ['https://acme.us/', 1100, 1511, 1500, 0, '/my-acme?pageId=1511'], // @todo Fails, not expanded to sub-pages
            ['https://acme.us/', 1100, 1512, 1500, 0, '/my-acme?pageId=1512&cHash=0ced3db0fd4aae0019a99f59cfa58cb0'],
            ['https://acme.us/', 1100, 1515, 1500, 0, '/my-acme?pageId=1515&cHash=176f16b31d2c731347d411861d8b06dc'],
            ['https://acme.us/', 1100, 1520, 1500, 0, '/my-acme?pageId=1520&cHash=253d3dccd4794c4a9473226f683bc36a'],
            // ['https://acme.us/', 1100, 1521, 1500, 0, '/my-acme?pageId=1521'], // @todo Fails, not expanded to sub-pages
            // frontend user 1
            ['https://acme.us/', 1100, 1510, 1500, 1, '/my-acme/whitepapers'],
            ['https://acme.us/', 1100, 1511, 1500, 1, '/my-acme/whitepapers/products'],
            ['https://acme.us/', 1100, 1512, 1500, 1, '/my-acme/whitepapers/solutions'],
            ['https://acme.us/', 1100, 1515, 1500, 1, '/my-acme?pageId=1515&cHash=176f16b31d2c731347d411861d8b06dc'],
            ['https://acme.us/', 1100, 1520, 1500, 1, '/my-acme?pageId=1520&cHash=253d3dccd4794c4a9473226f683bc36a'],
            // ['https://acme.us/', 1100, 1521, 1500, 1, '/my-acme?pageId=1521'], // @todo Fails, not expanded to sub-pages
            // frontend user 2
            ['https://acme.us/', 1100, 1510, 1500, 2, '/my-acme/whitepapers'],
            ['https://acme.us/', 1100, 1511, 1500, 2, '/my-acme/whitepapers/products'],
            ['https://acme.us/', 1100, 1512, 1500, 2, '/my-acme?pageId=1512&cHash=0ced3db0fd4aae0019a99f59cfa58cb0'],
            ['https://acme.us/', 1100, 1515, 1500, 2, '/my-acme/whitepapers/research'],
            ['https://acme.us/', 1100, 1520, 1500, 2, '/my-acme/forecasts'],
            ['https://acme.us/', 1100, 1521, 1500, 2, '/my-acme/forecasts/current-year'],
            // frontend user 3
            ['https://acme.us/', 1100, 1510, 1500, 3, '/my-acme/whitepapers'],
            ['https://acme.us/', 1100, 1511, 1500, 3, '/my-acme/whitepapers/products'],
            ['https://acme.us/', 1100, 1512, 1500, 3, '/my-acme/whitepapers/solutions'],
            ['https://acme.us/', 1100, 1515, 1500, 3, '/my-acme/whitepapers/research'],
            ['https://acme.us/', 1100, 1520, 1500, 3, '/my-acme/forecasts'],
            ['https://acme.us/', 1100, 1521, 1500, 3, '/my-acme/forecasts/current-year'],
        ];

        return $this->keysFromTemplate(
            $instructions,
            '%2$d->%3$d (via: %4$d, user:%5$d)'
        );
    }

    /**
     * @test
     * @dataProvider linkIsGeneratedForRestrictedPageUsingLoginPageDataProvider
     */
    public function linkIsGeneratedForRestrictedPageUsingLoginPage(string $hostPrefix, int $sourcePageId, int $targetPageId, int $loginPageId, int $frontendUserId, string $expectation): void
    {
        $response = $this->executeFrontendSubRequest(
            (new InternalRequest($hostPrefix))
                ->withPageId($sourcePageId)
                ->withInstructions([
                    (new TypoScriptInstruction())
                        ->withTypoScript([
                            'config.' => [
                                'typolinkLinkAccessRestrictedPages' => $loginPageId,
                                'typolinkLinkAccessRestrictedPages_addParams' => '&pageId=###PAGE_ID###',
                            ],
                        ]),
                    $this->createTypoLinkUrlInstruction([
                        'parameter' => $targetPageId,
                    ]),
                ]),
            (new InternalRequestContext())->withFrontendUserId($frontendUserId)
        );

        self::assertSame($expectation, (string)$response->getBody());
    }

    public function linkIsGeneratedForRestrictedPageForGuestsUsingTypolinkLinkAccessRestrictedPagesDataProvider(): array
    {
        $instructions = [
            // default language (0)
            ['https://acme.us/', 1100, 1510, 0, '/my-acme/whitepapers'],
            ['https://acme.us/', 1100, 1512, 0, '/my-acme/whitepapers/solutions'],
            ['https://acme.us/', 1100, 1515, 0, '/my-acme/whitepapers/research'],
            // french language (1)
            ['https://acme.fr/', 1100, 1510, 1, '/my-acme/papiersblanc'],
            ['https://acme.fr/', 1100, 1512, 1, '/my-acme/papiersblanc/la-solutions'],
            ['https://acme.fr/', 1100, 1515, 1, '/my-acme/papiersblanc/recherche'],
            // canadian french language (2)
            ['https://acme.ca/', 1100, 1510, 2, '/my-acme-ca/papiersblanc'],
            ['https://acme.ca/', 1100, 1512, 2, '/my-acme-ca/papiersblanc/la-solutions'],
            ['https://acme.ca/', 1100, 1515, 2, '/my-acme-ca/papiersblanc/recherche'],
        ];

        return $this->keysFromTemplate(
            $instructions,
            '%2$d->%3$d (language: %4$d)'
        );
    }

    /**
     * @test
     * @dataProvider linkIsGeneratedForRestrictedPageForGuestsUsingTypolinkLinkAccessRestrictedPagesDataProvider
     */
    public function linkIsGeneratedForRestrictedPageForGuestsUsingTypolinkLinkAccessRestrictedPages(string $hostPrefix, int $sourcePageId, int $targetPageId, int $languageId, string $expectation): void
    {
        $response = $this->executeFrontendSubRequest(
            (new InternalRequest($hostPrefix))
                ->withPageId($sourcePageId)
                ->withInstructions([
                    (new TypoScriptInstruction())
                        ->withTypoScript([
                            'config.' => [
                                'typolinkLinkAccessRestrictedPages' => 'NONE',
                            ],
                        ]),
                    $this->createTypoLinkUrlInstruction([
                        'parameter' => $targetPageId,
                    ]),
                ])
        );

        self::assertSame($expectation, (string)$response->getBody());
    }

    public function linkIsGeneratedForPageVersionDataProvider(): array
    {
        $instructions = [
            // acme.com -> acme.com (same site): link to changed page
            ['https://acme.us/', 1100, 1100, false, 1, '/welcome-modified'],
            ['https://acme.us/', 1100, 1100, true, 1, '/welcome-modified'],
            ['https://acme.us/', 1100, 1100, false, 0, '/welcome'],
            ['https://acme.us/', 1100, 1100, true, 0, ''], // @todo link is empty, but should create a link
            // acme.com -> acme.com (same site): link to new page (no need to resolve the version for the new page)
            ['https://acme.us/', 1100, 1950, false, 1, '/bye'],
            ['https://acme.us/', 1100, 1950, false, 0, ''],
            // blog.acme.com -> acme.com (different site): link to changed page
            ['https://blog.acme.com/', 2100, 1100, true, 1, 'https://acme.us/welcome-modified'],
            ['https://blog.acme.com/', 2100, 1100, false, 1, 'https://acme.us/welcome-modified'],
            ['https://blog.acme.com/', 2100, 1100, false, 0, 'https://acme.us/welcome'],
            ['https://blog.acme.com/', 2100, 1100, true, 0, ''], // @todo link is empty, but should create a link
            // blog.acme.com -> acme.com (different site): link to new page (no need to resolve the version for the new page)
            ['https://blog.acme.com/', 2100, 1950, false, 1, 'https://acme.us/bye'],
            ['https://blog.acme.com/', 2100, 1950, false, 0, ''],
        ];

        return $this->keysFromTemplate(
            $instructions,
            '%2$d->%3$d (resolve:%4$d, be_user:%5$d)'
        );
    }

    /**
     * @test
     * @dataProvider linkIsGeneratedForPageVersionDataProvider
     */
    public function linkIsGeneratedForPageVersion(string $hostPrefix, int $sourcePageId, int $targetPageId, bool $resolveVersion, int $backendUserId, string $expectation): void
    {
        $workspaceId = 1;
        if ($resolveVersion) {
            $targetPageId = BackendUtility::getWorkspaceVersionOfRecord(
                $workspaceId,
                'pages',
                $targetPageId,
                'uid'
            )['uid'] ?? null;
            $targetPageId = $targetPageId ? (int)$targetPageId : null;
        }

        $response = $this->executeFrontendSubRequest(
            (new InternalRequest($hostPrefix))
                ->withPageId($sourcePageId)
                ->withInstructions([
                    $this->createTypoLinkUrlInstruction([
                        'parameter' => $targetPageId,
                    ]),
                ]),
            (new InternalRequestContext())
                ->withWorkspaceId($backendUserId !== 0 ? $workspaceId : 0)
                ->withBackendUserId($backendUserId)
        );

        $expectation = str_replace(
            ['{targetPageId}'],
            [$targetPageId],
            $expectation
        );

        self::assertSame($expectation, (string)$response->getBody());
    }

    public function hierarchicalMenuIsGeneratedDataProvider(): array
    {
        return [
            'ACME Inc' => [
                'https://acme.us/',
                1100,
                [
                    ['title' => 'EN: Welcome', 'link' => '/welcome', 'target' => ''],
                    [
                        'title' => 'ZH-CN: Welcome Default',
                        // Symfony UrlGenerator, which is used for uri generation, rawurlencodes the url internally.
                        'link' => '/%E7%AE%80-bienvenue',
                        'target' => '',
                    ],
                    [
                        'title' => 'EN: Features',
                        'link' => '/features',
                        'target' => '',
                        'children' => [
                            [
                                'title' => 'EN: Frontend Editing',
                                'link' => '/features/frontend-editing/',
                                'target' => '',
                            ],
                        ],
                    ],
                    [
                        'title' => 'EN: Products',
                        'link' => 'https://products.acme.com/products',
                        'target' => '',
                        'children' => [
                            [
                                'title' => 'EN: Planets',
                                'link' => 'https://products.acme.com/products/planets',
                                'target' => '',
                            ],
                            [
                                'title' => 'EN: Spaceships',
                                'link' => 'https://products.acme.com/products/spaceships',
                                'target' => '',
                            ],
                            [
                                'title' => 'EN: Dark Matter',
                                'link' => 'https://products.acme.com/products/dark-matter',
                                'target' => '',
                            ],
                        ],
                    ],
                    ['title' => 'EN: ACME in your Region', 'link' => '/acme-in-your-region', 'target' => ''],
                    [
                        'title' => 'Divider',
                        'link' => '/divider',
                        'target' => '',
                        'children' => [
                            [
                                'title' => 'EN: Subpage of Spacer',
                                'link' => '/divider/subpage-of-spacer',
                                'target' => '',
                            ],
                        ],
                    ],
                    ['title' => 'Internal', 'link' => '/my-acme', 'target' => ''],
                    ['title' => 'About us', 'link' => '/about', 'target' => ''],
                    [
                        'title' => 'Announcements & News',
                        'link' => '/news',
                        'target' => '',
                        'children' => [
                            [
                                'title' => 'Markets',
                                'link' => '/news/common/markets',
                                'target' => '',
                            ],
                            [
                                'title' => 'Products',
                                'link' => '/news/common/products',
                                'target' => '',
                            ],
                            [
                                'title' => 'Partners',
                                'link' => '/news/common/partners',
                                'target' => '_blank',
                            ],
                        ],
                    ],
                    ['title' => 'That page is forbidden to you', 'link' => '/403', 'target' => ''],
                    ['title' => 'That page was not found', 'link' => '/404', 'target' => ''],
                    ['title' => 'Our Blog', 'link' => 'https://blog.acme.com/authors', 'target' => ''],
                    ['title' => 'Cross Site Shortcut', 'link' => 'https://blog.acme.com/authors', 'target' => ''],
                ],
            ],
            'ACME Blog' => [
                'https://blog.acme.com/',
                2100,
                [
                    [
                        'title' => 'Authors',
                        'link' => '/authors',
                        'target' => '',
                        'children' => [
                            [
                                'title' => 'John Doe',
                                'link' => 'https://blog.acme.com/john/john',
                                'target' => '',
                            ],
                            [
                                'title' => 'Jane Doe',
                                'link' => 'https://blog.acme.com/jane/jane',
                                'target' => '',
                            ],
                            [
                                'title' => 'Malloy Doe',
                                'link' => '/malloy',
                                'target' => '',
                            ],
                        ],
                    ],
                    1 =>
                        [
                            'title' => 'Announcements & News',
                            'link' => '/news',
                            'target' => '',
                            'children' => [
                                [
                                    'title' => 'Markets',
                                    'link' => '/news/common/markets',
                                    'target' => '',
                                ],
                                [
                                    'title' => 'Products',
                                    'link' => '/news/common/products',
                                    'target' => '',
                                ],
                                [
                                    'title' => 'Partners',
                                    'link' => '/news/common/partners',
                                    'target' => '_blank',
                                ],
                            ],
                        ],
                    ['title' => 'What is a blog on Wikipedia', 'link' => 'https://en.wikipedia.org/wiki/Blog', 'target' => 'a_new_tab'],
                    // target is empty because no fluid_styled_content typoscript with config.extTarget is active
                    ['title' => 'What is Wikipedia in a separate window', 'link' => 'https://en.wikipedia.org/', 'target' => ''],
                    ['title' => 'ACME Inc', 'link' => 'https://acme.us/welcome', 'target' => ''],
                ],
            ],
        ];
    }

    /**
     * @test
     * @dataProvider hierarchicalMenuIsGeneratedDataProvider
     */
    public function hierarchicalMenuIsGenerated(string $hostPrefix, int $sourcePageId, array $expectation): void
    {
        $response = $this->executeFrontendSubRequest(
            (new InternalRequest($hostPrefix))
                ->withPageId($sourcePageId)
                ->withInstructions([
                    $this->createHierarchicalMenuProcessorInstruction([
                        'levels' => 2,
                        'entryLevel' => 0,
                        'expandAll' => 1,
                        'includeSpacer' => 1,
                        'titleField' => 'title',
                    ]),
                ])
        );

        $json = json_decode((string)$response->getBody(), true);
        $json = $this->filterMenu($json, ['title', 'link', 'target']);

        self::assertSame($expectation, $json);
    }

    /**
     * @test
     */
    public function hierarchicalMenuDoesNotShowHiddenPagesAsSubMenu(): void
    {
        $expectation = [
            [
                'title' => 'John Doe',
                'link' => 'https://blog.acme.com/john/john',
                'hasSubpages' => 1,
                'children' => [
                    [
                        'title' => 'About',
                        'link' => 'https://blog.acme.com/john/about-john',
                        'hasSubpages' => 0,
                    ],
                ],
            ],
            [
                'title' => 'Jane Doe',
                'link' => 'https://blog.acme.com/jane/jane',
                'hasSubpages' => 1,
                'children' => [
                    [
                        'title' => 'About',
                        'link' => 'https://blog.acme.com/jane/about-jane',
                        'hasSubpages' => 0,
                    ],
                ],
            ],
            [
                'title' => 'Malloy Doe',
                'link' => '/malloy',
                'hasSubpages' => 0,
            ],
        ];
        $response = $this->executeFrontendSubRequest(
            (new InternalRequest('https://blog.acme.com/'))
                ->withPageId(2130)
                ->withInstructions([
                    $this->createHierarchicalMenuProcessorInstruction([
                        'levels' => 2,
                        'entryLevel' => 1,
                        'expandAll' => 1,
                        'includeSpacer' => 1,
                        'titleField' => 'title',
                    ]),
                ])
        );

        $json = json_decode((string)$response->getBody(), true);
        $json = $this->filterMenu($json, ['title', 'link', 'hasSubpages']);

        self::assertSame($expectation, $json);
    }

    public function hierarchicalMenuSetsActiveStateProperlyDataProvider(): array
    {
        return [
            'regular page' => [
                'https://acme.us/',
                1310,
                '1300',
                [
                    [
                        'title' => 'EN: Products',
                        'link' => 'https://products.acme.com/products',
                        'active' => 1,
                        'current' => 0,
                        'children' => [
                            [
                                'title' => 'EN: Planets',
                                'link' => 'https://products.acme.com/products/planets',
                                'active' => 1,
                                'current' => 1,
                            ],
                            [
                                'title' => 'EN: Spaceships',
                                'link' => 'https://products.acme.com/products/spaceships',
                                'active' => 0,
                                'current' => 0,
                            ],
                            [
                                'title' => 'EN: Dark Matter',
                                'link' => 'https://products.acme.com/products/dark-matter',
                                'active' => 0,
                                'current' => 0,
                            ],
                        ],
                    ],
                ],
            ],
            'resolved shortcut' => [
                'https://blog.acme.com/',
                2100,
                '1930',
                [
                    [
                        'title' => 'Our Blog',
                        'link' => '/authors',
                        'active' => 1,
                        'current' => 1,
                    ],
                ],
            ],
        ];
    }

    /**
     * @test
     * @dataProvider hierarchicalMenuSetsActiveStateProperlyDataProvider
     */
    public function hierarchicalMenuSetsActiveStateProperly(string $hostPrefix, int $sourcePageId, string $menuPageIds, array $expectation): void
    {
        $response = $this->executeFrontendSubRequest(
            (new InternalRequest($hostPrefix))
                ->withPageId($sourcePageId)
                ->withInstructions([
                    $this->createHierarchicalMenuProcessorInstruction([
                        'levels' => 2,
                        'special' => 'list',
                        'special.' => [
                            'value' => $menuPageIds,
                        ],
                        'includeSpacer' => 1,
                        'titleField' => 'title',
                    ]),
                ]),
        );

        $json = json_decode((string)$response->getBody(), true);
        $json = $this->filterMenu($json, ['title', 'active', 'current', 'link']);

        self::assertSame($expectation, $json);
    }

    public function hierarchicalMenuAlwaysResolvesToDefaultLanguageDataProvider(): array
    {
        return [
            'no banned IDs in default language' => [
                'language' => 0,
                'banned IDs' => '',
                'expected menu items' => 13,
            ],
            'no banned IDs in FR' => [
                'language' => 1,
                'banned IDs' => '',
                'expected menu items' => 13,
            ],
            'banned IDs in default language' => [
                'language' => 0,
                'banned IDs' => '1100,1200,1300,1400,403,404',
                'expected menu items' => 7,
            ],
            'banned IDs in FR language' => [
                'language' => 1,
                'banned IDs' => '1100,1200,1300,1400,403,404',
                'expected menu items' => 7,
            ],
            'banned translated IDs in default language' => [
                'language' => 0,
                'banned IDs' => '1101,1200,1300,1400,403,404',
                'expected menu items' => 8,
            ],
            'banned translated IDs in FR language' => [
                'language' => 1,
                'banned IDs' => '1101,1200,1300,1400,403,404',
                'expected menu items' => 7,
            ],
        ];
    }

    /**
     * Checks that excludeUidList checks against translated pages and default-language page IDs.
     *
     * @test
     * @dataProvider hierarchicalMenuAlwaysResolvesToDefaultLanguageDataProvider
     */
    public function hierarchicalMenuAlwaysResolvesToDefaultLanguage(int $languageId, string $excludedUidList, int $expectedMenuItems): void
    {
        $response = $this->executeFrontendSubRequest(
            (new InternalRequest('https://acme.us/'))
                ->withPageId(1100)
                ->withLanguageId($languageId)
                ->withInstructions([
                    $this->createHierarchicalMenuProcessorInstruction([
                        'levels' => 1,
                        'entryLevel' => 0,
                        'excludeUidList' => $excludedUidList,
                        'expandAll' => 1,
                        'includeSpacer' => 1,
                        'titleField' => 'title',
                    ]),
                ])
        );

        $json = json_decode((string)$response->getBody(), true);
        self::assertSame($expectedMenuItems, count($json));
    }

    public function directoryMenuIsGeneratedDataProvider(): array
    {
        return [
            'ACME Inc First Level - Live' => [
                'https://acme.us/',
                1100,
                1000,
                0,
                0,
                [
                    [
                        'title' => 'EN: Welcome',
                        'link' => '/welcome',
                    ],
                    [
                        'title' => 'ZH-CN: Welcome Default',
                        // Symfony UrlGenerator, which is used for uri generation, rawurlencodes the url internally.
                        'link' => '/%E7%AE%80-bienvenue',
                    ],
                    [
                        'title' => 'EN: Features',
                        'link' => '/features',
                    ],
                    [
                        'title' => 'EN: Products',
                        'link' => 'https://products.acme.com/products',
                    ],
                    [
                        'title' => 'EN: ACME in your Region',
                        'link' => '/acme-in-your-region',
                    ],
                    [
                        'title' => 'Internal',
                        'link' => '/my-acme',
                    ],
                    [
                        'title' => 'About us',
                        'link' => '/about',
                    ],
                    [
                        'title' => 'Announcements & News',
                        'link' => '/news',
                    ],
                    [
                        'title' => 'That page is forbidden to you',
                        'link' => '/403',
                    ],
                    [
                        'title' => 'That page was not found',
                        'link' => '/404',
                    ],
                    [
                        'title' => 'Our Blog',
                        'link' => 'https://blog.acme.com/authors',
                    ],
                    [
                        'title' => 'Cross Site Shortcut',
                        'link' => 'https://blog.acme.com/authors',
                    ],
                ],
            ],
            'ACME Inc First Level - Draft Workspace' => [
                'https://acme.us/',
                1100,
                1000,
                1,
                1,
                [
                    [
                        'title' => 'EN: Goodbye',
                        'link' => '/bye',
                    ],
                    [
                        'title' => 'EN: Welcome to ACME Inc',
                        'link' => '/welcome-modified',
                    ],
                    [
                        'title' => 'ZH-CN: Welcome Default',
                        // Symfony UrlGenerator, which is used for uri generation, rawurlencodes the url internally.
                        'link' => '/%E7%AE%80-bienvenue',
                    ],
                    [
                        'title' => 'EN: Features',
                        'link' => '/features',
                    ],
                    [
                        'title' => 'EN: Products',
                        'link' => 'https://products.acme.com/products',
                    ],
                    [
                        'title' => 'EN: ACME in your Region',
                        'link' => '/acme-in-your-region',
                    ],
                    [
                        'title' => 'Internal',
                        'link' => '/my-acme',
                    ],
                    [
                        'title' => 'About us',
                        'link' => '/about',
                    ],
                    [
                        'title' => 'Announcements & News',
                        'link' => '/news',
                    ],
                    [
                        'title' => 'That page is forbidden to you',
                        'link' => '/403',
                    ],
                    [
                        'title' => 'That page was not found',
                        'link' => '/404',
                    ],
                    [
                        'title' => 'Our Blog',
                        'link' => 'https://blog.acme.com/authors',
                    ],
                    [
                        'title' => 'Cross Site Shortcut',
                        'link' => 'https://blog.acme.com/authors',
                    ],
                ],
            ],
        ];
    }

    /**
     * @test
     * @dataProvider directoryMenuIsGeneratedDataProvider
     */
    public function directoryMenuIsGenerated(string $hostPrefix, int $sourcePageId, int $directoryMenuParentPage, int $backendUserId, int $workspaceId, array $expectation): void
    {
        $response = $this->executeFrontendSubRequest(
            (new InternalRequest($hostPrefix))
                ->withPageId($sourcePageId)
                ->withInstructions([
                    $this->createHierarchicalMenuProcessorInstruction([
                        'special' => 'directory',
                        'special.' => [
                            'value' => $directoryMenuParentPage,
                        ],
                        'titleField' => 'title',
                    ]),
                ]),
            (new InternalRequestContext())
                ->withWorkspaceId($backendUserId !== 0 ? $workspaceId : 0)
                ->withBackendUserId($backendUserId)
        );

        $json = json_decode((string)$response->getBody(), true);
        $json = $this->filterMenu($json);

        self::assertSame($expectation, $json);
    }

    public function directoryMenuToAccessRestrictedPagesIsGeneratedDataProvider(): array
    {
        return [
            'All restricted pages are linked to welcome page' => [
                'https://acme.us/',
                1100,
                1500,
                1100,
                0,
                0,
                [
                    [
                        'title' => 'Whitepapers',
                        'link' => '/welcome',
                    ],
                    [
                        'title' => 'Forecasts',
                        'link' => '/welcome',
                    ],
                    [
                        // Shortcut page, which resolves the shortcut and then the next page
                        'title' => 'Employees',
                        'link' => '/welcome',
                    ],
                ],
            ],
            'Inherited restricted pages are linked' => [
                'https://acme.us/',
                1100,
                1520,
                1100,
                0,
                0,
                [
                    [
                        'title' => 'Current Year',
                        // Should be
                        // 'link' => '/welcome',
                        // see https://forge.typo3.org/issues/16561
                        'link' => '/my-acme/forecasts/current-year',
                    ],
                    [
                        'title' => 'Next Year',
                        // Should be
                        // 'link' => '/welcome',
                        // see https://forge.typo3.org/issues/16561
                        'link' => '/my-acme/forecasts/next-year',
                    ],
                    [
                        'title' => 'Five Years',
                        // Should be
                        // 'link' => '/welcome',
                        // see https://forge.typo3.org/issues/16561
                        'link' => '/my-acme/forecasts/five-years',
                    ],
                ],
            ],
        ];
    }

    /**
     * @test
     * @dataProvider directoryMenuToAccessRestrictedPagesIsGeneratedDataProvider
     */
    public function directoryMenuToAccessRestrictedPagesIsGenerated(string $hostPrefix, int $sourcePageId, int $directoryMenuParentPage, int $loginPageId, int $backendUserId, int $workspaceId, array $expectation): void
    {
        $response = $this->executeFrontendSubRequest(
            (new InternalRequest($hostPrefix))
                ->withPageId($sourcePageId)
                ->withInstructions([
                    $this->createHierarchicalMenuProcessorInstruction([
                        'special' => 'directory',
                        'special.' => [
                            'value' => $directoryMenuParentPage,
                        ],
                        'levels' => 1,
                        'showAccessRestrictedPages' => $loginPageId,
                    ]),
                ]),
            (new InternalRequestContext())
                ->withWorkspaceId($backendUserId !== 0 ? $workspaceId : 0)
                ->withBackendUserId($backendUserId)
        );

        $json = json_decode((string)$response->getBody(), true);
        $json = $this->filterMenu($json);

        self::assertSame($expectation, $json);
    }

    public function listMenuIsGeneratedDataProvider(): array
    {
        return [
            'Live' => [
                'https://acme.us/',
                1100,
                [1600, 1100, 1700, 1800, 1520],
                0,
                0,
                [],
                [
                    [
                        'title' => 'About us',
                        'link' => '/about',
                    ],
                    [
                        'title' => 'EN: Welcome',
                        'link' => '/welcome',
                    ],
                    [
                        'title' => 'Announcements & News',
                        'link' => '/news',
                    ],
                ],
            ],
            'Workspaces' => [
                'https://acme.us/',
                1100,
                [1600, 1100, 1700, 1800, 1520],
                1,
                1,
                [],
                [
                    [
                        'title' => 'About us',
                        'link' => '/about',
                    ],
                    [
                        'title' => 'EN: Welcome to ACME Inc',
                        'link' => '/welcome-modified',
                    ],
                    [
                        'title' => 'Announcements & News',
                        'link' => '/news',
                    ],
                ],
            ],
            'Folder as base directory, needs to set excludeDoktypes in order to show the folder itself' => [
                'https://acme.us/',
                1100,
                [7000],
                0,
                0,
                [
                    'levels' => 2,
                    'expandAll' => 1,
                    'excludeDoktypes' => PageRepository::DOKTYPE_BE_USER_SECTION,
                ],
                [
                    [
                        'title' => 'Common Collection',
                        // @todo Folders should not be linked in frontend menus, as they are not accessible there.
                        // @todo Folder as rootpage - reconsider if this should be a valid use/test case, as marking
                        //       it as root_page is not possible if page is doktype sysfolder first.
                        'link' => 'https://common.acme.com/common',
                        'children' => [
                            [
                                'title' => 'Announcements & News',
                                'link' => 'https://common.acme.com/common/news',
                            ],
                        ],
                    ],
                ],
            ],
            'Non-Rootpage Sysfolder, needs to set excludeDoktypes in order to show the folder itself' => [
                'https://acme.us/',
                1100,
                [8000],
                0,
                0,
                [
                    'levels' => 2,
                    'expandAll' => 1,
                    'excludeDoktypes' => PageRepository::DOKTYPE_BE_USER_SECTION,
                ],
                [
                    [
                        'title' => 'Usual Collection Non-Root',
                        'link' => 'https://usual.acme.com/usual',
                        'children' => [
                            [
                                'title' => 'Announcements & News',
                                // @todo Folders should not be linked in frontend menus, as they are not accessible there.
                                'link' => 'https://usual.acme.com/usual/news-folder',
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @test
     * @dataProvider listMenuIsGeneratedDataProvider
     */
    public function listMenuIsGenerated(string $hostPrefix, int $sourcePageId, array $menuPageIds, int $backendUserId, int $workspaceId, array $additionalMenuConfiguration, array $expectation): void
    {
        $response = $this->executeFrontendSubRequest(
            (new InternalRequest($hostPrefix))
                ->withPageId($sourcePageId)
                ->withInstructions([
                    $this->createHierarchicalMenuProcessorInstruction(array_replace_recursive([
                        'special' => 'list',
                        'special.' => [
                            'value' => implode(',', $menuPageIds),
                        ],
                        'titleField' => 'title',
                    ], $additionalMenuConfiguration)),
                ]),
            (new InternalRequestContext())
                ->withWorkspaceId($backendUserId !== 0 ? $workspaceId : 0)
                ->withBackendUserId($backendUserId)
        );

        $json = json_decode((string)$response->getBody(), true);
        $json = $this->filterMenu($json);

        self::assertSame($expectation, $json);
    }

    public function languageMenuIsGeneratedDataProvider(): array
    {
        return [
            'ACME Inc (EN)' => [
                'https://acme.us/',
                1100,
                [
                    ['title' => 'English', 'link' => '/welcome', 'active' => 1, 'current' => 0, 'available' => 1],
                    ['title' => 'French', 'link' => 'https://acme.fr/bienvenue', 'active' => 0, 'current' => 0, 'available' => 1],
                    ['title' => 'Franco-Canadian', 'link' => 'https://acme.ca/bienvenue', 'active' => 0, 'current' => 0, 'available' => 1],
                ],
            ],
            'ACME Inc (FR)' => [
                'https://acme.fr/',
                1100,
                [
                    ['title' => 'English', 'link' => 'https://acme.us/welcome', 'active' => 0, 'current' => 0, 'available' => 1],
                    ['title' => 'French', 'link' => '/bienvenue', 'active' => 1, 'current' => 0, 'available' => 1],
                    ['title' => 'Franco-Canadian', 'link' => 'https://acme.ca/bienvenue', 'active' => 0, 'current' => 0, 'available' => 1],
                ],
            ],
            'ACME Inc (FR-CA)' => [
                'https://acme.ca/',
                1100,
                [
                    ['title' => 'English', 'link' => 'https://acme.us/welcome', 'active' => 0, 'current' => 0, 'available' => 1],
                    ['title' => 'French', 'link' => 'https://acme.fr/bienvenue', 'active' => 0, 'current' => 0, 'available' => 1],
                    ['title' => 'Franco-Canadian', 'link' => '/bienvenue', 'active' => 1, 'current' => 0, 'available' => 1],
                ],
            ],
            'ACME Blog' => [
                'https://blog.acme.com/',
                2100,
                [
                    ['title' => 'Default', 'link' => '/authors', 'active' => 1, 'current' => 0, 'available' => 1],
                ],
            ],
            'ACME Inc (EN) with a subpage' => [
                'https://acme.us/about',
                1600,
                [
                    ['title' => 'English', 'link' => '/about', 'active' => 1, 'current' => 0, 'available' => 1],
                    ['title' => 'French', 'link' => 'https://acme.fr/about', 'active' => 0, 'current' => 0, 'available' => 0],
                    ['title' => 'Franco-Canadian', 'link' => 'https://acme.ca/about', 'active' => 0, 'current' => 0, 'available' => 0],
                ],
            ],
        ];
    }

    /**
     * @test
     * @dataProvider languageMenuIsGeneratedDataProvider
     */
    public function languageMenuIsGenerated(string $hostPrefix, int $sourcePageId, array $expectation): void
    {
        $response = $this->executeFrontendSubRequest(
            (new InternalRequest($hostPrefix))
                ->withPageId($sourcePageId)
                ->withInstructions([
                    $this->createLanguageMenuProcessorInstruction([
                        'languages' => 'auto',
                    ]),
                ])
        );

        $json = json_decode((string)$response->getBody(), true);
        $json = $this->filterMenu($json, ['title', 'link', 'available', 'active', 'current']);

        self::assertSame($expectation, $json);
    }
}
