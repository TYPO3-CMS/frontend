<?php

defined('TYPO3') or die();

\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addRecordType(
    [
        'label' => 'LLL:EXT:frontend/Resources/Private/Language/locallang_ttc.xlf:CType.menu_related_pages',
        'description' => 'LLL:EXT:frontend/Resources/Private/Language/locallang_ttc.xlf:CType.menu_related_pages.description',
        'value' => 'menu_related_pages',
        'icon' => 'content-menu-related',
        'group' => 'menu',
    ],
    '
        --palette--;;headers,
        pages;LLL:EXT:frontend/Resources/Private/Language/locallang_ttc.xlf:pages.ALT.menu_formlabel,
    --div--;LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:appearance,
        --palette--;;frames,
        --palette--;;appearanceLinks,
    --div--;LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:categories,
        categories'
);
