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

namespace TYPO3\CMS\Frontend\Tests\Functional\SiteHandling\EnhancerSiteRequest;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Functional\Framework\Frontend\InternalRequest;

/**
 * Tests for limitToPages with Expression Language support in route enhancers.
 */
final class LimitToPagesTest extends AbstractEnhancerSiteRequestTestCase
{
    private static function getSimpleEnhancer(array $limitToPages): array
    {
        return [
            'type' => 'Simple',
            'routePath' => '/enhance/{parameter}',
            'limitToPages' => $limitToPages,
            'aspects' => [
                'parameter' => [
                    'type' => 'StaticValueMapper',
                    'map' => [
                        'hello' => 'world',
                    ],
                ],
            ],
        ];
    }

    #[Test]
    public function enhancerWithIntegerLimitToPagesMatchesCorrectPage(): void
    {
        // Page 1100 is "EN: Welcome" (doktype=0, standard)
        $this->mergeSiteConfiguration('acme-com', [
            'routeEnhancers' => [
                'Enhancer' => self::getSimpleEnhancer([1100]),
            ],
        ]);

        $response = $this->executeFrontendSubRequest(
            new InternalRequest('https://acme.us/welcome/enhance/hello'),
            null,
            true
        );
        $pageArguments = json_decode((string)$response->getBody(), true);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(1100, $pageArguments['pageId']);
        self::assertSame(['parameter' => 'world'], $pageArguments['staticArguments']);
    }

