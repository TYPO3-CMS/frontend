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

namespace TYPO3\CMS\Frontend\Tests\Unit\Controller;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Frontend\Controller\TypoScriptFrontendController;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class TypoScriptFrontendControllerTest extends UnitTestCase
{
    public static function baseUrlWrapHandlesDifferentUrlsDataProvider(): array
    {
        return [
            'without base url' => [
                '',
                'fileadmin/user_uploads/image.jpg',
                'fileadmin/user_uploads/image.jpg',
            ],
            'with base url' => [
                'http://www.google.com/',
                'fileadmin/user_uploads/image.jpg',
                'http://www.google.com/fileadmin/user_uploads/image.jpg',
            ],
            'without base url but with url prepended with a forward slash' => [
                '',
                '/fileadmin/user_uploads/image.jpg',
                '/fileadmin/user_uploads/image.jpg',
            ],
            'with base url but with url prepended with a forward slash' => [
                'http://www.google.com/',
                '/fileadmin/user_uploads/image.jpg',
                '/fileadmin/user_uploads/image.jpg',
            ],
        ];
    }

    #[DataProvider('baseUrlWrapHandlesDifferentUrlsDataProvider')]
    #[Test]
    public function baseUrlWrapHandlesDifferentUrls(string $baseUrl, string $url, string $expected): void
    {
        $subject = $this->getMockBuilder(TypoScriptFrontendController::class)->disableOriginalConstructor()->onlyMethods([])->getMock();
        $subject->config['config']['baseURL'] = $baseUrl;
        self::assertSame($expected, $subject->baseUrlWrap($url, true));
    }
}
