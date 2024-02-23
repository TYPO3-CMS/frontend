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

namespace TYPO3\CMS\Frontend\Tests\UnitDeprecated\Resource;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Frontend\Resource\FilePathSanitizer;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class FilePathSanitizerTest extends UnitTestCase
{
    #[Test]
    public function sanitizeCorrectlyResolvesPathsForLegacySystemsEvenForPrivateResources(): void
    {
        $subject = new FilePathSanitizer();
        self::assertSame('typo3/sysext/frontend/Resources/Private/Templates/MainPage.html', $subject->sanitize('EXT:frontend/Resources/Private/Templates/MainPage.html'));
    }
}
