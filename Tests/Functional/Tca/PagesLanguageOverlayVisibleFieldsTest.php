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

namespace TYPO3\CMS\Frontend\Tests\Functional\Tca;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Backend\Tests\Functional\Form\FormTestService;
use TYPO3\CMS\Core\Domain\Repository\PageRepository;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class PagesLanguageOverlayVisibleFieldsTest extends FunctionalTestCase
{
    /**
     * These form fields are visible in the default page types.
     */
    protected static array $defaultPagesLanguageOverlayFields = [
        'title',
        'nav_title',
        'subtitle',
        'hidden',
        'starttime',
        'endtime',
        'abstract',
        'keywords',
        'description',
        'author',
        'author_email',
        'media',
    ];

    /**
     * Configuration of hidden / additional form fields per page type.
     */
    protected static array $pageFormFields = [
        PageRepository::DOKTYPE_BE_USER_SECTION => [],
        PageRepository::DOKTYPE_DEFAULT => [],
        PageRepository::DOKTYPE_SHORTCUT => [
            'additionalFields' => [
                'shortcut_mode',
                'shortcut',
            ],
            'hiddenFields' => [
                'keywords',
                'description',
                'content_from_pid',
                'cache_timeout',
                'cache_tags',
                'module',
            ],
        ],
        PageRepository::DOKTYPE_MOUNTPOINT => [
            'hiddenFields' => [
                'keywords',
                'description',
                'content_from_pid',
                'cache_timeout',
                'cache_tags',
                'module',
            ],
        ],
        PageRepository::DOKTYPE_LINK => [
            'additionalFields' => [
                'url',
            ],
            'hiddenFields' => [
                'keywords',
                'description',
                'content_from_pid',
                'cache_timeout',
                'cache_tags',
                'module',
            ],
        ],
        PageRepository::DOKTYPE_SYSFOLDER => [
            'hiddenFields' => [
                'nav_title',
                'subtitle',
                'starttime',
                'endtime',
                'abstract',
                'keywords',
                'description',
                'author',
                'author_email',
            ],
        ],
        PageRepository::DOKTYPE_SPACER => [
            'hiddenFields' => [
                'nav_title',
                'subtitle',
                'abstract',
                'keywords',
                'description',
                'author',
                'author_email',
                'media',
            ],
        ],
    ];

    public static function pagesLanguageOverlayFormContainsExpectedFieldsDataProvider(): array
    {
        $pageTypes = [];

        foreach (static::$pageFormFields as $doktype => $fieldConfig) {
            $expectedFields = static::$defaultPagesLanguageOverlayFields;
            $hiddenFields = [];
            if (array_key_exists('additionalFields', $fieldConfig)) {
                $expectedFields = array_merge($expectedFields, $fieldConfig['additionalFields']);
            }
            if (array_key_exists('hiddenFields', $fieldConfig)) {
                $hiddenFields = $fieldConfig['hiddenFields'];
                $expectedFields = array_diff($expectedFields, $hiddenFields);
            }
            $pageTypes['page doktype ' . $doktype] = [$doktype, $expectedFields, $hiddenFields];
        }

        return $pageTypes;
    }

    #[DataProvider('pagesLanguageOverlayFormContainsExpectedFieldsDataProvider')]
    #[Test]
    public function pagesLanguageOverlayFormContainsExpectedFields(int $doktype, array $expectedFields, array $hiddenFields): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->create('default');

        $formEngineTestService = new FormTestService();
        $formResult = $formEngineTestService->createNewRecordForm('pages', ['doktype' => $doktype]);

        foreach ($expectedFields as $expectedField) {
            self::assertNotFalse(
                $formEngineTestService->formHtmlContainsField($expectedField, $formResult['html']),
                'The field ' . $expectedField . ' is not in the form HTML'
            );
        }

        foreach ($hiddenFields as $hiddenField) {
            self::assertFalse(
                $formEngineTestService->formHtmlContainsField($hiddenField, $formResult['html']),
                'The field ' . $hiddenField . ' is in the form HTML'
            );
        }
    }
}