    #[Test]
    public function enhancerWithIntegerLimitToPagesSkipsNonMatchingPage(): void
    {
        // limitToPages only includes 1200, so the enhancer should NOT match page 1100
        $this->mergeSiteConfiguration('acme-com', [
            'routeEnhancers' => [
                'Enhancer' => self::getSimpleEnhancer([1200]),
            ],
        ]);

        $response = $this->executeFrontendSubRequest(
            new InternalRequest('https://acme.us/welcome/enhance/hello'),
            null,
            true
        );

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function enhancerWithDoktypeExpressionMatchesStandardPage(): void
    {
        // Page 1100 has doktype=0 (standard). Expression matches all standard pages.
        $this->mergeSiteConfiguration('acme-com', [
            'routeEnhancers' => [
                'Enhancer' => self::getSimpleEnhancer(['page["doktype"] == 0']),
            ],
        ]);

        $response = $this->executeFrontendSubRequest(
            new InternalRequest('https://acme.us/welcome/enhance/hello'),
            null,
            true
        );
        $pageArguments = json_decode((string)$response->getBody(), true);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(1100, $pageArguments['pageId']);
        self::assertSame(['parameter' => 'world'], $pageArguments['staticArguments']);
    }

    #[Test]
    public function enhancerWithDoktypeExpressionDoesNotMatchWrongDoktype(): void
    {
        // Page 1100 has doktype=0. Expression looks for doktype=254 (folder), so it should not match.
        $this->mergeSiteConfiguration('acme-com', [
            'routeEnhancers' => [
                'Enhancer' => self::getSimpleEnhancer(['page["doktype"] == 254']),
            ],
        ]);

        $response = $this->executeFrontendSubRequest(
            new InternalRequest('https://acme.us/welcome/enhance/hello'),
            null,
            true
        );

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function enhancerWithMixedIntegersAndExpressionsMatchesViaInteger(): void
    {
        // Page 1100 matches via integer
        $this->mergeSiteConfiguration('acme-com', [
            'routeEnhancers' => [
                'Enhancer' => self::getSimpleEnhancer([1100, 'page["doktype"] == 254']),
            ],
        ]);

        $response = $this->executeFrontendSubRequest(
            new InternalRequest('https://acme.us/welcome/enhance/hello'),
            null,
            true
        );
        $pageArguments = json_decode((string)$response->getBody(), true);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(1100, $pageArguments['pageId']);
        self::assertSame(['parameter' => 'world'], $pageArguments['staticArguments']);
    }

    #[Test]
    public function enhancerWithMixedIntegersAndExpressionsMatchesViaExpression(): void
    {
        // Page 1100 does NOT match integer 9999, but matches the doktype expression
        $this->mergeSiteConfiguration('acme-com', [
            'routeEnhancers' => [
                'Enhancer' => self::getSimpleEnhancer([9999, 'page["doktype"] == 0']),
            ],
        ]);

        $response = $this->executeFrontendSubRequest(
            new InternalRequest('https://acme.us/welcome/enhance/hello'),
            null,
            true
        );
        $pageArguments = json_decode((string)$response->getBody(), true);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(1100, $pageArguments['pageId']);
        self::assertSame(['parameter' => 'world'], $pageArguments['staticArguments']);
    }

    #[Test]
    public function enhancerWithCompoundExpressionMatchesBothConditions(): void
    {
        // Page 1100 has doktype=0 and pid=1000. Both conditions must be true.
        $this->mergeSiteConfiguration('acme-com', [
            'routeEnhancers' => [
                'Enhancer' => self::getSimpleEnhancer(['page["doktype"] == 0 && page["pid"] == 1000']),
            ],
        ]);

        $response = $this->executeFrontendSubRequest(
            new InternalRequest('https://acme.us/welcome/enhance/hello'),
            null,
            true
        );
        $pageArguments = json_decode((string)$response->getBody(), true);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(1100, $pageArguments['pageId']);
        self::assertSame(['parameter' => 'world'], $pageArguments['staticArguments']);
    }

    #[Test]
    public function enhancerWithCompoundExpressionFailsWhenOneConditionDoesNotMatch(): void
    {
        // Page 1100 has doktype=0, but pid is 1000, not 9999. AND fails.
        $this->mergeSiteConfiguration('acme-com', [
            'routeEnhancers' => [
                'Enhancer' => self::getSimpleEnhancer(['page["doktype"] == 0 && page["pid"] == 9999']),
            ],
        ]);

        $response = $this->executeFrontendSubRequest(
            new InternalRequest('https://acme.us/welcome/enhance/hello'),
            null,
            true
        );

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function enhancerWithTitleExpressionMatchesPage(): void
    {
        // Page 1100 has title "EN: Welcome"
        $this->mergeSiteConfiguration('acme-com', [
            'routeEnhancers' => [
                'Enhancer' => self::getSimpleEnhancer(['page["title"] == "EN: Welcome"']),
            ],
        ]);

        $response = $this->executeFrontendSubRequest(
            new InternalRequest('https://acme.us/welcome/enhance/hello'),
            null,
            true
        );
        $pageArguments = json_decode((string)$response->getBody(), true);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(1100, $pageArguments['pageId']);
    }

    #[Test]
    public function enhancerWithoutLimitToPagesAppliesToAllPages(): void
    {
        // No limitToPages at all — enhancer should match any page
        $enhancer = self::getSimpleEnhancer([]);
        unset($enhancer['limitToPages']);
        $this->mergeSiteConfiguration('acme-com', [
            'routeEnhancers' => [
                'Enhancer' => $enhancer,
            ],
        ]);

        $response = $this->executeFrontendSubRequest(
            new InternalRequest('https://acme.us/welcome/enhance/hello'),
            null,
            true
        );
        $pageArguments = json_decode((string)$response->getBody(), true);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(1100, $pageArguments['pageId']);
        self::assertSame(['parameter' => 'world'], $pageArguments['staticArguments']);
    }

    #[Test]
    public function enhancerWithInvalidExpressionIsSkipped(): void
    {
        // Invalid expression is silently skipped, no integer matches either → 404
        $this->mergeSiteConfiguration('acme-com', [
            'routeEnhancers' => [
                'Enhancer' => self::getSimpleEnhancer(['this is not valid!!!']),
            ],
        ]);

        $response = $this->executeFrontendSubRequest(
            new InternalRequest('https://acme.us/welcome/enhance/hello'),
            null,
            true
        );

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function enhancerWithMultipleOrExpressionsMatchesOnFirst(): void
    {
        // Two expressions, first one matches (doktype=0), second doesn't (title mismatch)
        $this->mergeSiteConfiguration('acme-com', [
            'routeEnhancers' => [
                'Enhancer' => self::getSimpleEnhancer([
                    'page["doktype"] == 0',
                    'page["title"] == "Does not exist"',
                ]),
            ],
        ]);

        $response = $this->executeFrontendSubRequest(
            new InternalRequest('https://acme.us/welcome/enhance/hello'),
            null,
            true
        );
        $pageArguments = json_decode((string)$response->getBody(), true);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(1100, $pageArguments['pageId']);
    }
}
