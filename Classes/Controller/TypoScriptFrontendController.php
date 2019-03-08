<?php
namespace TYPO3\CMS\Frontend\Controller;

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

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Backend\FrontendBackendUserAuthentication;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Charset\CharsetConverter;
use TYPO3\CMS\Core\Charset\UnknownCharsetException;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\DateTimeAspect;
use TYPO3\CMS\Core\Context\LanguageAspect;
use TYPO3\CMS\Core\Context\LanguageAspectFactory;
use TYPO3\CMS\Core\Context\UserAspect;
use TYPO3\CMS\Core\Context\VisibilityAspect;
use TYPO3\CMS\Core\Context\WorkspaceAspect;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\EndTimeRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\StartTimeRestriction;
use TYPO3\CMS\Core\Error\Http\PageNotFoundException;
use TYPO3\CMS\Core\Error\Http\ServiceUnavailableException;
use TYPO3\CMS\Core\Error\Http\ShortcutTargetPageNotFoundException;
use TYPO3\CMS\Core\Exception\Page\RootLineException;
use TYPO3\CMS\Core\Http\ImmediateResponseException;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Localization\Locales;
use TYPO3\CMS\Core\Locking\Exception\LockAcquireWouldBlockException;
use TYPO3\CMS\Core\Locking\LockFactory;
use TYPO3\CMS\Core\Locking\LockingStrategyInterface;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\PageTitle\PageTitleProviderManager;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Routing\PageArguments;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\TimeTracker\TimeTracker;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\TypoScript\Parser\TypoScriptParser;
use TYPO3\CMS\Core\TypoScript\TemplateService;
use TYPO3\CMS\Core\Utility\ArrayUtility;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\HttpUtility;
use TYPO3\CMS\Core\Utility\MathUtility;
use TYPO3\CMS\Core\Utility\PathUtility;
use TYPO3\CMS\Core\Utility\RootlineUtility;
use TYPO3\CMS\Frontend\Authentication\FrontendUserAuthentication;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;
use TYPO3\CMS\Frontend\Page\PageAccessFailureReasons;
use TYPO3\CMS\Frontend\Page\PageRepository;
use TYPO3\CMS\Frontend\Resource\FilePathSanitizer;

/**
 * Class for the built TypoScript based frontend. Instantiated in
 * \TYPO3\CMS\Frontend\Http\RequestHandler as the global object TSFE.
 *
 * Main frontend class, instantiated in \TYPO3\CMS\Frontend\Http\RequestHandler
 * as the global object TSFE.
 *
 * This class has a lot of functions and internal variable which are used from
 * \TYPO3\CMS\Frontend\Http\RequestHandler
 *
 * The class is instantiated as $GLOBALS['TSFE'] in \TYPO3\CMS\Frontend\Http\RequestHandler.
 *
 * The use of this class should be inspired by the order of function calls as
 * found in \TYPO3\CMS\Frontend\Http\RequestHandler.
 */
class TypoScriptFrontendController implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /**
     * The page id (int)
     * @var string
     */
    public $id = '';

    /**
     * The type (read-only)
     * @var int
     */
    public $type = '';

    /**
     * The submitted cHash
     * @var string
     * @internal
     */
    public $cHash = '';

    /**
     * @var PageArguments
     * @internal
     */
    protected $pageArguments;

    /**
     * Page will not be cached. Write only TRUE. Never clear value (some other
     * code might have reasons to set it TRUE).
     * @var bool
     */
    public $no_cache = false;

    /**
     * The rootLine (all the way to tree root, not only the current site!)
     * @var array
     */
    public $rootLine = '';

    /**
     * The pagerecord
     * @var array
     */
    public $page = '';

    /**
     * This will normally point to the same value as id, but can be changed to
     * point to another page from which content will then be displayed instead.
     * @var int
     */
    public $contentPid = 0;

    /**
     * Gets set when we are processing a page of type mounpoint with enabled overlay in getPageAndRootline()
     * Used later in checkPageForMountpointRedirect() to determine the final target URL where the user
     * should be redirected to.
     *
     * @var array|null
     */
    protected $originalMountPointPage;

    /**
     * Gets set when we are processing a page of type shortcut in the early stages
     * of the request when we do not know about languages yet, used later in the request
     * to determine the correct shortcut in case a translation changes the shortcut
     * target
     * @var array|null
     * @see checkTranslatedShortcut()
     */
    protected $originalShortcutPage;

    /**
     * sys_page-object, pagefunctions
     *
     * @var PageRepository
     */
    public $sys_page = '';

    /**
     * Is set to 1 if a pageNotFound handler could have been called.
     * @var int
     * @internal
     */
    public $pageNotFound = 0;

    /**
     * Domain start page
     * @var int
     * @internal
     */
    public $domainStartPage = 0;

    /**
     * Array containing a history of why a requested page was not accessible.
     * @var array
     */
    protected $pageAccessFailureHistory = [];

    /**
     * @var string
     * @internal
     */
    public $MP = '';

    /**
     * The frontend user
     *
     * @var FrontendUserAuthentication
     */
    public $fe_user = '';

    /**
     * Shows whether logins are allowed in branch
     * @var bool
     */
    protected $loginAllowedInBranch = true;

    /**
     * Shows specific mode (all or groups)
     * @var string
     * @internal
     */
    protected $loginAllowedInBranch_mode = '';

    /**
     * Flag indication that preview is active. This is based on the login of a
     * backend user and whether the backend user has read access to the current
     * page.
     * @var int
     * @internal
     */
    public $fePreview = 0;

    /**
     * Value that contains the simulated usergroup if any
     * @var int
     * @internal only to be used in AdminPanel, and within TYPO3 Core
     */
    public $simUserGroup = 0;

    /**
     * "CONFIG" object from TypoScript. Array generated based on the TypoScript
     * configuration of the current page. Saved with the cached pages.
     * @var array
     */
    public $config = [];

    /**
     * The TypoScript template object. Used to parse the TypoScript template
     *
     * @var TemplateService
     */
    public $tmpl;

    /**
     * Is set to the time-to-live time of cached pages. If FALSE, default is
     * 60*60*24, which is 24 hours.
     * @var bool|int
     * @internal
     */
    protected $cacheTimeOutDefault = false;

    /**
     * Set internally if cached content is fetched from the database. No matter if it is temporary
     * content (tempContent) or already generated page content.
     *
     * @var bool
     * @internal
     */
    protected $cacheContentFlag = false;

    /**
     * Set to the expire time of cached content
     * @var int
     * @internal
     */
    protected $cacheExpires = 0;

    /**
     * Set if cache headers allowing caching are sent.
     * @var bool
     * @internal
     */
    protected $isClientCachable = false;

    /**
     * Used by template fetching system. This array is an identification of
     * the template. If $this->all is empty it's because the template-data is not
     * cached, which it must be.
     * @var array
     */
    public $all = [];

    /**
     * Toplevel - objArrayName, eg 'page'
     * @var string
     */
    public $sPre = '';

    /**
     * TypoScript configuration of the page-object pointed to by sPre.
     * $this->tmpl->setup[$this->sPre.'.']
     * @var array
     */
    public $pSetup = '';

    /**
     * This hash is unique to the template, the $this->id and $this->type vars and
     * the gr_list (list of groups). Used to get and later store the cached data
     * @var string
     * @internal
     */
    public $newHash = '';

    /**
     * If config.ftu (Frontend Track User) is set in TypoScript for the current
     * page, the string value of this var is substituted in the rendered source-code
     * with the string, '&ftu=[token...]' which enables GET-method usertracking as
     * opposed to cookie based
     * @var string
     * @internal
     */
    public $getMethodUrlIdToken = '';

    /**
     * This flag is set before inclusion of RequestHandler IF no_cache is set. If this
     * flag is set after the inclusion of RequestHandler, no_cache is forced to be set.
     * This is done in order to make sure that php-code from pagegen does not falsely
     * clear the no_cache flag.
     * @var bool
     * @internal
     */
    protected $no_cacheBeforePageGen = false;

    /**
     * This flag indicates if temporary content went into the cache during page-generation.
     * When the message is set to "this page is being generated", TYPO3 Frontend indicates this way
     * that the current page request is fully cached, and needs no page generation.
     * @var mixed
     * @internal
     */
    protected $tempContent = false;

    /**
     * Passed to TypoScript template class and tells it to force template rendering
     * @var bool
     */
    public $forceTemplateParsing = false;

    /**
     * The array which cHash_calc is based on, see PageArgumentValidator class.
     * @var array
     * @internal
     */
    public $cHash_array = [];

    /**
     * May be set to the pagesTSconfig
     * @var array
     * @internal
     */
    protected $pagesTSconfig = '';

    /**
     * Eg. insert JS-functions in this array ($additionalHeaderData) to include them
     * once. Use associative keys.
     *
     * Keys in use:
     *
     * used to accumulate additional HTML-code for the header-section,
     * <head>...</head>. Insert either associative keys (like
     * additionalHeaderData['myStyleSheet'], see reserved keys above) or num-keys
     * (like additionalHeaderData[] = '...')
     *
     * @var array
     */
    public $additionalHeaderData = [];

    /**
     * Used to accumulate additional HTML-code for the footer-section of the template
     * @var array
     */
    public $additionalFooterData = [];

    /**
     * Used to accumulate additional JavaScript-code. Works like
     * additionalHeaderData. Reserved keys at 'openPic' and 'mouseOver'
     *
     * @var array
     */
    public $additionalJavaScript = [];

    /**
     * Used to accumulate additional Style code. Works like additionalHeaderData.
     *
     * @var array
     */
    public $additionalCSS = [];

    /**
     * @var  string
     */
    public $JSCode;

    /**
     * @var string
     */
    public $inlineJS;

    /**
     * Used to accumulate DHTML-layers.
     * @var string
     */
    public $divSection = '';

    /**
     * Default internal target
     * @var string
     */
    public $intTarget = '';

    /**
     * Default external target
     * @var string
     */
    public $extTarget = '';

    /**
     * Default file link target
     * @var string
     */
    public $fileTarget = '';

    /**
     * If set, typolink() function encrypts email addresses. Is set in pagegen-class.
     * @var string|int
     */
    public $spamProtectEmailAddresses = 0;

    /**
     * Absolute Reference prefix
     * @var string
     */
    public $absRefPrefix = '';

    /**
     * <A>-tag parameters
     * @var string
     */
    public $ATagParams = '';

    /**
     * Search word regex, calculated if there has been search-words send. This is
     * used to mark up the found search words on a page when jumped to from a link
     * in a search-result.
     * @var string
     * @internal
     */
    public $sWordRegEx = '';

    /**
     * Is set to the incoming array sword_list in case of a page-view jumped to from
     * a search-result.
     * @var string
     * @internal
     */
    public $sWordList = '';

    /**
     * A string prepared for insertion in all links on the page as url-parameters.
     * Based on configuration in TypoScript where you defined which GET_VARS you
     * would like to pass on.
     * @var string
     */
    public $linkVars = '';

    /**
     * If set, edit icons are rendered aside content records. Must be set only if
     * the ->beUserLogin flag is set and set_no_cache() must be called as well.
     * @var string
     */
    public $displayEditIcons = '';

    /**
     * If set, edit icons are rendered aside individual fields of content. Must be
     * set only if the ->beUserLogin flag is set and set_no_cache() must be called as
     * well.
     * @var string
     */
    public $displayFieldEditIcons = '';

    /**
     * Is set to the iso code of the sys_language_content if that is properly defined
     * by the sys_language record representing the sys_language_uid.
     * @var string
     */
    public $sys_language_isocode = '';

    /**
     * 'Global' Storage for various applications. Keys should be 'tx_'.extKey for
     * extensions.
     * @var array
     */
    public $applicationData = [];

    /**
     * @var array
     */
    public $register = [];

    /**
     * Stack used for storing array and retrieving register arrays (see
     * LOAD_REGISTER and RESTORE_REGISTER)
     * @var array
     */
    public $registerStack = [];

    /**
     * Checking that the function is not called eternally. This is done by
     * interrupting at a depth of 50
     * @var int
     */
    public $cObjectDepthCounter = 50;

    /**
     * Used by RecordContentObject and ContentContentObject to ensure the a records is NOT
     * rendered twice through it!
     * @var array
     */
    public $recordRegister = [];

    /**
     * This is set to the [table]:[uid] of the latest record rendered. Note that
     * class ContentObjectRenderer has an equal value, but that is pointing to the
     * record delivered in the $data-array of the ContentObjectRenderer instance, if
     * the cObjects CONTENT or RECORD created that instance
     * @var string
     */
    public $currentRecord = '';

    /**
     * Used by class \TYPO3\CMS\Frontend\ContentObject\Menu\AbstractMenuContentObject
     * to keep track of access-keys.
     * @var array
     */
    public $accessKey = [];

    /**
     * Numerical array where image filenames are added if they are referenced in the
     * rendered document. This includes only TYPO3 generated/inserted images.
     * @var array
     */
    public $imagesOnPage = [];

    /**
     * Is set in ContentObjectRenderer->cImage() function to the info-array of the
     * most recent rendered image. The information is used in ImageTextContentObject
     * @var array
     */
    public $lastImageInfo = [];

    /**
     * Used to generate page-unique keys. Point is that uniqid() functions is very
     * slow, so a unikey key is made based on this, see function uniqueHash()
     * @var int
     * @internal
     */
    protected $uniqueCounter = 0;

    /**
     * @var string
     * @internal
     */
    protected $uniqueString = '';

    /**
     * This value will be used as the title for the page in the indexer (if
     * indexing happens)
     * @var string
     */
    public $indexedDocTitle = '';

    /**
     * The base URL set for the page header.
     * @var string
     */
    public $baseUrl = '';

    /**
     * Page content render object
     *
     * @var ContentObjectRenderer
     */
    public $cObj = '';

    /**
     * All page content is accumulated in this variable. See RequestHandler
     * @var string
     */
    public $content = '';

    /**
     * Output charset of the websites content. This is the charset found in the
     * header, meta tag etc. If different than utf-8 a conversion
     * happens before output to browser. Defaults to utf-8.
     * @var string
     */
    public $metaCharset = 'utf-8';

    /**
     * Internal calculations for labels
     *
     * @var LanguageService
     */
    protected $languageService;

    /**
     * @var LockingStrategyInterface[][]
     */
    protected $locks = [];

    /**
     * @var PageRenderer
     */
    protected $pageRenderer;

    /**
     * The page cache object, use this to save pages to the cache and to
     * retrieve them again
     *
     * @var \TYPO3\CMS\Core\Cache\Backend\AbstractBackend
     */
    protected $pageCache;

    /**
     * @var array
     */
    protected $pageCacheTags = [];

    /**
     * Content type HTTP header being sent in the request.
     * @todo Ticket: #63642 Should be refactored to a request/response model later
     * @internal Should only be used by TYPO3 core for now
     *
     * @var string
     */
    protected $contentType = 'text/html';

    /**
     * Doctype to use
     *
     * @var string
     */
    public $xhtmlDoctype = '';

    /**
     * @var int
     */
    public $xhtmlVersion;

    /**
     * Originally requested id from the initial $_GET variable
     *
     * @var int
     */
    protected $requestedId;

    /**
     * The context for keeping the current state, mostly related to current page information,
     * backend user / frontend user access, workspaceId
     *
     * @var Context
     */
    protected $context;

    /**
     * Class constructor
     * Takes a number of GET/POST input variable as arguments and stores them internally.
     * The processing of these variables goes on later in this class.
     * Also sets a unique string (->uniqueString) for this script instance; A md5 hash of the microtime()
     *
     * @param array $_ unused, previously defined to set TYPO3_CONF_VARS
     * @param mixed $id The value of GeneralUtility::_GP('id')
     * @param int $type The value of GeneralUtility::_GP('type')
     * @param bool|string $_1 unused, previously the value of GeneralUtility::_GP('no_cache')
     * @param string $cHash The value of GeneralUtility::_GP('cHash')
     * @param string $_2 previously was used to define the jumpURL
     * @param string $MP The value of GeneralUtility::_GP('MP')
     */
    public function __construct($_ = null, $id, $type, $_1 = null, $cHash = '', $_2 = null, $MP = '')
    {
        // Setting some variables:
        $this->id = $id;
        $this->type = $type;
        $this->cHash = $cHash;
        $this->MP = $GLOBALS['TYPO3_CONF_VARS']['FE']['enable_mount_pids'] ? (string)$MP : '';
        $this->uniqueString = md5(microtime());
        $this->initPageRenderer();
        $this->initCaches();
        // Use the global context for now
        $this->context = GeneralUtility::makeInstance(Context::class);
    }

    /**
     * Initializes the page renderer object
     */
    protected function initPageRenderer()
    {
        if ($this->pageRenderer !== null) {
            return;
        }
        $this->pageRenderer = GeneralUtility::makeInstance(PageRenderer::class);
        $this->pageRenderer->setTemplateFile('EXT:frontend/Resources/Private/Templates/MainPage.html');
    }

    /**
     * @param string $contentType
     * @internal Should only be used by TYPO3 core for now
     */
    public function setContentType($contentType)
    {
        $this->contentType = $contentType;
    }

    /********************************************
     *
     * Initializing, resolving page id
     *
     ********************************************/
    /**
     * Initializes the caching system.
     */
    protected function initCaches()
    {
        $this->pageCache = GeneralUtility::makeInstance(CacheManager::class)->getCache('cache_pages');
    }

    /**
     * Initializes the front-end user groups.
     * Sets frontend.user aspect based on front-end user status.
     */
    public function initUserGroups()
    {
        $userGroups = [0];
        // This affects the hidden-flag selecting the fe_groups for the user!
        $this->fe_user->showHiddenRecords = $this->context->getPropertyFromAspect('visibility', 'includeHiddenContent', false);
        // no matter if we have an active user we try to fetch matching groups which can be set without an user (simulation for instance!)
        $this->fe_user->fetchGroupData();
        $isUserAndGroupSet = is_array($this->fe_user->user) && !empty($this->fe_user->groupData['uid']);
        if ($isUserAndGroupSet) {
            // group -2 is not an existing group, but denotes a 'default' group when a user IS logged in.
            // This is used to let elements be shown for all logged in users!
            $userGroups[] = -2;
            $groupsFromUserRecord = $this->fe_user->groupData['uid'];
        } else {
            // group -1 is not an existing group, but denotes a 'default' group when not logged in.
            // This is used to let elements be hidden, when a user is logged in!
            $userGroups[] = -1;
            if ($this->loginAllowedInBranch) {
                // For cases where logins are not banned from a branch usergroups can be set based on IP masks so we should add the usergroups uids.
                $groupsFromUserRecord = $this->fe_user->groupData['uid'];
            } else {
                // Set to blank since we will NOT risk any groups being set when no logins are allowed!
                $groupsFromUserRecord = [];
            }
        }
        // Clean up.
        // Make unique and sort the groups
        $groupsFromUserRecord = array_unique($groupsFromUserRecord);
        if (!empty($groupsFromUserRecord) && !$this->loginAllowedInBranch_mode) {
            sort($groupsFromUserRecord);
            $userGroups = array_merge($userGroups, array_map('intval', $groupsFromUserRecord));
        }

        $this->context->setAspect('frontend.user', GeneralUtility::makeInstance(UserAspect::class, $this->fe_user ?: null, $userGroups));

        // For every 60 seconds the is_online timestamp for a logged-in user is updated
        if ($isUserAndGroupSet) {
            $this->fe_user->updateOnlineTimestamp();
        }

        $this->logger->debug('Valid usergroups for TSFE: ' . implode(',', $userGroups));
    }

    /**
     * Checking if a user is logged in or a group constellation different from "0,-1"
     *
     * @return bool TRUE if either a login user is found (array fe_user->user) OR if the gr_list is set to something else than '0,-1' (could be done even without a user being logged in!)
     */
    public function isUserOrGroupSet()
    {
        /** @var UserAspect $userAspect */
        $userAspect = $this->context->getAspect('frontend.user');
        return $userAspect->isUserOrGroupSet();
    }

    /**
     * Clears the preview-flags, sets sim_exec_time to current time.
     * Hidden pages must be hidden as default, $GLOBALS['SIM_EXEC_TIME'] is set to $GLOBALS['EXEC_TIME']
     * in bootstrap initializeGlobalTimeVariables(). Alter it by adding or subtracting seconds.
     */
    public function clear_preview()
    {
        if ($this->fePreview
            || $GLOBALS['EXEC_TIME'] !== $GLOBALS['SIM_EXEC_TIME']
            || $this->context->getPropertyFromAspect('visibility', 'includeHiddenPages', false)
            || $this->context->getPropertyFromAspect('visibility', 'includeHiddenContent', false)
        ) {
            $GLOBALS['SIM_EXEC_TIME'] = $GLOBALS['EXEC_TIME'];
            $GLOBALS['SIM_ACCESS_TIME'] = $GLOBALS['ACCESS_TIME'];
            $this->fePreview = 0;
            $this->context->setAspect('date', GeneralUtility::makeInstance(DateTimeAspect::class, new \DateTimeImmutable('@' . $GLOBALS['SIM_EXEC_TIME'])));
            $this->context->setAspect('visibility', GeneralUtility::makeInstance(VisibilityAspect::class));
        }
    }

    /**
     * Checks if a backend user is logged in
     *
     * @return bool whether a backend user is logged in
     */
    public function isBackendUserLoggedIn()
    {
        return (bool)$this->context->getPropertyFromAspect('backend.user', 'isLoggedIn', false);
    }

    /**
     * Determines the id and evaluates any preview settings
     * Basically this function is about determining whether a backend user is logged in,
     * if he has read access to the page and if he's previewing the page.
     * That all determines which id to show and how to initialize the id.
     */
    public function determineId()
    {
        // Call pre processing function for id determination
        foreach ($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['tslib/class.tslib_fe.php']['determineId-PreProcessing'] ?? [] as $functionReference) {
            $parameters = ['parentObject' => $this];
            GeneralUtility::callUserFunction($functionReference, $parameters, $this);
        }
        // If there is a Backend login we are going to check for any preview settings
        $originalFrontendUserGroups = $this->applyPreviewSettings($this->getBackendUser());
        // If the front-end is showing a preview, caching MUST be disabled.
        if ($this->fePreview) {
            $this->disableCache();
        }
        // Now, get the id, validate access etc:
        $this->fetch_the_id();
        // Check if backend user has read access to this page. If not, recalculate the id.
        if ($this->isBackendUserLoggedIn() && $this->fePreview && !$this->getBackendUser()->doesUserHaveAccess($this->page, Permission::PAGE_SHOW)) {
            // Resetting
            $this->clear_preview();
            $this->fe_user->user[$this->fe_user->usergroup_column] = $originalFrontendUserGroups;
            // Fetching the id again, now with the preview settings reset.
            $this->fetch_the_id();
        }
        // Checks if user logins are blocked for a certain branch and if so, will unset user login and re-fetch ID.
        $this->loginAllowedInBranch = $this->checkIfLoginAllowedInBranch();
        // Logins are not allowed, but there is a login, so will we run this.
        if (!$this->loginAllowedInBranch && $this->isUserOrGroupSet()) {
            if ($this->loginAllowedInBranch_mode === 'all') {
                // Clear out user and group:
                $this->fe_user->hideActiveLogin();
                $userGroups = [0, -1];
            } else {
                $userGroups = [0, -2];
            }
            $this->context->setAspect('frontend.user', GeneralUtility::makeInstance(UserAspect::class, $this->fe_user ?: null, $userGroups));
            // Fetching the id again, now with the preview settings reset.
            $this->fetch_the_id();
        }
        // Final cleaning.
        // Make sure it's an integer
        $this->id = ($this->contentPid = (int)$this->id);
        // Make sure it's an integer
        $this->type = (int)$this->type;
        // Call post processing function for id determination:
        $_params = ['pObj' => &$this];
        foreach ($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['tslib/class.tslib_fe.php']['determineId-PostProc'] ?? [] as $_funcRef) {
            GeneralUtility::callUserFunction($_funcRef, $_params, $this);
        }
    }

    /**
     * Evaluates admin panel or workspace settings to see if
     * visibility settings like
     * - $fePreview
     * - Visibility Aspect: includeHiddenPages
     * - Visibility Aspect: includeHiddenPontent
     * - $simUserGroup
     * should be applied to the current object.
     *
     * @param FrontendBackendUserAuthentication $backendUser
     * @return string|null null if no changes to the current frontend usergroups have been made, otherwise the original list of frontend usergroups
     * @internal
     */
    protected function applyPreviewSettings($backendUser = null)
    {
        if (!$backendUser) {
            return null;
        }
        $originalFrontendUserGroup = null;
        if ($this->fe_user->user) {
            $originalFrontendUserGroup = $this->fe_user->user[$this->fe_user->usergroup_column];
        }

        // The preview flag is set if the current page turns out to be hidden
        if ($this->id && $this->determineIdIsHiddenPage()) {
            $this->fePreview = 1;
            /** @var VisibilityAspect $aspect */
            $aspect = $this->context->getAspect('visibility');
            $newAspect = GeneralUtility::makeInstance(VisibilityAspect::class, true, $aspect->includeHiddenContent(), $aspect->includeDeletedRecords());
            $this->context->setAspect('visibility', $newAspect);
        }
        // The preview flag will be set if an offline workspace will be previewed
        if ($this->whichWorkspace() > 0) {
            $this->fePreview = 1;
        }
        return $this->simUserGroup ? $originalFrontendUserGroup : null;
    }

    /**
     * Checks if the page is hidden in the active workspace.
     * If it is hidden, preview flags will be set.
     *
     * @return bool
     */
    protected function determineIdIsHiddenPage()
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('pages');
        $queryBuilder
            ->getRestrictions()
            ->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        $page = $queryBuilder
            ->select('uid', 'hidden', 'starttime', 'endtime')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($this->id, \PDO::PARAM_INT)),
                $queryBuilder->expr()->gte('pid', $queryBuilder->createNamedParameter(0, \PDO::PARAM_INT))
            )
            ->setMaxResults(1)
            ->execute()
            ->fetch();

        if ($this->whichWorkspace() > 0) {
            // Fetch overlay of page if in workspace and check if it is hidden
            $customContext = clone $this->context;
            $customContext->setAspect('workspace', GeneralUtility::makeInstance(WorkspaceAspect::class, $this->whichWorkspace()));
            $customContext->setAspect('visibility', GeneralUtility::makeInstance(VisibilityAspect::class));
            $pageSelectObject = GeneralUtility::makeInstance(PageRepository::class, $customContext);
            $targetPage = $pageSelectObject->getWorkspaceVersionOfRecord($this->whichWorkspace(), 'pages', $page['uid']);
            $result = $targetPage === -1 || $targetPage === -2;
        } else {
            $result = is_array($page) && ($page['hidden'] || $page['starttime'] > $GLOBALS['SIM_EXEC_TIME'] || $page['endtime'] != 0 && $page['endtime'] <= $GLOBALS['SIM_EXEC_TIME']);
        }
        return $result;
    }

    /**
     * Resolves the page id and sets up several related properties.
     *
     * If $this->id is not set at all or is not a plain integer, the method
     * does it's best to set the value to an integer. Resolving is based on
     * this options:
     *
     * - Splitting $this->id if it contains an additional type parameter.
     * - Finding the domain record start page
     * - First visible page
     * - Relocating the id below the domain record if outside
     *
     * The following properties may be set up or updated:
     *
     * - id
     * - requestedId
     * - type
     * - domainStartPage
     * - sys_page
     * - sys_page->where_groupAccess
     * - sys_page->where_hid_del
     * - Context: FrontendUser Aspect
     * - no_cache
     * - register['SYS_LASTCHANGED']
     * - pageNotFound
     *
     * Via getPageAndRootlineWithDomain()
     *
     * - rootLine
     * - page
     * - MP
     * - originalShortcutPage
     * - originalMountPointPage
     * - pageAccessFailureHistory['direct_access']
     * - pageNotFound
     *
     * @todo:
     *
     * On the first impression the method does to much. This is increased by
     * the fact, that is is called repeated times by the method determineId.
     * The reasons are manifold.
     *
     * 1.) The first part, the creation of sys_page and the type
     * resolution don't need to be repeated. They could be separated to be
     * called only once.
     *
     * 2.) The user group setup could be done once on a higher level.
     *
     * 3.) The workflow of the resolution could be elaborated to be less
     * tangled. Maybe the check of the page id to be below the domain via the
     * root line doesn't need to be done each time, but for the final result
     * only.
     *
     * 4.) The root line does not need to be directly addressed by this class.
     * A root line is always related to one page. The rootline could be handled
     * indirectly by page objects. Page objects still don't exist.
     *
     * @throws ServiceUnavailableException
     * @internal
     */
    public function fetch_the_id()
    {
        $timeTracker = $this->getTimeTracker();
        $timeTracker->push('fetch_the_id initialize/');
        // Set the valid usergroups for FE
        $this->initUserGroups();
        // Initialize the PageRepository has to be done after the frontend usergroups are initialized / resolved, as
        // frontend group aspect is modified before
        $this->sys_page = GeneralUtility::makeInstance(PageRepository::class, $this->context);
        // The id and type is set to the integer-value - just to be sure...
        $this->id = (int)$this->id;
        $this->type = (int)$this->type;
        $timeTracker->pull();
        // We find the first page belonging to the current domain
        $timeTracker->push('fetch_the_id domain/');
        if (!$this->id) {
            if ($this->domainStartPage) {
                // If the id was not previously set, set it to the id of the domain.
                $this->id = $this->domainStartPage;
            } else {
                // Find the first 'visible' page in that domain
                $rootLevelPages = $this->sys_page->getMenu([0], 'uid', 'sorting', '', false);
                if (!empty($rootLevelPages)) {
                    $theFirstPage = reset($rootLevelPages);
                    $this->id = $theFirstPage['uid'];
                } else {
                    $message = 'No pages are found on the rootlevel!';
                    $this->logger->alert($message);
                    try {
                        $response = GeneralUtility::makeInstance(ErrorController::class)->unavailableAction(
                            $GLOBALS['TYPO3_REQUEST'],
                            $message,
                            ['code' => PageAccessFailureReasons::NO_PAGES_FOUND]
                        );
                        throw new ImmediateResponseException($response, 1533931299);
                    } catch (ServiceUnavailableException $e) {
                        throw new ServiceUnavailableException($message, 1301648975);
                    }
                }
            }
        }
        $timeTracker->pull();
        $timeTracker->push('fetch_the_id rootLine/');
        // We store the originally requested id
        $this->requestedId = $this->id;
        try {
            $this->getPageAndRootlineWithDomain($this->domainStartPage);
        } catch (ShortcutTargetPageNotFoundException $e) {
            $this->pageNotFound = 1;
        }
        $timeTracker->pull();
        if ($this->pageNotFound) {
            switch ($this->pageNotFound) {
                case 1:
                    $response = GeneralUtility::makeInstance(ErrorController::class)->accessDeniedAction(
                        $GLOBALS['TYPO3_REQUEST'],
                        'ID was not an accessible page',
                        $this->getPageAccessFailureReasons(PageAccessFailureReasons::ACCESS_DENIED_PAGE_NOT_RESOLVED)
                    );
                    break;
                case 2:
                    $response = GeneralUtility::makeInstance(ErrorController::class)->accessDeniedAction(
                        $GLOBALS['TYPO3_REQUEST'],
                        'Subsection was found and not accessible',
                        $this->getPageAccessFailureReasons(PageAccessFailureReasons::ACCESS_DENIED_SUBSECTION_NOT_RESOLVED)
                    );
                    break;
                case 3:
                    $response = GeneralUtility::makeInstance(ErrorController::class)->pageNotFoundAction(
                        $GLOBALS['TYPO3_REQUEST'],
                        'ID was outside the domain',
                        $this->getPageAccessFailureReasons(PageAccessFailureReasons::ACCESS_DENIED_HOST_PAGE_MISMATCH)
                    );
                    break;
                default:
                    $response = GeneralUtility::makeInstance(ErrorController::class)->pageNotFoundAction(
                        $GLOBALS['TYPO3_REQUEST'],
                        'Unspecified error',
                        $this->getPageAccessFailureReasons()
                    );
            }
            throw new ImmediateResponseException($response, 1533931329);
        }
        // Init SYS_LASTCHANGED
        $this->register['SYS_LASTCHANGED'] = (int)$this->page['tstamp'];
        if ($this->register['SYS_LASTCHANGED'] < (int)$this->page['SYS_LASTCHANGED']) {
            $this->register['SYS_LASTCHANGED'] = (int)$this->page['SYS_LASTCHANGED'];
        }
        foreach ($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['tslib/class.tslib_fe.php']['fetchPageId-PostProcessing'] ?? [] as $functionReference) {
            $parameters = ['parentObject' => $this];
            GeneralUtility::callUserFunction($functionReference, $parameters, $this);
        }
    }

    /**
     * Loads the page and root line records based on $this->id
     *
     * A final page and the matching root line are determined and loaded by
     * the algorithm defined by this method.
     *
     * First it loads the initial page from the page repository for $this->id.
     * If that can't be loaded directly, it gets the root line for $this->id.
     * It walks up the root line towards the root page until the page
     * repository can deliver a page record. (The loading restrictions of
     * the root line records are more liberal than that of the page record.)
     *
     * Now the page type is evaluated and handled if necessary. If the page is
     * a short cut, it is replaced by the target page. If the page is a mount
     * point in overlay mode, the page is replaced by the mounted page.
     *
     * After this potential replacements are done, the root line is loaded
     * (again) for this page record. It walks up the root line up to
     * the first viewable record.
     *
     * (While upon the first accessibility check of the root line it was done
     * by loading page by page from the page repository, this time the method
     * checkRootlineForIncludeSection() is used to find the most distant
     * accessible page within the root line.)
     *
     * Having found the final page id, the page record and the root line are
     * loaded for last time by this method.
     *
     * Exceptions may be thrown for DOKTYPE_SPACER and not loadable page records
     * or root lines.
     *
     * If $GLOBALS['TYPO3_CONF_VARS']['FE']['pageNotFound_handling'] is set,
     * instead of throwing an exception it's handled by a page unavailable
     * handler.
     *
     * May set or update this properties:
     *
     * @see TypoScriptFrontendController::$id
     * @see TypoScriptFrontendController::$MP
     * @see TypoScriptFrontendController::$page
     * @see TypoScriptFrontendController::$pageNotFound
     * @see TypoScriptFrontendController::$pageAccessFailureHistory
     * @see TypoScriptFrontendController::$originalMountPointPage
     * @see TypoScriptFrontendController::$originalShortcutPage
     *
     * @throws ServiceUnavailableException
     * @throws PageNotFoundException
     */
    protected function getPageAndRootline()
    {
        $this->resolveTranslatedPageId();
        if (empty($this->page)) {
            // If no page, we try to find the page before in the rootLine.
            // Page is 'not found' in case the id itself was not an accessible page. code 1
            $this->pageNotFound = 1;
            try {
                $this->rootLine = GeneralUtility::makeInstance(RootlineUtility::class, $this->id, $this->MP, $this->context)->get();
                if (!empty($this->rootLine)) {
                    $c = count($this->rootLine) - 1;
                    while ($c > 0) {
                        // Add to page access failure history:
                        $this->pageAccessFailureHistory['direct_access'][] = $this->rootLine[$c];
                        // Decrease to next page in rootline and check the access to that, if OK, set as page record and ID value.
                        $c--;
                        $this->id = $this->rootLine[$c]['uid'];
                        $this->page = $this->sys_page->getPage($this->id);
                        if (!empty($this->page)) {
                            break;
                        }
                    }
                }
            } catch (RootLineException $e) {
                $this->rootLine = [];
            }
            // If still no page...
            if (empty($this->page)) {
                $message = 'The requested page does not exist!';
                $this->logger->error($message);
                try {
                    $response = GeneralUtility::makeInstance(ErrorController::class)->pageNotFoundAction(
                        $GLOBALS['TYPO3_REQUEST'],
                        $message,
                        $this->getPageAccessFailureReasons(PageAccessFailureReasons::PAGE_NOT_FOUND)
                    );
                    throw new ImmediateResponseException($response, 1533931330);
                } catch (PageNotFoundException $e) {
                    throw new PageNotFoundException($message, 1301648780);
                }
            }
        }
        // Spacer is not accessible in frontend
        if ($this->page['doktype'] == PageRepository::DOKTYPE_SPACER) {
            $message = 'The requested page does not exist!';
            $this->logger->error($message);
            try {
                $response = GeneralUtility::makeInstance(ErrorController::class)->pageNotFoundAction(
                    $GLOBALS['TYPO3_REQUEST'],
                    $message,
                    $this->getPageAccessFailureReasons(PageAccessFailureReasons::ACCESS_DENIED_INVALID_PAGETYPE)
                );
                throw new ImmediateResponseException($response, 1533931343);
            } catch (PageNotFoundException $e) {
                throw new PageNotFoundException($message, 1301648781);
            }
        }
        // Is the ID a link to another page??
        if ($this->page['doktype'] == PageRepository::DOKTYPE_SHORTCUT) {
            // We need to clear MP if the page is a shortcut. Reason is if the short cut goes to another page, then we LEAVE the rootline which the MP expects.
            $this->MP = '';
            // saving the page so that we can check later - when we know
            // about languages - whether we took the correct shortcut or
            // whether a translation of the page overwrites the shortcut
            // target and we need to follow the new target
            $this->originalShortcutPage = $this->page;
            $this->page = $this->sys_page->getPageShortcut($this->page['shortcut'], $this->page['shortcut_mode'], $this->page['uid']);
            $this->id = $this->page['uid'];
        }
        // If the page is a mountpoint which should be overlaid with the contents of the mounted page,
        // it must never be accessible directly, but only in the mountpoint context. Therefore we change
        // the current ID and the user is redirected by checkPageForMountpointRedirect().
        if ($this->page['doktype'] == PageRepository::DOKTYPE_MOUNTPOINT && $this->page['mount_pid_ol']) {
            $this->originalMountPointPage = $this->page;
            $this->page = $this->sys_page->getPage($this->page['mount_pid']);
            if (empty($this->page)) {
                $message = 'This page (ID ' . $this->originalMountPointPage['uid'] . ') is of type "Mount point" and '
                    . 'mounts a page which is not accessible (ID ' . $this->originalMountPointPage['mount_pid'] . ').';
                throw new PageNotFoundException($message, 1402043263);
            }
            if ($this->MP === '') {
                $this->MP = $this->page['uid'] . '-' . $this->originalMountPointPage['uid'];
            } else {
                $this->MP .= ',' . $this->page['uid'] . '-' . $this->originalMountPointPage['uid'];
            }
            $this->id = $this->page['uid'];
        }
        // Gets the rootLine
        try {
            $this->rootLine = GeneralUtility::makeInstance(RootlineUtility::class, $this->id, $this->MP, $this->context)->get();
        } catch (RootLineException $e) {
            $this->rootLine = [];
        }
        // If not rootline we're off...
        if (empty($this->rootLine)) {
            $message = 'The requested page didn\'t have a proper connection to the tree-root!';
            $this->logger->error($message);
            try {
                $response = GeneralUtility::makeInstance(ErrorController::class)->unavailableAction(
                    $GLOBALS['TYPO3_REQUEST'],
                    $message,
                    $this->getPageAccessFailureReasons(PageAccessFailureReasons::ROOTLINE_BROKEN)
                );
                throw new ImmediateResponseException($response, 1533931350);
            } catch (ServiceUnavailableException $e) {
                throw new ServiceUnavailableException($message, 1301648167);
            }
        }
        // Checking for include section regarding the hidden/starttime/endtime/fe_user (that is access control of a whole subbranch!)
        if ($this->checkRootlineForIncludeSection()) {
            if (empty($this->rootLine)) {
                $message = 'The requested page was not accessible!';
                try {
                    $response = GeneralUtility::makeInstance(ErrorController::class)->unavailableAction(
                        $GLOBALS['TYPO3_REQUEST'],
                        $message,
                        $this->getPageAccessFailureReasons(PageAccessFailureReasons::ACCESS_DENIED_GENERAL)
                    );
                    throw new ImmediateResponseException($response, 1533931351);
                } catch (ServiceUnavailableException $e) {
                    $this->logger->warning($message);
                    throw new ServiceUnavailableException($message, 1301648234);
                }
            } else {
                $el = reset($this->rootLine);
                $this->id = $el['uid'];
                $this->page = $this->sys_page->getPage($this->id);
                try {
                    $this->rootLine = GeneralUtility::makeInstance(RootlineUtility::class, $this->id, $this->MP, $this->context)->get();
                } catch (RootLineException $e) {
                    $this->rootLine = [];
                }
            }
        }
    }

    /**
     * If $this->id contains a translated page record, this needs to be resolved to the default language
     * in order for all rootline functionality and access restrictions to be in place further on.
     *
     * Additionally, if a translated page is found, $this->sys_language_uid/sys_language_content is set as well.
     */
    protected function resolveTranslatedPageId()
    {
        $this->page = $this->sys_page->getPage($this->id);
        // Accessed a default language page record, nothing to resolve
        if (empty($this->page) || (int)$this->page[$GLOBALS['TCA']['pages']['ctrl']['languageField']] === 0) {
            return;
        }
        $languageId = (int)$this->page[$GLOBALS['TCA']['pages']['ctrl']['languageField']];
        $this->page = $this->sys_page->getPage($this->page[$GLOBALS['TCA']['pages']['ctrl']['transOrigPointerField']]);
        $this->context->setAspect('language', GeneralUtility::makeInstance(LanguageAspect::class, $languageId));
        $this->id = $this->page['uid'];
        // For common best-practice reasons, this is set, however, will be optional for new routing mechanisms
        if (!$this->getCurrentSiteLanguage()) {
            $_GET['L'] = $languageId;
            $GLOBALS['HTTP_GET_VARS']['L'] = $languageId;
        }
    }

    /**
     * Checks if visibility of the page is blocked upwards in the root line.
     *
     * If any page in the root line is blocking visibility, true is returend.
     *
     * All pages from the blocking page downwards are removed from the root
     * line, so that the remaining pages can be used to relocate the page up
     * to lowest visible page.
     *
     * The blocking feature of a page must be turned on by setting the page
     * record field 'extendToSubpages' to 1 in case of hidden, starttime,
     * endtime or fe_group restrictions.
     *
     * Additionally this method checks for backend user sections in root line
     * and if found evaluates if a backend user is logged in and has access.
     *
     * Recyclers are also checked and trigger page not found if found in root
     * line.
     *
     * @todo Find a better name, i.e. checkVisibilityByRootLine
     * @todo Invert boolean return value. Return true if visible.
     *
     * @return bool
     */
    protected function checkRootlineForIncludeSection(): bool
    {
        $c = count($this->rootLine);
        $removeTheRestFlag = false;
        for ($a = 0; $a < $c; $a++) {
            if (!$this->checkPagerecordForIncludeSection($this->rootLine[$a])) {
                // Add to page access failure history:
                $this->pageAccessFailureHistory['sub_section'][] = $this->rootLine[$a];
                $removeTheRestFlag = true;
            }

            if ($this->rootLine[$a]['doktype'] == PageRepository::DOKTYPE_BE_USER_SECTION) {
                // If there is a backend user logged in, check if he has read access to the page:
                if ($this->isBackendUserLoggedIn()) {
                    $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
                        ->getQueryBuilderForTable('pages');

                    $queryBuilder
                        ->getRestrictions()
                        ->removeAll();

                    $row = $queryBuilder
                        ->select('uid')
                        ->from('pages')
                        ->where(
                            $queryBuilder->expr()->eq(
                                'uid',
                                $queryBuilder->createNamedParameter($this->id, \PDO::PARAM_INT)
                            ),
                            $this->getBackendUser()->getPagePermsClause(Permission::PAGE_SHOW)
                        )
                        ->execute()
                        ->fetch();

                    // versionOL()?
                    if (!$row) {
                        // If there was no page selected, the user apparently did not have read access to the current PAGE (not position in rootline) and we set the remove-flag...
                        $removeTheRestFlag = true;
                    }
                } else {
                    // Don't go here, if there is no backend user logged in.
                    $removeTheRestFlag = true;
                }
            } elseif ((int)$this->rootLine[$a]['doktype'] === PageRepository::DOKTYPE_RECYCLER) {
                // page is in a recycler
                $removeTheRestFlag = true;
            }
            if ($removeTheRestFlag) {
                // Page is 'not found' in case a subsection was found and not accessible, code 2
                $this->pageNotFound = 2;
                unset($this->rootLine[$a]);
            }
        }
        return $removeTheRestFlag;
    }

    /**
     * Checks page record for enableFields
     * Returns TRUE if enableFields does not disable the page record.
     * Takes notice of the includeHiddenPages visibility aspect flag and uses SIM_ACCESS_TIME for start/endtime evaluation
     *
     * @param array $row The page record to evaluate (needs fields: hidden, starttime, endtime, fe_group)
     * @param bool $bypassGroupCheck Bypass group-check
     * @return bool TRUE, if record is viewable.
     * @see \TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer::getTreeList(), checkPagerecordForIncludeSection()
     */
    public function checkEnableFields($row, $bypassGroupCheck = false)
    {
        $_params = ['pObj' => $this, 'row' => &$row, 'bypassGroupCheck' => &$bypassGroupCheck];
        foreach ($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['tslib/class.tslib_fe.php']['hook_checkEnableFields'] ?? [] as $_funcRef) {
            // Call hooks: If one returns FALSE, method execution is aborted with result "This record is not available"
            $return = GeneralUtility::callUserFunction($_funcRef, $_params, $this);
            if ($return === false) {
                return false;
            }
        }
        if ((!$row['hidden'] || $this->context->getPropertyFromAspect('visibility', 'includeHiddenPages', false))
            && $row['starttime'] <= $GLOBALS['SIM_ACCESS_TIME']
            && ($row['endtime'] == 0 || $row['endtime'] > $GLOBALS['SIM_ACCESS_TIME'])
            && ($bypassGroupCheck || $this->checkPageGroupAccess($row))) {
            return true;
        }
        return false;
    }

    /**
     * Check group access against a page record
     *
     * @param array $row The page record to evaluate (needs field: fe_group)
     * @return bool TRUE, if group access is granted.
     * @internal
     */
    public function checkPageGroupAccess($row)
    {
        /** @var UserAspect $userAspect */
        $userAspect = $this->context->getAspect('frontend.user');
        $pageGroupList = explode(',', $row['fe_group'] ?: 0);
        return count(array_intersect($userAspect->getGroupIds(), $pageGroupList)) > 0;
    }

    /**
     * Checks if the current page of the root line is visible.
     *
     * If the field extendToSubpages is 0, access is granted,
     * else the fields hidden, starttime, endtime, fe_group are evaluated.
     *
     * @todo Find a better name, i.e. isVisibleRecord()
     *
     * @param array $row The page record
     * @return bool true if visible
     * @internal
     * @see checkEnableFields()
     * @see \TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer::getTreeList()
     * @see checkRootlineForIncludeSection()
     */
    public function checkPagerecordForIncludeSection(array $row): bool
    {
        return !$row['extendToSubpages'] || $this->checkEnableFields($row);
    }

    /**
     * Checks if logins are allowed in the current branch of the page tree. Traverses the full root line and returns TRUE if logins are OK, otherwise FALSE (and then the login user must be unset!)
     *
     * @return bool returns TRUE if logins are OK, otherwise FALSE (and then the login user must be unset!)
     */
    public function checkIfLoginAllowedInBranch()
    {
        // Initialize:
        $c = count($this->rootLine);
        $loginAllowed = true;
        // Traverse root line from root and outwards:
        for ($a = 0; $a < $c; $a++) {
            // If a value is set for login state:
            if ($this->rootLine[$a]['fe_login_mode'] > 0) {
                // Determine state from value:
                if ((int)$this->rootLine[$a]['fe_login_mode'] === 1) {
                    $loginAllowed = false;
                    $this->loginAllowedInBranch_mode = 'all';
                } elseif ((int)$this->rootLine[$a]['fe_login_mode'] === 3) {
                    $loginAllowed = false;
                    $this->loginAllowedInBranch_mode = 'groups';
                } else {
                    $loginAllowed = true;
                }
            }
        }
        return $loginAllowed;
    }

    /**
     * Analysing $this->pageAccessFailureHistory into a summary array telling which features disabled display and on which pages and conditions. That data can be used inside a page-not-found handler
     *
     * @param string $failureReasonCode the error code to be attached (optional), see PageAccessFailureReasons list for details
     * @return array Summary of why page access was not allowed.
     */
    public function getPageAccessFailureReasons(string $failureReasonCode = null)
    {
        $output = [];
        if ($failureReasonCode) {
            $output['code'] = $failureReasonCode;
        }
        $combinedRecords = array_merge(is_array($this->pageAccessFailureHistory['direct_access']) ? $this->pageAccessFailureHistory['direct_access'] : [['fe_group' => 0]], is_array($this->pageAccessFailureHistory['sub_section']) ? $this->pageAccessFailureHistory['sub_section'] : []);
        if (!empty($combinedRecords)) {
            foreach ($combinedRecords as $k => $pagerec) {
                // If $k=0 then it is the very first page the original ID was pointing at and that will get a full check of course
                // If $k>0 it is parent pages being tested. They are only significant for the access to the first page IF they had the extendToSubpages flag set, hence checked only then!
                if (!$k || $pagerec['extendToSubpages']) {
                    if ($pagerec['hidden']) {
                        $output['hidden'][$pagerec['uid']] = true;
                    }
                    if ($pagerec['starttime'] > $GLOBALS['SIM_ACCESS_TIME']) {
                        $output['starttime'][$pagerec['uid']] = $pagerec['starttime'];
                    }
                    if ($pagerec['endtime'] != 0 && $pagerec['endtime'] <= $GLOBALS['SIM_ACCESS_TIME']) {
                        $output['endtime'][$pagerec['uid']] = $pagerec['endtime'];
                    }
                    if (!$this->checkPageGroupAccess($pagerec)) {
                        $output['fe_group'][$pagerec['uid']] = $pagerec['fe_group'];
                    }
                }
            }
        }
        return $output;
    }

    /**
     * Gets ->page and ->rootline information based on ->id. ->id may change during this operation.
     * If not inside domain, then default to first page in domain.
     *
     * @param int $domainStartPage Page uid of the page where the found domain record is (pid of the domain record)
     * @internal
     */
    public function getPageAndRootlineWithDomain($domainStartPage)
    {
        $this->getPageAndRootline();
        // Checks if the $domain-startpage is in the rootLine. This is necessary so that references to page-id's from other domains are not possible.
        if ($domainStartPage && is_array($this->rootLine)) {
            $idFound = false;
            foreach ($this->rootLine as $key => $val) {
                if ($val['uid'] == $domainStartPage) {
                    $idFound = true;
                    break;
                }
            }
            if (!$idFound) {
                // Page is 'not found' in case the id was outside the domain, code 3
                $this->pageNotFound = 3;
                $this->id = $domainStartPage;
                // re-get the page and rootline if the id was not found.
                $this->getPageAndRootline();
            }
        }
    }

    /********************************************
     *
     * Template and caching related functions.
     *
     *******************************************/

    /**
     * Will disable caching if the cHash value was not set when having dynamic arguments in GET query parameters.
     * This function should be called to check the _existence_ of "&cHash" whenever a plugin generating cacheable output is using extra GET variables. If there _is_ a cHash value the validation of it automatically takes place in makeCacheHash() (see above)
     *
     * @see \TYPO3\CMS\Frontend\Plugin\AbstractPlugin::pi_cHashCheck()
     */
    public function reqCHash()
    {
        $skip = $this->pageArguments !== null && empty($this->pageArguments->getDynamicArguments());
        if ($this->cHash || $skip) {
            return;
        }
        if ($GLOBALS['TYPO3_CONF_VARS']['FE']['pageNotFoundOnCHashError']) {
            if ($this->tempContent) {
                $this->clearPageCacheContent();
            }
            $response = GeneralUtility::makeInstance(ErrorController::class)->pageNotFoundAction(
                $GLOBALS['TYPO3_REQUEST'],
                'Request parameters could not be validated (&cHash empty)',
                ['code' => PageAccessFailureReasons::CACHEHASH_EMPTY]
            );
            throw new ImmediateResponseException($response, 1533931354);
        }
        $this->disableCache();
        $this->getTimeTracker()->setTSlogMessage('TSFE->reqCHash(): No &cHash parameter was sent for GET vars though required so caching is disabled', 2);
    }

    /**
     * @param PageArguments $pageArguments
     * @internal
     */
    public function setPageArguments(PageArguments $pageArguments)
    {
        $this->pageArguments = $pageArguments;
    }

    /**
     * See if page is in cache and get it if so
     * Stores the page content in $this->content if something is found.
     *
     * @throws \InvalidArgumentException
     * @throws \RuntimeException
     */
    public function getFromCache()
    {
        // clearing the content-variable, which will hold the pagecontent
        $this->content = '';
        // Unsetting the lowlevel config
        $this->config = [];
        $this->cacheContentFlag = false;

        if ($this->no_cache) {
            return;
        }

        if (!($this->tmpl instanceof TemplateService)) {
            $this->tmpl = GeneralUtility::makeInstance(TemplateService::class, $this->context);
        }

        $pageSectionCacheContent = $this->tmpl->getCurrentPageData();
        if (!is_array($pageSectionCacheContent)) {
            // Nothing in the cache, we acquire an "exclusive lock" for the key now.
            // We use the Registry to store this lock centrally,
            // but we protect the access again with a global exclusive lock to avoid race conditions

            $this->acquireLock('pagesection', $this->id . '::' . $this->MP);
            //
            // from this point on we're the only one working on that page ($key)
            //

            // query the cache again to see if the page data are there meanwhile
            $pageSectionCacheContent = $this->tmpl->getCurrentPageData();
            if (is_array($pageSectionCacheContent)) {
                // we have the content, nice that some other process did the work for us already
                $this->releaseLock('pagesection');
            }
            // We keep the lock set, because we are the ones generating the page now
                // and filling the cache.
                // This indicates that we have to release the lock in the Registry later in releaseLocks()
        }

        if (is_array($pageSectionCacheContent)) {
            // BE CAREFUL to change the content of the cc-array. This array is serialized and an md5-hash based on this is used for caching the page.
            // If this hash is not the same in here in this section and after page-generation, then the page will not be properly cached!
            // This array is an identification of the template. If $this->all is empty it's because the template-data is not cached, which it must be.
            $pageSectionCacheContent = $this->tmpl->matching($pageSectionCacheContent);
            ksort($pageSectionCacheContent);
            $this->all = $pageSectionCacheContent;
        }
        unset($pageSectionCacheContent);

        // Look for page in cache only if a shift-reload is not sent to the server.
        $lockHash = $this->getLockHash();
        if (!$this->headerNoCache()) {
            if ($this->all) {
                // we got page section information
                $this->newHash = $this->getHash();
                $this->getTimeTracker()->push('Cache Row');
                $row = $this->getFromCache_queryRow();
                if (!is_array($row)) {
                    // nothing in the cache, we acquire an exclusive lock now

                    $this->acquireLock('pages', $lockHash);
                    //
                    // from this point on we're the only one working on that page ($lockHash)
                    //

                    // query the cache again to see if the data are there meanwhile
                    $row = $this->getFromCache_queryRow();
                    if (is_array($row)) {
                        // we have the content, nice that some other process did the work for us
                        $this->releaseLock('pages');
                    }
                    // We keep the lock set, because we are the ones generating the page now
                        // and filling the cache.
                        // This indicates that we have to release the lock in the Registry later in releaseLocks()
                }
                if (is_array($row)) {
                    // we have data from cache

                    // Call hook when a page is retrieved from cache:
                    $_params = ['pObj' => &$this, 'cache_pages_row' => &$row];
                    foreach ($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['tslib/class.tslib_fe.php']['pageLoadedFromCache'] ?? [] as $_funcRef) {
                        GeneralUtility::callUserFunction($_funcRef, $_params, $this);
                    }
                    // Fetches the lowlevel config stored with the cached data
                    $this->config = $row['cache_data'];
                    // Getting the content
                    $this->content = $row['content'];
                    // Flag for temp content
                    $this->tempContent = $row['temp_content'];
                    // Setting flag, so we know, that some cached content has been loaded
                    $this->cacheContentFlag = true;
                    $this->cacheExpires = $row['expires'];

                    // Restore page title information, this is needed to generate the page title for
                    // partially cached pages.
                    $this->page['title'] = $row['pageTitleInfo']['title'];
                    $this->indexedDocTitle = $row['pageTitleInfo']['indexedDocTitle'];

                    if (isset($this->config['config']['debug'])) {
                        $debugCacheTime = (bool)$this->config['config']['debug'];
                    } else {
                        $debugCacheTime = !empty($GLOBALS['TYPO3_CONF_VARS']['FE']['debug']);
                    }
                    if ($debugCacheTime) {
                        $dateFormat = $GLOBALS['TYPO3_CONF_VARS']['SYS']['ddmmyy'];
                        $timeFormat = $GLOBALS['TYPO3_CONF_VARS']['SYS']['hhmm'];
                        $this->content .= LF . '<!-- Cached page generated ' . date($dateFormat . ' ' . $timeFormat, $row['tstamp']) . '. Expires ' . date($dateFormat . ' ' . $timeFormat, $row['expires']) . ' -->';
                    }
                }
                $this->getTimeTracker()->pull();

                return;
            }
        }
        // the user forced rebuilding the page cache or there was no pagesection information
        // get a lock for the page content so other processes will not interrupt the regeneration
        $this->acquireLock('pages', $lockHash);
    }

    /**
     * Returning the cached version of page with hash = newHash
     *
     * @return array Cached row, if any. Otherwise void.
     */
    public function getFromCache_queryRow()
    {
        $this->getTimeTracker()->push('Cache Query');
        $row = $this->pageCache->get($this->newHash);
        $this->getTimeTracker()->pull();
        return $row;
    }

    /**
     * Detecting if shift-reload has been clicked
     * Will not be called if re-generation of page happens by other reasons (for instance that the page is not in cache yet!)
     * Also, a backend user MUST be logged in for the shift-reload to be detected due to DoS-attack-security reasons.
     *
     * @return bool If shift-reload in client browser has been clicked, disable getting cached page (and regenerate it).
     */
    public function headerNoCache()
    {
        $disableAcquireCacheData = false;
        if ($this->isBackendUserLoggedIn()) {
            if (strtolower($_SERVER['HTTP_CACHE_CONTROL']) === 'no-cache' || strtolower($_SERVER['HTTP_PRAGMA']) === 'no-cache') {
                $disableAcquireCacheData = true;
            }
        }
        // Call hook for possible by-pass of requiring of page cache (for recaching purpose)
        $_params = ['pObj' => &$this, 'disableAcquireCacheData' => &$disableAcquireCacheData];
        foreach ($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['tslib/class.tslib_fe.php']['headerNoCache'] ?? [] as $_funcRef) {
            GeneralUtility::callUserFunction($_funcRef, $_params, $this);
        }
        return $disableAcquireCacheData;
    }

    /**
     * Calculates the cache-hash
     * This hash is unique to the template, the variables ->id, ->type, list of fe user groups, ->MP (Mount Points) and cHash array
     * Used to get and later store the cached data.
     *
     * @return string MD5 hash of serialized hash base from createHashBase()
     * @see getFromCache(), getLockHash()
     */
    protected function getHash()
    {
        return md5($this->createHashBase(false));
    }

    /**
     * Calculates the lock-hash
     * This hash is unique to the above hash, except that it doesn't contain the template information in $this->all.
     *
     * @return string MD5 hash
     * @see getFromCache(), getHash()
     */
    protected function getLockHash()
    {
        $lockHash = $this->createHashBase(true);
        return md5($lockHash);
    }

    /**
     * Calculates the cache-hash (or the lock-hash)
     * This hash is unique to the template,
     * the variables ->id, ->type, list of frontend user groups,
     * ->MP (Mount Points) and cHash array
     * Used to get and later store the cached data.
     *
     * @param bool $createLockHashBase Whether to create the lock hash, which doesn't contain the "this->all" (the template information)
     * @return string the serialized hash base
     */
    protected function createHashBase($createLockHashBase = false)
    {
        // Ensure the language base is used for the hash base calculation as well, otherwise TypoScript and page-related rendering
        // is not cached properly as we don't have any language-specific conditions anymore
        $siteBase = $this->getCurrentSiteLanguage() ? (string)$this->getCurrentSiteLanguage()->getBase() : '';

        // Fetch the list of user groups
        /** @var UserAspect $userAspect */
        $userAspect = $this->context->getAspect('frontend.user');
        $hashParameters = [
            'id' => (int)$this->id,
            'type' => (int)$this->type,
            'gr_list' => (string)implode(',', $userAspect->getGroupIds()),
            'MP' => (string)$this->MP,
            'siteBase' => $siteBase,
            // cHash_array includes dynamic route arguments (if route was resolved)
            'cHash' => $this->cHash_array,
            // additional variation trigger for static routes
            'staticRouteArguments' => $this->pageArguments !== null ? $this->pageArguments->getStaticArguments() : null,
            'domainStartPage' => $this->domainStartPage
        ];
        // Include the template information if we shouldn't create a lock hash
        if (!$createLockHashBase) {
            $hashParameters['all'] = $this->all;
        }
        // Call hook to influence the hash calculation
        $_params = [
            'hashParameters' => &$hashParameters,
            'createLockHashBase' => $createLockHashBase
        ];
        foreach ($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['tslib/class.tslib_fe.php']['createHashBase'] ?? [] as $_funcRef) {
            GeneralUtility::callUserFunction($_funcRef, $_params, $this);
        }
        return serialize($hashParameters);
    }

    /**
     * Checks if config-array exists already but if not, gets it
     *
     * @throws ServiceUnavailableException
     */
    public function getConfigArray()
    {
        if (!($this->tmpl instanceof TemplateService)) {
            $this->tmpl = GeneralUtility::makeInstance(TemplateService::class, $this->context);
        }

        // If config is not set by the cache (which would be a major mistake somewhere) OR if INTincScripts-include-scripts have been registered, then we must parse the template in order to get it
        if (empty($this->config) || is_array($this->config['INTincScript']) || $this->forceTemplateParsing) {
            $timeTracker = $this->getTimeTracker();
            $timeTracker->push('Parse template');
            // Force parsing, if set?:
            $this->tmpl->forceTemplateParsing = $this->forceTemplateParsing;
            // Start parsing the TS template. Might return cached version.
            $this->tmpl->start($this->rootLine);
            $timeTracker->pull();
            if ($this->tmpl->loaded) {
                $timeTracker->push('Setting the config-array');
                // toplevel - objArrayName
                $this->sPre = $this->tmpl->setup['types.'][$this->type];
                $this->pSetup = $this->tmpl->setup[$this->sPre . '.'];
                if (!is_array($this->pSetup)) {
                    $message = 'The page is not configured! [type=' . $this->type . '][' . $this->sPre . '].';
                    $this->logger->alert($message);
                    try {
                        $response = GeneralUtility::makeInstance(ErrorController::class)->unavailableAction(
                            $GLOBALS['TYPO3_REQUEST'],
                            $message,
                            ['code' => PageAccessFailureReasons::RENDERING_INSTRUCTIONS_NOT_CONFIGURED]
                        );
                        throw new ImmediateResponseException($response, 1533931374);
                    } catch (ServiceUnavailableException $e) {
                        $explanation = 'This means that there is no TypoScript object of type PAGE with typeNum=' . $this->type . ' configured.';
                        throw new ServiceUnavailableException($message . ' ' . $explanation, 1294587217);
                    }
                } else {
                    if (!isset($this->config['config'])) {
                        $this->config['config'] = [];
                    }
                    // Filling the config-array, first with the main "config." part
                    if (is_array($this->tmpl->setup['config.'])) {
                        ArrayUtility::mergeRecursiveWithOverrule($this->tmpl->setup['config.'], $this->config['config']);
                        $this->config['config'] = $this->tmpl->setup['config.'];
                    }
                    // override it with the page/type-specific "config."
                    if (is_array($this->pSetup['config.'])) {
                        ArrayUtility::mergeRecursiveWithOverrule($this->config['config'], $this->pSetup['config.']);
                    }
                    // Set default values for removeDefaultJS and inlineStyle2TempFile so CSS and JS are externalized if compatversion is higher than 4.0
                    if (!isset($this->config['config']['removeDefaultJS'])) {
                        $this->config['config']['removeDefaultJS'] = 'external';
                    }
                    if (!isset($this->config['config']['inlineStyle2TempFile'])) {
                        $this->config['config']['inlineStyle2TempFile'] = 1;
                    }

                    if (!isset($this->config['config']['compressJs'])) {
                        $this->config['config']['compressJs'] = 0;
                    }
                    // Processing for the config_array:
                    $this->config['rootLine'] = $this->tmpl->rootLine;
                    // Class for render Header and Footer parts
                    if ($this->pSetup['pageHeaderFooterTemplateFile']) {
                        try {
                            $file = GeneralUtility::makeInstance(FilePathSanitizer::class)
                                ->sanitize((string)$this->pSetup['pageHeaderFooterTemplateFile']);
                            $this->pageRenderer->setTemplateFile($file);
                        } catch (\TYPO3\CMS\Core\Resource\Exception $e) {
                            // do nothing
                        }
                    }
                }
                $timeTracker->pull();
            } else {
                $message = 'No TypoScript template found!';
                $this->logger->alert($message);
                try {
                    $response = GeneralUtility::makeInstance(ErrorController::class)->unavailableAction(
                        $GLOBALS['TYPO3_REQUEST'],
                        $message,
                        ['code' => PageAccessFailureReasons::RENDERING_INSTRUCTIONS_NOT_FOUND]
                    );
                    throw new ImmediateResponseException($response, 1533931380);
                } catch (ServiceUnavailableException $e) {
                    throw new ServiceUnavailableException($message, 1294587218);
                }
            }
        }

        // No cache
        // Set $this->no_cache TRUE if the config.no_cache value is set!
        if ($this->config['config']['no_cache']) {
            $this->set_no_cache('config.no_cache is set');
        }
        // Merge GET with defaultGetVars
        // Please note that this code will get removed in TYPO3 v10.0 as it is done in the PSR-15 middleware.
        if (!empty($this->config['config']['defaultGetVars.'])) {
            $modifiedGetVars = GeneralUtility::removeDotsFromTS($this->config['config']['defaultGetVars.']);
            ArrayUtility::mergeRecursiveWithOverrule($modifiedGetVars, GeneralUtility::_GET());
            $_GET = $modifiedGetVars;
            $GLOBALS['HTTP_GET_VARS'] = $modifiedGetVars;
        }

        // Auto-configure settings when a site is configured
        if ($this->getCurrentSiteLanguage()) {
            $this->config['config']['absRefPrefix'] = $this->config['config']['absRefPrefix'] ?? 'auto';
        }

        $this->setUrlIdToken();

        // Hook for postProcessing the configuration array
        $params = ['config' => &$this->config['config']];
        foreach ($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['tslib/class.tslib_fe.php']['configArrayPostProc'] ?? [] as $funcRef) {
            GeneralUtility::callUserFunction($funcRef, $params, $this);
        }
    }

    /********************************************
     *
     * Further initialization and data processing
     *
     *******************************************/

    /**
     * Setting the language key that will be used by the current page.
     * In this function it should be checked, 1) that this language exists, 2) that a page_overlay_record exists, .. and if not the default language, 0 (zero), should be set.
     *
     * @internal
     */
    public function settingLanguage()
    {
        $_params = [];
        foreach ($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['tslib/class.tslib_fe.php']['settingLanguage_preProcess'] ?? [] as $_funcRef) {
            GeneralUtility::callUserFunction($_funcRef, $_params, $this);
        }

        Locales::initialize();

        $siteLanguage = $this->getCurrentSiteLanguage();

        // Initialize charset settings etc.
        if ($siteLanguage) {
            $languageKey = $siteLanguage->getTypo3Language();
        } else {
            $languageKey = $this->config['config']['language'] ?? 'default';
        }
        $this->setOutputLanguage($languageKey);

        // Rendering charset of HTML page.
        if (isset($this->config['config']['metaCharset']) && $this->config['config']['metaCharset'] !== 'utf-8') {
            $this->metaCharset = $this->config['config']['metaCharset'];
        }

        // Get values from site language
        if ($siteLanguage) {
            $languageAspect = LanguageAspectFactory::createFromSiteLanguage($siteLanguage);
        } else {
            $languageAspect = LanguageAspectFactory::createFromTypoScript($this->config['config'] ?? []);
        }

        $languageId = $languageAspect->getId();
        $languageContentId = $languageAspect->getContentId();

        // If sys_language_uid is set to another language than default:
        if ($languageAspect->getId() > 0) {
            // check whether a shortcut is overwritten by a translated page
            // we can only do this now, as this is the place where we get
            // to know about translations
            $this->checkTranslatedShortcut($languageAspect->getId());
            // Request the overlay record for the sys_language_uid:
            $olRec = $this->sys_page->getPageOverlay($this->id, $languageAspect->getId());
            if (empty($olRec)) {
                // If requested translation is not available:
                if (GeneralUtility::hideIfNotTranslated($this->page['l18n_cfg'])) {
                    $response = GeneralUtility::makeInstance(ErrorController::class)->pageNotFoundAction(
                        $GLOBALS['TYPO3_REQUEST'],
                        'Page is not available in the requested language.',
                        ['code' => PageAccessFailureReasons::LANGUAGE_NOT_AVAILABLE]
                    );
                    throw new ImmediateResponseException($response, 1533931388);
                }
                switch ((string)$languageAspect->getLegacyLanguageMode()) {
                    case 'strict':
                        $response = GeneralUtility::makeInstance(ErrorController::class)->pageNotFoundAction(
                            $GLOBALS['TYPO3_REQUEST'],
                            'Page is not available in the requested language (strict).',
                            ['code' => PageAccessFailureReasons::LANGUAGE_NOT_AVAILABLE_STRICT_MODE]
                        );
                        throw new ImmediateResponseException($response, 1533931395);
                        break;
                    case 'fallback':
                    case 'content_fallback':
                        // Setting content uid (but leaving the sys_language_uid) when a content_fallback
                        // value was found.
                        foreach ($languageAspect->getFallbackChain() ?? [] as $orderValue) {
                            if ($orderValue === '0' || $orderValue === 0 || $orderValue === '') {
                                $languageContentId = 0;
                                break;
                            }
                            if (MathUtility::canBeInterpretedAsInteger($orderValue) && !empty($this->sys_page->getPageOverlay($this->id, (int)$orderValue))) {
                                $languageContentId = (int)$orderValue;
                                break;
                            }
                            if ($orderValue === 'pageNotFound') {
                                // The existing fallbacks have not been found, but instead of continuing
                                // page rendering with default language, a "page not found" message should be shown
                                // instead.
                                $response = GeneralUtility::makeInstance(ErrorController::class)->pageNotFoundAction(
                                    $GLOBALS['TYPO3_REQUEST'],
                                    'Page is not available in the requested language (fallbacks did not apply).',
                                    ['code' => PageAccessFailureReasons::LANGUAGE_AND_FALLBACKS_NOT_AVAILABLE]
                                );
                                throw new ImmediateResponseException($response, 1533931402);
                            }
                        }
                        break;
                    case 'ignore':
                        $languageContentId = $languageAspect->getId();
                        break;
                    default:
                        // Default is that everything defaults to the default language...
                        $languageId = ($languageContentId = 0);
                }
            }

            // Define the language aspect again now
            $languageAspect = GeneralUtility::makeInstance(
                LanguageAspect::class,
                $languageId,
                $languageContentId,
                $languageAspect->getOverlayType(),
                $languageAspect->getFallbackChain()
            );

            // Setting sys_language if an overlay record was found (which it is only if a language is used)
            // We'll do this every time since the language aspect might have changed now
            // Doing this ensures that page properties like the page title are returned in the correct language
            $this->page = $this->sys_page->getPageOverlay($this->page, $languageAspect->getContentId());
        }

        // Set the language aspect
        $this->context->setAspect('language', $languageAspect);

        // Setting sys_language_uid inside sys-page by creating a new page repository
        $this->sys_page = GeneralUtility::makeInstance(PageRepository::class, $this->context);
        // If default translation is not available:
        if ((!$languageAspect->getContentId() || !$languageAspect->getId())
            && GeneralUtility::hideIfDefaultLanguage($this->page['l18n_cfg'] ?? 0)
        ) {
            $message = 'Page is not available in default language.';
            $this->logger->error($message);
            $response = GeneralUtility::makeInstance(ErrorController::class)->pageNotFoundAction(
                $GLOBALS['TYPO3_REQUEST'],
                $message,
                ['code' => PageAccessFailureReasons::LANGUAGE_DEFAULT_NOT_AVAILABLE]
            );
            throw new ImmediateResponseException($response, 1533931423);
        }

        if ($languageAspect->getId() > 0) {
            $this->updateRootLinesWithTranslations();
        }

        // Finding the ISO code for the currently selected language
        // fetched by the sys_language record when not fetching content from the default language
        if ($siteLanguage = $this->getCurrentSiteLanguage()) {
            $this->sys_language_isocode = $siteLanguage->getTwoLetterIsoCode();
        } elseif ($languageAspect->getContentId() > 0) {
            // using sys_language_content because the ISO code only (currently) affect content selection from FlexForms - which should follow "sys_language_content"
            // Set the fourth parameter to TRUE in the next two getRawRecord() calls to
            // avoid versioning overlay to be applied as it generates an SQL error
            $sys_language_row = $this->sys_page->getRawRecord('sys_language', $languageAspect->getContentId(), 'language_isocode,static_lang_isocode');
            if (is_array($sys_language_row) && !empty($sys_language_row['language_isocode'])) {
                $this->sys_language_isocode = $sys_language_row['language_isocode'];
            }
            // the DB value is overridden by TypoScript
            if (!empty($this->config['config']['sys_language_isocode'])) {
                $this->sys_language_isocode = $this->config['config']['sys_language_isocode'];
            }
        } else {
            // fallback to the TypoScript option when rendering with sys_language_uid=0
            // also: use "en" by default
            if (!empty($this->config['config']['sys_language_isocode_default'])) {
                $this->sys_language_isocode = $this->config['config']['sys_language_isocode_default'];
            } else {
                $this->sys_language_isocode = $languageKey !== 'default' ? $languageKey : 'en';
            }
        }

        $_params = [];
        foreach ($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['tslib/class.tslib_fe.php']['settingLanguage_postProcess'] ?? [] as $_funcRef) {
            GeneralUtility::callUserFunction($_funcRef, $_params, $this);
        }
    }

    /**
     * Updating content of the two rootLines IF the language key is set!
     */
    protected function updateRootLinesWithTranslations()
    {
        try {
            $this->rootLine = GeneralUtility::makeInstance(RootlineUtility::class, $this->id, $this->MP, $this->context)->get();
        } catch (RootLineException $e) {
            $this->rootLine = [];
        }
        $this->tmpl->updateRootlineData($this->rootLine);
    }

    /**
     * Setting locale for frontend rendering
     */
    public function settingLocale()
    {
        // Setting locale
        $locale = $this->config['config']['locale_all'];
        $siteLanguage = $this->getCurrentSiteLanguage();
        if ($siteLanguage) {
            $locale = $siteLanguage->getLocale();
        }
        if ($locale) {
            $availableLocales = GeneralUtility::trimExplode(',', $locale, true);
            // If LC_NUMERIC is set e.g. to 'de_DE' PHP parses float values locale-aware resulting in strings with comma
            // as decimal point which causes problems with value conversions - so we set all locale types except LC_NUMERIC
            // @see https://bugs.php.net/bug.php?id=53711
            $locale = setlocale(LC_COLLATE, ...$availableLocales);
            if ($locale) {
                // As str_* methods are locale aware and turkish has no upper case I
                // Class autoloading and other checks depending on case changing break with turkish locale LC_CTYPE
                // @see http://bugs.php.net/bug.php?id=35050
                if (strpos($locale, 'tr') !== 0) {
                    setlocale(LC_CTYPE, ...$availableLocales);
                }
                setlocale(LC_MONETARY, ...$availableLocales);
                setlocale(LC_TIME, ...$availableLocales);
            } else {
                $this->getTimeTracker()->setTSlogMessage('Locale "' . htmlspecialchars($locale) . '" not found.', 3);
            }
        }
    }

    /**
     * Checks whether a translated shortcut page has a different shortcut
     * target than the original language page.
     * If that is the case, things get corrected to follow that alternative
     * shortcut
     * @param int $languageId
     */
    protected function checkTranslatedShortcut(int $languageId)
    {
        if (!is_null($this->originalShortcutPage)) {
            $originalShortcutPageOverlay = $this->sys_page->getPageOverlay($this->originalShortcutPage['uid'], $languageId);
            if (!empty($originalShortcutPageOverlay['shortcut']) && $originalShortcutPageOverlay['shortcut'] != $this->id) {
                // the translation of the original shortcut page has a different shortcut target!
                // set the correct page and id
                $shortcut = $this->sys_page->getPageShortcut($originalShortcutPageOverlay['shortcut'], $originalShortcutPageOverlay['shortcut_mode'], $originalShortcutPageOverlay['uid']);
                $this->id = ($this->contentPid = $shortcut['uid']);
                $this->page = $this->sys_page->getPage($this->id);
                // Fix various effects on things like menus f.e.
                $this->fetch_the_id();
                $this->tmpl->rootLine = array_reverse($this->rootLine);
            }
        }
    }

    /**
     * Sets the URL_ID_TOKEN in the internal var, $this->getMethodUrlIdToken
     * This feature allows sessions to use a GET-parameter instead of a cookie.
     */
    protected function setUrlIdToken()
    {
        if ($this->config['config']['ftu']) {
            $this->getMethodUrlIdToken = $GLOBALS['TYPO3_CONF_VARS']['FE']['get_url_id_token'];
        } else {
            $this->getMethodUrlIdToken = '';
        }
    }

    /**
     * Calculates and sets the internal linkVars based upon the current request parameters
     * and the setting "config.linkVars".
     *
     * @param array $queryParams $_GET (usually called with a PSR-7 $request->getQueryParams())
     */
    public function calculateLinkVars(array $queryParams)
    {
        $this->linkVars = '';
        if (empty($this->config['config']['linkVars'])) {
            return;
        }

        $linkVars = $this->splitLinkVarsString((string)$this->config['config']['linkVars']);

        if (empty($linkVars)) {
            return;
        }
        foreach ($linkVars as $linkVar) {
            $test = $value = '';
            if (preg_match('/^(.*)\\((.+)\\)$/', $linkVar, $match)) {
                $linkVar = trim($match[1]);
                $test = trim($match[2]);
            }

            $keys = explode('|', $linkVar);
            $numberOfLevels = count($keys);
            $rootKey = trim($keys[0]);
            if (!isset($queryParams[$rootKey])) {
                continue;
            }
            $value = $queryParams[$rootKey];
            for ($i = 1; $i < $numberOfLevels; $i++) {
                $currentKey = trim($keys[$i]);
                if (isset($value[$currentKey])) {
                    $value = $value[$currentKey];
                } else {
                    $value = false;
                    break;
                }
            }
            if ($value !== false) {
                $parameterName = $keys[0];
                for ($i = 1; $i < $numberOfLevels; $i++) {
                    $parameterName .= '[' . $keys[$i] . ']';
                }
                if (!is_array($value)) {
                    $temp = rawurlencode($value);
                    if ($test !== '' && !$this->isAllowedLinkVarValue($temp, $test)) {
                        // Error: This value was not allowed for this key
                        continue;
                    }
                    $value = '&' . $parameterName . '=' . $temp;
                } else {
                    if ($test !== '' && $test !== 'array') {
                        // Error: This key must not be an array!
                        continue;
                    }
                    $value = HttpUtility::buildQueryString([$parameterName => $value], '&');
                }
                $this->linkVars .= $value;
            }
        }
    }

    /**
     * Split the link vars string by "," but not if the "," is inside of braces
     *
     * @param $string
     *
     * @return array
     */
    protected function splitLinkVarsString(string $string): array
    {
        $tempCommaReplacementString = '###KASPER###';

        // replace every "," wrapped in "()" by a "unique" string
        $string = preg_replace_callback('/\((?>[^()]|(?R))*\)/', function ($result) use ($tempCommaReplacementString) {
            return str_replace(',', $tempCommaReplacementString, $result[0]);
        }, $string);

        $string = GeneralUtility::trimExplode(',', $string);

        // replace all "unique" strings back to ","
        return str_replace($tempCommaReplacementString, ',', $string);
    }

    /**
     * Checks if the value defined in "config.linkVars" contains an allowed value.
     * Otherwise, return FALSE which means the value will not be added to any links.
     *
     * @param string $haystack The string in which to find $needle
     * @param string $needle The string to find in $haystack
     * @return bool Returns TRUE if $needle matches or is found in $haystack
     */
    protected function isAllowedLinkVarValue(string $haystack, string $needle): bool
    {
        $isAllowed = false;
        // Integer
        if ($needle === 'int' || $needle === 'integer') {
            if (MathUtility::canBeInterpretedAsInteger($haystack)) {
                $isAllowed = true;
            }
        } elseif (preg_match('/^\\/.+\\/[imsxeADSUXu]*$/', $needle)) {
            // Regular expression, only "//" is allowed as delimiter
            if (@preg_match($needle, $haystack)) {
                $isAllowed = true;
            }
        } elseif (strstr($needle, '-')) {
            // Range
            if (MathUtility::canBeInterpretedAsInteger($haystack)) {
                $range = explode('-', $needle);
                if ($range[0] <= $haystack && $range[1] >= $haystack) {
                    $isAllowed = true;
                }
            }
        } elseif (strstr($needle, '|')) {
            // List
            // Trim the input
            $haystack = str_replace(' ', '', $haystack);
            if (strstr('|' . $needle . '|', '|' . $haystack . '|')) {
                $isAllowed = true;
            }
        } elseif ((string)$needle === (string)$haystack) {
            // String comparison
            $isAllowed = true;
        }
        return $isAllowed;
    }

    /**
     * Returns URI of target page, if the current page is an overlaid mountpoint.
     *
     * If the current page is of type mountpoint and should be overlaid with the contents of the mountpoint page
     * and is accessed directly, the user will be redirected to the mountpoint context.
     * @internal
     * @param ServerRequestInterface $request
     * @return string|null
     */
    public function getRedirectUriForMountPoint(ServerRequestInterface $request): ?string
    {
        if (!empty($this->originalMountPointPage) && (int)$this->originalMountPointPage['doktype'] === PageRepository::DOKTYPE_MOUNTPOINT) {
            return $this->getUriToCurrentPageForRedirect($request);
        }

        return null;
    }

    /**
     * Returns URI of target page, if the current page is a Shortcut.
     *
     * If the current page is of type shortcut and accessed directly via its URL,
     * the user will be redirected to shortcut target.
     *
     * @internal
     * @param ServerRequestInterface $request
     * @return string|null
     */
    public function getRedirectUriForShortcut(ServerRequestInterface $request): ?string
    {
        if (!empty($this->originalShortcutPage) && $this->originalShortcutPage['doktype'] == PageRepository::DOKTYPE_SHORTCUT) {
            return $this->getUriToCurrentPageForRedirect($request);
        }

        return null;
    }

    /**
     * Instantiate \TYPO3\CMS\Frontend\ContentObject to generate the correct target URL
     *
     * @param ServerRequestInterface $request
     * @return string
     */
    protected function getUriToCurrentPageForRedirect(ServerRequestInterface $request): string
    {
        $this->calculateLinkVars($request->getQueryParams());
        $parameter = $this->page['uid'];
        if ($this->type && MathUtility::canBeInterpretedAsInteger($this->type)) {
            $parameter .= ',' . $this->type;
        }
        return GeneralUtility::makeInstance(ContentObjectRenderer::class, $this)->typoLink_URL([
            'parameter' => $parameter,
            'addQueryString' => true,
            'addQueryString.' => ['exclude' => 'id'],
            // ensure absolute URL is generated when having a valid Site
            'forceAbsoluteUrl' => $GLOBALS['TYPO3_REQUEST'] instanceof ServerRequestInterface
                && $GLOBALS['TYPO3_REQUEST']->getAttribute('site') instanceof Site
        ]);
    }

    /********************************************
     *
     * Page generation; cache handling
     *
     *******************************************/
    /**
     * Returns TRUE if the page should be generated.
     * That is if no URL handler is active and the cacheContentFlag is not set.
     *
     * @return bool
     */
    public function isGeneratePage()
    {
        return !$this->cacheContentFlag;
    }

    /**
     * Temp cache content
     * The temporary cache will expire after a few seconds (typ. 30) or will be cleared by the rendered page,
     * which will also clear and rewrite the cache.
     */
    protected function tempPageCacheContent()
    {
        $this->tempContent = false;
        if (!$this->no_cache) {
            $seconds = 30;
            $title = htmlspecialchars($this->printTitle($this->page['title']));
            $request_uri = htmlspecialchars(GeneralUtility::getIndpEnv('REQUEST_URI'));
            $stdMsg = '
		<strong>Page is being generated.</strong><br />
		If this message does not disappear within ' . $seconds . ' seconds, please reload.';
            $message = $this->config['config']['message_page_is_being_generated'];
            if ((string)$message !== '') {
                $message = str_replace(['###TITLE###', '###REQUEST_URI###'], [$title, $request_uri], $message);
            } else {
                $message = $stdMsg;
            }
            $temp_content = '<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN"
	"http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
	<head>
		<title>' . $title . '</title>
		<meta http-equiv="refresh" content="10" />
	</head>
	<body style="background-color:white; font-family:Verdana,Arial,Helvetica,sans-serif; color:#cccccc; text-align:center;">' . $message . '
	</body>
</html>';
            // Fix 'nice errors' feature in modern browsers
            $padSuffix = '<!--pad-->';
            // prevent any trims
            $padSize = 768 - strlen($padSuffix) - strlen($temp_content);
            if ($padSize > 0) {
                $temp_content = str_pad($temp_content, $padSize, LF) . $padSuffix;
            }
            if (!$this->headerNoCache() && ($cachedRow = $this->getFromCache_queryRow())) {
                // We are here because between checking for cached content earlier and now some other HTTP-process managed to store something in cache AND it was not due to a shift-reload by-pass.
                // This is either the "Page is being generated" screen or it can be the final result.
                // In any case we should not begin another rendering process also, so we silently disable caching and render the page ourselves and that's it.
                // Actually $cachedRow contains content that we could show instead of rendering. Maybe we should do that to gain more performance but then we should set all the stuff done in $this->getFromCache()... For now we stick to this...
                $this->set_no_cache('Another process wrote into the cache since the beginning of the render process', true);

            // Since the new Locking API this should never be the case
            } else {
                $this->tempContent = true;
                // This flag shows that temporary content is put in the cache
                $this->setPageCacheContent($temp_content, $this->config, $GLOBALS['EXEC_TIME'] + $seconds);
            }
        }
    }

    /**
     * Set cache content to $this->content
     */
    protected function realPageCacheContent()
    {
        // seconds until a cached page is too old
        $cacheTimeout = $this->get_cache_timeout();
        $timeOutTime = $GLOBALS['EXEC_TIME'] + $cacheTimeout;
        $this->tempContent = false;
        $usePageCache = true;
        // Hook for deciding whether page cache should be written to the cache backend or not
        // NOTE: as hooks are called in a loop, the last hook will have the final word (however each
        // hook receives the current status of the $usePageCache flag)
        foreach ($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['tslib/class.tslib_fe.php']['usePageCache'] ?? [] as $className) {
            $usePageCache = GeneralUtility::makeInstance($className)->usePageCache($this, $usePageCache);
        }
        // Write the page to cache, if necessary
        if ($usePageCache) {
            $this->setPageCacheContent($this->content, $this->config, $timeOutTime);
        }
        // Hook for cache post processing (eg. writing static files!)
        foreach ($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['tslib/class.tslib_fe.php']['insertPageIncache'] ?? [] as $className) {
            GeneralUtility::makeInstance($className)->insertPageIncache($this, $timeOutTime);
        }
    }

    /**
     * Sets cache content; Inserts the content string into the cache_pages cache.
     *
     * @param string $content The content to store in the HTML field of the cache table
     * @param mixed $data The additional cache_data array, fx. $this->config
     * @param int $expirationTstamp Expiration timestamp
     * @see realPageCacheContent(), tempPageCacheContent()
     */
    protected function setPageCacheContent($content, $data, $expirationTstamp)
    {
        $cacheData = [
            'identifier' => $this->newHash,
            'page_id' => $this->id,
            'content' => $content,
            'temp_content' => $this->tempContent,
            'cache_data' => $data,
            'expires' => $expirationTstamp,
            'tstamp' => $GLOBALS['EXEC_TIME'],
            'pageTitleInfo' => [
                'title' => $this->page['title'],
                'indexedDocTitle' => $this->indexedDocTitle
            ]
        ];
        $this->cacheExpires = $expirationTstamp;
        $this->pageCacheTags[] = 'pageId_' . $cacheData['page_id'];
        if (!empty($this->page['cache_tags'])) {
            $tags = GeneralUtility::trimExplode(',', $this->page['cache_tags'], true);
            $this->pageCacheTags = array_merge($this->pageCacheTags, $tags);
        }
        $this->pageCache->set($this->newHash, $cacheData, $this->pageCacheTags, $expirationTstamp - $GLOBALS['EXEC_TIME']);
    }

    /**
     * Clears cache content (for $this->newHash)
     */
    public function clearPageCacheContent()
    {
        $this->pageCache->remove($this->newHash);
    }

    /**
     * Clears cache content for a list of page ids
     *
     * @param string $pidList A list of INTEGER numbers which points to page uids for which to clear entries in the cache_pages cache (page content cache)
     */
    protected function clearPageCacheContent_pidList($pidList)
    {
        $pageIds = GeneralUtility::trimExplode(',', $pidList);
        foreach ($pageIds as $pageId) {
            $this->pageCache->flushByTag('pageId_' . (int)$pageId);
        }
    }

    /**
     * Sets sys last changed
     * Setting the SYS_LASTCHANGED value in the pagerecord: This value will thus be set to the highest tstamp of records rendered on the page. This includes all records with no regard to hidden records, userprotection and so on.
     *
     * @see ContentObjectRenderer::lastChanged()
     */
    protected function setSysLastChanged()
    {
        // We only update the info if browsing the live workspace
        if (!$this->doWorkspacePreview() && $this->page['SYS_LASTCHANGED'] < (int)$this->register['SYS_LASTCHANGED']) {
            $connection = GeneralUtility::makeInstance(ConnectionPool::class)
                ->getConnectionForTable('pages');
            $connection->update(
                'pages',
                [
                    'SYS_LASTCHANGED' => (int)$this->register['SYS_LASTCHANGED']
                ],
                [
                    'uid' => (int)$this->id
                ]
            );
        }
    }

    /**
     * Release pending locks
     *
     * @internal
     */
    public function releaseLocks()
    {
        $this->releaseLock('pagesection');
        $this->releaseLock('pages');
    }

    /**
     * Adds tags to this page's cache entry, you can then f.e. remove cache
     * entries by tag
     *
     * @param array $tags An array of tag
     */
    public function addCacheTags(array $tags)
    {
        $this->pageCacheTags = array_merge($this->pageCacheTags, $tags);
    }

    /**
     * @return array
     */
    public function getPageCacheTags(): array
    {
        return $this->pageCacheTags;
    }

    /********************************************
     *
     * Page generation; rendering and inclusion
     *
     *******************************************/
    /**
     * Does some processing BEFORE the pagegen script is included.
     */
    public function generatePage_preProcessing()
    {
        // Same codeline as in getFromCache(). But $this->all has been changed by
        // \TYPO3\CMS\Core\TypoScript\TemplateService::start() in the meantime, so this must be called again!
        $this->newHash = $this->getHash();

        // If the pages_lock is set, we are in charge of generating the page.
        if (is_object($this->locks['pages']['accessLock'])) {
            // Here we put some temporary stuff in the cache in order to let the first hit generate the page.
            // The temporary cache will expire after a few seconds (typ. 30) or will be cleared by the rendered page,
            // which will also clear and rewrite the cache.
            $this->tempPageCacheContent();
        }
        // At this point we have a valid pagesection_cache and also some temporary page_cache content,
        // so let all other processes proceed now. (They are blocked at the pagessection_lock in getFromCache())
        $this->releaseLock('pagesection');

        // Setting cache_timeout_default. May be overridden by PHP include scripts.
        $this->cacheTimeOutDefault = (int)($this->config['config']['cache_period'] ?? 0);
        // Page is generated
        $this->no_cacheBeforePageGen = $this->no_cache;
    }

    /**
     * Previously located in static method in PageGenerator::init. Is solely used to set up TypoScript
     * config. options and set properties in $TSFE for that.
     *
     * @param ServerRequestInterface $request
     */
    public function preparePageContentGeneration(ServerRequestInterface $request)
    {
        $this->getTimeTracker()->push('Prepare page content generation');
        if (isset($this->page['content_from_pid']) && $this->page['content_from_pid'] > 0) {
            // make REAL copy of TSFE object - not reference!
            $temp_copy_TSFE = clone $this;
            // Set ->id to the content_from_pid value - we are going to evaluate this pid as was it a given id for a page-display!
            $temp_copy_TSFE->id = $this->page['content_from_pid'];
            $temp_copy_TSFE->MP = '';
            $temp_copy_TSFE->getPageAndRootlineWithDomain($this->config['config']['content_from_pid_allowOutsideDomain'] ? 0 : $this->domainStartPage);
            $this->contentPid = (int)$temp_copy_TSFE->id;
            unset($temp_copy_TSFE);
        }
        // Global vars...
        $this->indexedDocTitle = $this->page['title'] ?? null;
        // Base url:
        if (isset($this->config['config']['baseURL'])) {
            $this->baseUrl = $this->config['config']['baseURL'];
        }
        // Internal and External target defaults
        $this->intTarget = (string)($this->config['config']['intTarget'] ?? '');
        $this->extTarget = (string)($this->config['config']['extTarget'] ?? '');
        $this->fileTarget = (string)($this->config['config']['fileTarget'] ?? '');
        $this->spamProtectEmailAddresses = $this->config['config']['spamProtectEmailAddresses'] ?? 0;
        if ($this->spamProtectEmailAddresses !== 'ascii') {
            $this->spamProtectEmailAddresses = MathUtility::forceIntegerInRange($this->spamProtectEmailAddresses, -10, 10, 0);
        }
        // calculate the absolute path prefix
        if (!empty($this->config['config']['absRefPrefix'])) {
            $absRefPrefix = trim($this->config['config']['absRefPrefix']);
            if ($absRefPrefix === 'auto') {
                $this->absRefPrefix = GeneralUtility::getIndpEnv('TYPO3_SITE_PATH');
            } else {
                $this->absRefPrefix = $absRefPrefix;
            }
        } else {
            $this->absRefPrefix = '';
        }
        $this->ATagParams = trim($this->config['config']['ATagParams'] ?? '') ? ' ' . trim($this->config['config']['ATagParams']) : '';
        $this->initializeSearchWordData($request->getParsedBody()['sword_list'] ?? $request->getQueryParams()['sword_list'] ?? null);
        // linkVars
        $this->calculateLinkVars($request->getQueryParams());
        // Setting XHTML-doctype from doctype
        if (!isset($this->config['config']['xhtmlDoctype']) || !$this->config['config']['xhtmlDoctype']) {
            $this->config['config']['xhtmlDoctype'] = $this->config['config']['doctype'] ?? '';
        }
        if ($this->config['config']['xhtmlDoctype']) {
            $this->xhtmlDoctype = $this->config['config']['xhtmlDoctype'];
            // Checking XHTML-docytpe
            switch ((string)$this->config['config']['xhtmlDoctype']) {
                case 'xhtml_trans':
                case 'xhtml_strict':
                    $this->xhtmlVersion = 100;
                    break;
                case 'xhtml_basic':
                    $this->xhtmlVersion = 105;
                    break;
                case 'xhtml_11':
                case 'xhtml+rdfa_10':
                    $this->xhtmlVersion = 110;
                    break;
                default:
                    $this->pageRenderer->setRenderXhtml(false);
                    $this->xhtmlDoctype = '';
                    $this->xhtmlVersion = 0;
            }
        } else {
            $this->pageRenderer->setRenderXhtml(false);
        }

        // Global content object
        $this->newCObj();
        $this->getTimeTracker()->pull();
    }

    /**
     * Fills the sWordList property and builds the regular expression in TSFE that can be used to split
     * strings by the submitted search words.
     *
     * @param mixed $searchWords - usually an array, but we can't be sure (yet)
     * @see sWordList
     * @see sWordRegEx
     */
    protected function initializeSearchWordData($searchWords)
    {
        $this->sWordRegEx = '';
        $this->sWordList = $searchWords === null ? '' : $searchWords;
        if (is_array($this->sWordList)) {
            $space = !empty($this->config['config']['sword_standAlone'] ?? null) ? '[[:space:]]' : '';
            $regexpParts = [];
            foreach ($this->sWordList as $val) {
                if (trim($val) !== '') {
                    $regexpParts[] = $space . preg_quote($val, '/') . $space;
                }
            }
            $this->sWordRegEx = implode('|', $regexpParts);
        }
    }

    /**
     * Does some processing AFTER the pagegen script is included.
     * This includes caching the page, indexing the page (if configured) and setting sysLastChanged
     */
    public function generatePage_postProcessing()
    {
        // This is to ensure, that the page is NOT cached if the no_cache parameter was set before the page was generated. This is a safety precaution, as it could have been unset by some script.
        if ($this->no_cacheBeforePageGen) {
            $this->set_no_cache('no_cache has been set before the page was generated - safety check', true);
        }
        // Hook for post-processing of page content cached/non-cached:
        $_params = ['pObj' => &$this];
        foreach ($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['tslib/class.tslib_fe.php']['contentPostProc-all'] ?? [] as $_funcRef) {
            GeneralUtility::callUserFunction($_funcRef, $_params, $this);
        }
        // Processing if caching is enabled:
        if (!$this->no_cache) {
            // Hook for post-processing of page content before being cached:
            foreach ($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['tslib/class.tslib_fe.php']['contentPostProc-cached'] ?? [] as $_funcRef) {
                GeneralUtility::callUserFunction($_funcRef, $_params, $this);
            }
        }
        // Convert char-set for output: (should be BEFORE indexing of the content (changed 22/4 2005)),
        // because otherwise indexed search might convert from the wrong charset!
        // One thing is that the charset mentioned in the HTML header would be wrong since the output charset (metaCharset)
        // has not been converted to from utf-8. And indexed search will internally convert from metaCharset
        // to utf-8 so the content MUST be in metaCharset already!
        $this->content = $this->convOutputCharset($this->content);
        // Hook for indexing pages
        foreach ($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['tslib/class.tslib_fe.php']['pageIndexing'] ?? [] as $className) {
            GeneralUtility::makeInstance($className)->hook_indexContent($this);
        }
        // Storing for cache:
        if (!$this->no_cache) {
            $this->realPageCacheContent();
        } elseif ($this->tempContent) {
            // If there happens to be temporary content in the cache and the cache was not cleared due to new content, put it in... ($this->no_cache=0)
            $this->clearPageCacheContent();
            $this->tempContent = false;
        }
        // Sets sys-last-change:
        $this->setSysLastChanged();
    }

    /**
     * Generate the page title, can be called multiple times,
     * as PageTitleProvider might have been modified by an uncached plugin etc.
     *
     * @return string the generated page title
     */
    public function generatePageTitle(): string
    {
        $pageTitleSeparator = '';

        // Check for a custom pageTitleSeparator, and perform stdWrap on it
        if (isset($this->config['config']['pageTitleSeparator']) && $this->config['config']['pageTitleSeparator'] !== '') {
            $pageTitleSeparator = $this->config['config']['pageTitleSeparator'];

            if (isset($this->config['config']['pageTitleSeparator.']) && is_array($this->config['config']['pageTitleSeparator.'])) {
                $pageTitleSeparator = $this->cObj->stdWrap($pageTitleSeparator, $this->config['config']['pageTitleSeparator.']);
            } else {
                $pageTitleSeparator .= ' ';
            }
        }

        $titleProvider = GeneralUtility::makeInstance(PageTitleProviderManager::class);
        $pageTitle = $titleProvider->getTitle();

        $titleTagContent = $this->printTitle(
            $pageTitle,
            (bool)($this->config['config']['noPageTitle'] ?? false),
            (bool)($this->config['config']['pageTitleFirst'] ?? false),
            $pageTitleSeparator
        );
        // stdWrap around the title tag
        if (isset($this->config['config']['pageTitle.']) && is_array($this->config['config']['pageTitle.'])) {
            $titleTagContent = $this->cObj->stdWrap($titleTagContent, $this->config['config']['pageTitle.']);
        }

        // config.noPageTitle = 2 - means do not render the page title
        if (isset($this->config['config']['noPageTitle']) && (int)$this->config['config']['noPageTitle'] === 2) {
            $titleTagContent = '';
        }
        if ($titleTagContent !== '') {
            $this->pageRenderer->setTitle($titleTagContent);
        }
        return (string)$titleTagContent;
    }

    /**
     * Compiles the content for the page <title> tag.
     *
     * @param string $pageTitle The input title string, typically the "title" field of a page's record.
     * @param bool $noTitle If set, then only the site title is outputted (from $this->setup['sitetitle'])
     * @param bool $showTitleFirst If set, then "sitetitle" and $title is swapped
     * @param string $pageTitleSeparator an alternative to the ": " as the separator between site title and page title
     * @return string The page title on the form "[sitetitle]: [input-title]". Not htmlspecialchar()'ed.
     * @see tempPageCacheContent(), generatePageTitle()
     */
    protected function printTitle(string $pageTitle, bool $noTitle = false, bool $showTitleFirst = false, string $pageTitleSeparator = ''): string
    {
        $siteTitle = trim($this->tmpl->setup['sitetitle'] ?? '');
        $pageTitle = $noTitle ? '' : $pageTitle;
        if ($showTitleFirst) {
            $temp = $siteTitle;
            $siteTitle = $pageTitle;
            $pageTitle = $temp;
        }
        // only show a separator if there are both site title and page title
        if ($pageTitle === '' || $siteTitle === '') {
            $pageTitleSeparator = '';
        } elseif (empty($pageTitleSeparator)) {
            // use the default separator if non given
            $pageTitleSeparator = ': ';
        }
        return $siteTitle . $pageTitleSeparator . $pageTitle;
    }

    /**
     * Processes the INTinclude-scripts
     */
    public function INTincScript()
    {
        $this->additionalHeaderData = (isset($this->config['INTincScript_ext']['additionalHeaderData']) && is_array($this->config['INTincScript_ext']['additionalHeaderData']))
            ? $this->config['INTincScript_ext']['additionalHeaderData']
            : [];
        $this->additionalFooterData = (isset($this->config['INTincScript_ext']['additionalFooterData']) && is_array($this->config['INTincScript_ext']['additionalFooterData']))
            ? $this->config['INTincScript_ext']['additionalFooterData']
            : [];
        $this->additionalJavaScript = $this->config['INTincScript_ext']['additionalJavaScript'] ?? null;
        $this->additionalCSS = $this->config['INTincScript_ext']['additionalCSS'] ?? null;
        $this->divSection = '';
        if (empty($this->config['INTincScript_ext']['pageRenderer'])) {
            $this->initPageRenderer();
        } else {
            /** @var PageRenderer $pageRenderer */
            $pageRenderer = unserialize($this->config['INTincScript_ext']['pageRenderer']);
            $this->pageRenderer = $pageRenderer;
            GeneralUtility::setSingletonInstance(PageRenderer::class, $pageRenderer);
        }

        $this->recursivelyReplaceIntPlaceholdersInContent();
        $this->getTimeTracker()->push('Substitute header section');
        $this->INTincScript_loadJSCode();
        $this->generatePageTitle();

        $this->content = str_replace(
            [
                '<!--HD_' . $this->config['INTincScript_ext']['divKey'] . '-->',
                '<!--FD_' . $this->config['INTincScript_ext']['divKey'] . '-->',
                '<!--TDS_' . $this->config['INTincScript_ext']['divKey'] . '-->'
            ],
            [
                $this->convOutputCharset(implode(LF, $this->additionalHeaderData)),
                $this->convOutputCharset(implode(LF, $this->additionalFooterData)),
                $this->convOutputCharset($this->divSection),
            ],
            $this->pageRenderer->renderJavaScriptAndCssForProcessingOfUncachedContentObjects($this->content, $this->config['INTincScript_ext']['divKey'])
        );
        // Replace again, because header and footer data and page renderer replacements may introduce additional placeholders (see #44825)
        $this->recursivelyReplaceIntPlaceholdersInContent();
        $this->setAbsRefPrefix();
        $this->getTimeTracker()->pull();
    }

    /**
     * Replaces INT placeholders (COA_INT and USER_INT) in $this->content
     * In case the replacement adds additional placeholders, it loops
     * until no new placeholders are found any more.
     */
    protected function recursivelyReplaceIntPlaceholdersInContent()
    {
        do {
            $INTiS_config = $this->config['INTincScript'];
            $this->INTincScript_process($INTiS_config);
            // Check if there were new items added to INTincScript during the previous execution:
            // array_diff_assoc throws notices if values are arrays but not strings. We suppress this here.
            $INTiS_config = @array_diff_assoc($this->config['INTincScript'], $INTiS_config);
            $reprocess = count($INTiS_config) > 0;
        } while ($reprocess);
    }

    /**
     * Processes the INTinclude-scripts and substitue in content.
     *
     * @param array $INTiS_config $GLOBALS['TSFE']->config['INTincScript'] or part of it
     * @see INTincScript()
     */
    protected function INTincScript_process($INTiS_config)
    {
        $timeTracker = $this->getTimeTracker();
        $timeTracker->push('Split content');
        // Splits content with the key.
        $INTiS_splitC = explode('<!--INT_SCRIPT.', $this->content);
        $this->content = '';
        $timeTracker->setTSlogMessage('Parts: ' . count($INTiS_splitC));
        $timeTracker->pull();
        foreach ($INTiS_splitC as $INTiS_c => $INTiS_cPart) {
            // If the split had a comment-end after 32 characters it's probably a split-string
            if (substr($INTiS_cPart, 32, 3) === '-->') {
                $INTiS_key = 'INT_SCRIPT.' . substr($INTiS_cPart, 0, 32);
                if (is_array($INTiS_config[$INTiS_key])) {
                    $label = 'Include ' . $INTiS_config[$INTiS_key]['type'];
                    $label = $label . isset($INTiS_config[$INTiS_key]['file']) ? ' ' . $INTiS_config[$INTiS_key]['file'] : '';
                    $timeTracker->push($label);
                    $incContent = '';
                    $INTiS_cObj = unserialize($INTiS_config[$INTiS_key]['cObj']);
                    /* @var ContentObjectRenderer $INTiS_cObj */
                    switch ($INTiS_config[$INTiS_key]['type']) {
                        case 'COA':
                            $incContent = $INTiS_cObj->cObjGetSingle('COA', $INTiS_config[$INTiS_key]['conf']);
                            break;
                        case 'FUNC':
                            $incContent = $INTiS_cObj->cObjGetSingle('USER', $INTiS_config[$INTiS_key]['conf']);
                            break;
                        case 'POSTUSERFUNC':
                            $incContent = $INTiS_cObj->callUserFunction($INTiS_config[$INTiS_key]['postUserFunc'], $INTiS_config[$INTiS_key]['conf'], $INTiS_config[$INTiS_key]['content']);
                            break;
                    }
                    $this->content .= $this->convOutputCharset($incContent);
                    $this->content .= substr($INTiS_cPart, 35);
                    $timeTracker->pull($incContent);
                } else {
                    $this->content .= substr($INTiS_cPart, 35);
                }
            } else {
                $this->content .= ($INTiS_c ? '<!--INT_SCRIPT.' : '') . $INTiS_cPart;
            }
        }
    }

    /**
     * Loads the JavaScript code for INTincScript
     */
    public function INTincScript_loadJSCode()
    {
        // Add javascript
        $jsCode = trim($this->JSCode);
        $additionalJavaScript = is_array($this->additionalJavaScript)
            ? implode(LF, $this->additionalJavaScript)
            : $this->additionalJavaScript;
        $additionalJavaScript = trim($additionalJavaScript);
        if ($jsCode !== '' || $additionalJavaScript !== '') {
            $this->additionalHeaderData['JSCode'] = '
<script type="text/javascript">
	/*<![CDATA[*/
<!--
' . $additionalJavaScript . '
' . $jsCode . '
// -->
	/*]]>*/
</script>';
        }
        // Add CSS
        $additionalCss = is_array($this->additionalCSS) ? implode(LF, $this->additionalCSS) : $this->additionalCSS;
        $additionalCss = trim($additionalCss);
        if ($additionalCss !== '') {
            $this->additionalHeaderData['_CSS'] = '
<style type="text/css">
' . $additionalCss . '
</style>';
        }
    }

    /**
     * Determines if there are any INTincScripts to include.
     *
     * @return bool Returns TRUE if scripts are found and no URL handler is active.
     */
    public function isINTincScript()
    {
        return is_array($this->config['INTincScript']);
    }

    /********************************************
     *
     * Finished off; outputting, storing session data, statistics...
     *
     *******************************************/
    /**
     * Determines if content should be outputted.
     * Outputting content is done only if no URL handler is active and no hook disables the output.
     *
     * @return bool Returns TRUE if no redirect URL is set and no hook disables the output.
     */
    public function isOutputting()
    {
        // Initialize by status if there is a Redirect URL
        $enableOutput = true;
        // Call hook for possible disabling of output:
        $_params = ['pObj' => &$this, 'enableOutput' => &$enableOutput];
        foreach ($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['tslib/class.tslib_fe.php']['isOutputting'] ?? [] as $_funcRef) {
            GeneralUtility::callUserFunction($_funcRef, $_params, $this);
        }
        return $enableOutput;
    }

    /**
     * Process the output before it's actually outputted.
     *
     * This includes substituting the "username" comment.
     * Works on $this->content.
     */
    public function processContentForOutput()
    {
        // Make substitution of eg. username/uid in content only if cache-headers for client/proxy caching is NOT sent!
        if (!$this->isClientCachable) {
            $this->contentStrReplace();
        }
        // Hook for post-processing of page content before output:
        $_params = ['pObj' => &$this];
        foreach ($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['tslib/class.tslib_fe.php']['contentPostProc-output'] ?? [] as $_funcRef) {
            GeneralUtility::callUserFunction($_funcRef, $_params, $this);
        }
    }

    /**
     * Add HTTP headers to the response object.
     *
     * @param ResponseInterface $response
     * @return ResponseInterface
     */
    public function applyHttpHeadersToResponse(ResponseInterface $response): ResponseInterface
    {
        // Set header for charset-encoding unless disabled
        if (empty($this->config['config']['disableCharsetHeader'])) {
            $response = $response->withHeader('Content-Type', $this->contentType . '; charset=' . trim($this->metaCharset));
        }
        // Set header for content language unless disabled
        if (empty($this->config['config']['disableLanguageHeader']) && !empty($this->sys_language_isocode)) {
            $response = $response->withHeader('Content-Language', trim($this->sys_language_isocode));
        }
        // Set cache related headers to client (used to enable proxy / client caching!)
        if (!empty($this->config['config']['sendCacheHeaders'])) {
            $headers = $this->getCacheHeaders();
            foreach ($headers as $header => $value) {
                $response = $response->withHeader($header, $value);
            }
        }
        // Set additional headers if any have been configured via TypoScript
        $additionalHeaders = $this->getAdditionalHeaders();
        foreach ($additionalHeaders as $headerConfig) {
            list($header, $value) = GeneralUtility::trimExplode(':', $headerConfig['header'], false, 2);
            if ($headerConfig['statusCode']) {
                $response = $response->withStatus((int)$headerConfig['statusCode']);
            }
            if ($headerConfig['replace']) {
                $response = $response->withHeader($header, $value);
            } else {
                $response = $response->withAddedHeader($header, $value);
            }
        }
        // Send appropriate status code in case of temporary content
        if ($this->tempContent) {
            $response = $response->withStatus(503, 'Service unavailable');
            $headers = $this->getHttpHeadersForTemporaryContent();
            foreach ($headers as $header => $value) {
                $response = $response->withHeader($header, $value);
            }
        }
        return $response;
    }

    /**
     * Get cache headers good for client/reverse proxy caching.
     * This function should not be called if the page content is temporary (like for "Page is being generated..."
     * message, but in that case it is ok because the config-variables are not yet available and so will not allow to
     * send cache headers).
     *
     * @return array
     */
    protected function getCacheHeaders(): array
    {
        // Getting status whether we can send cache control headers for proxy caching:
        $doCache = $this->isStaticCacheble();
        // This variable will be TRUE unless cache headers are configured to be sent ONLY if a branch does not allow logins and logins turns out to be allowed anyway...
        $loginsDeniedCfg = empty($this->config['config']['sendCacheHeaders_onlyWhenLoginDeniedInBranch']) || empty($this->loginAllowedInBranch);
        // Finally, when backend users are logged in, do not send cache headers at all (Admin Panel might be displayed for instance).
        $this->isClientCachable = $doCache && !$this->isBackendUserLoggedIn() && !$this->doWorkspacePreview() && $loginsDeniedCfg;
        if ($this->isClientCachable) {
            $headers = [
                'Expires' => gmdate('D, d M Y H:i:s T', $this->cacheExpires),
                'ETag' => '"' . md5($this->content) . '"',
                'Cache-Control' => 'max-age=' . ($this->cacheExpires - $GLOBALS['EXEC_TIME']),
                // no-cache
                'Pragma' => 'public'
            ];
        } else {
            // "no-store" is used to ensure that the client HAS to ask the server every time, and is not allowed to store anything at all
            $headers = [
                'Cache-Control' => 'private, no-store'
            ];
            // Now, if a backend user is logged in, tell him in the Admin Panel log what the caching status would have been:
            if ($this->isBackendUserLoggedIn()) {
                if ($doCache) {
                    $this->getTimeTracker()->setTSlogMessage('Cache-headers with max-age "' . ($this->cacheExpires - $GLOBALS['EXEC_TIME']) . '" would have been sent');
                } else {
                    $reasonMsg = [];
                    if ($this->no_cache) {
                        $reasonMsg[] = 'Caching disabled (no_cache).';
                    }
                    if ($this->isINTincScript()) {
                        $reasonMsg[] = '*_INT object(s) on page.';
                    }
                    if (is_array($this->fe_user->user)) {
                        $reasonMsg[] = 'Frontend user logged in.';
                    }
                    $this->getTimeTracker()->setTSlogMessage('Cache-headers would disable proxy caching! Reason(s): "' . implode(' ', $reasonMsg) . '"', 1);
                }
            }
        }
        return $headers;
    }

    /**
     * Reporting status whether we can send cache control headers for proxy caching or publishing to static files
     *
     * Rules are:
     * no_cache cannot be set: If it is, the page might contain dynamic content and should never be cached.
     * There can be no USER_INT objects on the page ("isINTincScript()") because they implicitly indicate dynamic content
     * There can be no logged in user because user sessions are based on a cookie and thereby does not offer client caching a chance to know if the user is logged in. Actually, there will be a reverse problem here; If a page will somehow change when a user is logged in he may not see it correctly if the non-login version sent a cache-header! So do NOT use cache headers in page sections where user logins change the page content. (unless using such as realurl to apply a prefix in case of login sections)
     *
     * @return bool
     */
    public function isStaticCacheble()
    {
        return !$this->no_cache && !$this->isINTincScript() && !$this->isUserOrGroupSet();
    }

    /**
     * Substitute various tokens in content. This should happen only if the content is not cached by proxies or client browsers.
     */
    protected function contentStrReplace()
    {
        $search = [];
        $replace = [];
        // Substitutes get_URL_ID in case of GET-fallback
        if ($this->getMethodUrlIdToken) {
            $search[] = $this->getMethodUrlIdToken;
            $replace[] = $this->fe_user->get_URL_ID;
        }
        // Hook for supplying custom search/replace data
        foreach ($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['tslib/class.tslib_fe.php']['tslib_fe-contentStrReplace'] ?? [] as $_funcRef) {
            $_params = [
                'search' => &$search,
                'replace' => &$replace
            ];
            GeneralUtility::callUserFunction($_funcRef, $_params, $this);
        }
        if (!empty($search)) {
            $this->content = str_replace($search, $replace, $this->content);
        }
    }

    /**
     * Returns HTTP headers for temporary content.
     * These headers prevent search engines from caching temporary content and asks them to revisit this page again.
     * Please ensure to also send a 503 HTTP Status code with these headers.
     */
    protected function getHttpHeadersForTemporaryContent(): array
    {
        return [
            'Retry-after' => '3600',
            'Pragma' => 'no-cache',
            'Cache-control' => 'no-cache',
            'Expires' => '0',
        ];
    }
    /********************************************
     *
     * Various internal API functions
     *
     *******************************************/

    /**
     * Creates an instance of ContentObjectRenderer in $this->cObj
     * This instance is used to start the rendering of the TypoScript template structure
     *
     * @see RequestHandler
     */
    public function newCObj()
    {
        $this->cObj = GeneralUtility::makeInstance(ContentObjectRenderer::class, $this);
        $this->cObj->start($this->page, 'pages');
    }

    /**
     * Converts relative paths in the HTML source to absolute paths for fileadmin/, typo3conf/ext/ and media/ folders.
     *
     * @internal
     * @see RequestHandler, INTincScript()
     */
    public function setAbsRefPrefix()
    {
        if (!$this->absRefPrefix) {
            return;
        }
        $search = [
            '"typo3temp/',
            '"' . PathUtility::stripPathSitePrefix(Environment::getExtensionsPath()) . '/',
            '"' . PathUtility::stripPathSitePrefix(Environment::getBackendPath()) . '/ext/',
            '"' . PathUtility::stripPathSitePrefix(Environment::getFrameworkBasePath()) . '/',
        ];
        $replace = [
            '"' . $this->absRefPrefix . 'typo3temp/',
            '"' . $this->absRefPrefix . PathUtility::stripPathSitePrefix(Environment::getExtensionsPath()) . '/',
            '"' . $this->absRefPrefix . PathUtility::stripPathSitePrefix(Environment::getBackendPath()) . '/ext/',
            '"' . $this->absRefPrefix . PathUtility::stripPathSitePrefix(Environment::getFrameworkBasePath()) . '/',
        ];
        /** @var StorageRepository $storageRepository */
        $storageRepository = GeneralUtility::makeInstance(StorageRepository::class);
        $storages = $storageRepository->findAll();
        foreach ($storages as $storage) {
            if ($storage->getDriverType() === 'Local' && $storage->isPublic() && $storage->isOnline()) {
                $folder = $storage->getPublicUrl($storage->getRootLevelFolder(), true);
                $search[] = '"' . $folder;
                $replace[] = '"' . $this->absRefPrefix . $folder;
            }
        }
        // Process additional directories
        $directories = GeneralUtility::trimExplode(',', $GLOBALS['TYPO3_CONF_VARS']['FE']['additionalAbsRefPrefixDirectories'], true);
        foreach ($directories as $directory) {
            $search[] = '"' . $directory;
            $replace[] = '"' . $this->absRefPrefix . $directory;
        }
        $this->content = str_replace(
            $search,
            $replace,
            $this->content
        );
    }

    /**
     * Prefixing the input URL with ->baseUrl If ->baseUrl is set and the input url is not absolute in some way.
     * Designed as a wrapper functions for use with all frontend links that are processed by JavaScript (for "realurl" compatibility!). So each time a URL goes into window.open, window.location.href or otherwise, wrap it with this function!
     *
     * @param string $url Input URL, relative or absolute
     * @return string Processed input value.
     */
    public function baseUrlWrap($url)
    {
        if ($this->baseUrl) {
            $urlParts = parse_url($url);
            if (empty($urlParts['scheme']) && $url[0] !== '/') {
                $url = $this->baseUrl . $url;
            }
        }
        return $url;
    }

    /**
     * Logs access to deprecated TypoScript objects and properties.
     *
     * Dumps message to the TypoScript message log (admin panel) and the TYPO3 deprecation log.
     *
     * @param string $typoScriptProperty Deprecated object or property
     * @param string $explanation Message or additional information
     */
    public function logDeprecatedTyposcript($typoScriptProperty, $explanation = '')
    {
        $explanationText = $explanation !== '' ? ' - ' . $explanation : '';
        $this->getTimeTracker()->setTSlogMessage($typoScriptProperty . ' is deprecated.' . $explanationText, 2);
        trigger_error('TypoScript property ' . $typoScriptProperty . ' is deprecated' . $explanationText, E_USER_DEPRECATED);
    }

    /********************************************
     * PUBLIC ACCESSIBLE WORKSPACES FUNCTIONS
     *******************************************/

    /**
     * Returns TRUE if workspace preview is enabled
     *
     * @return bool Returns TRUE if workspace preview is enabled
     */
    public function doWorkspacePreview()
    {
        return $this->context->getPropertyFromAspect('workspace', 'isOffline', false);
    }

    /**
     * Returns the uid of the current workspace
     *
     * @return int returns workspace integer for which workspace is being preview. 0 if none (= live workspace).
     */
    public function whichWorkspace(): int
    {
        return $this->context->getPropertyFromAspect('workspace', 'id', 0);
    }

    /********************************************
     *
     * Various external API functions - for use in plugins etc.
     *
     *******************************************/

    /**
     * Returns the pages TSconfig array based on the currect ->rootLine
     *
     * @return array
     */
    public function getPagesTSconfig()
    {
        if (!is_array($this->pagesTSconfig)) {
            $TSdataArray = [];
            foreach ($this->rootLine as $k => $v) {
                // add TSconfig first, as $TSdataArray is reversed below and it shall be included last
                $TSdataArray[] = $v['TSconfig'];
                if (trim($v['tsconfig_includes'])) {
                    $includeTsConfigFileList = GeneralUtility::trimExplode(',', $v['tsconfig_includes'], true);
                    // reverse the includes first to make sure their order is preserved when $TSdataArray is reversed
                    $includeTsConfigFileList = array_reverse($includeTsConfigFileList);
                    // Traversing list
                    foreach ($includeTsConfigFileList as $includeTsConfigFile) {
                        if (strpos($includeTsConfigFile, 'EXT:') === 0) {
                            list($includeTsConfigFileExtensionKey, $includeTsConfigFilename) = explode(
                                '/',
                                substr($includeTsConfigFile, 4),
                                2
                            );
                            if ((string)$includeTsConfigFileExtensionKey !== ''
                                && (string)$includeTsConfigFilename !== ''
                                && ExtensionManagementUtility::isLoaded($includeTsConfigFileExtensionKey)
                            ) {
                                $includeTsConfigFileAndPath = ExtensionManagementUtility::extPath($includeTsConfigFileExtensionKey)
                                    . $includeTsConfigFilename;
                                if (file_exists($includeTsConfigFileAndPath)) {
                                    $TSdataArray[] = file_get_contents($includeTsConfigFileAndPath);
                                }
                            }
                        }
                    }
                }
            }
            // Adding the default configuration:
            $TSdataArray[] = $GLOBALS['TYPO3_CONF_VARS']['BE']['defaultPageTSconfig'];
            // Bring everything in the right order. Default first, then the Rootline down to the current page
            $TSdataArray = array_reverse($TSdataArray);
            // Parsing the user TS (or getting from cache)
            $TSdataArray = TypoScriptParser::checkIncludeLines_array($TSdataArray);
            $userTS = implode(LF . '[GLOBAL]' . LF, $TSdataArray);
            $identifier = md5('pageTS:' . $userTS);
            $contentHashCache = GeneralUtility::makeInstance(CacheManager::class)->getCache('cache_hash');
            $this->pagesTSconfig = $contentHashCache->get($identifier);
            if (!is_array($this->pagesTSconfig)) {
                $parseObj = GeneralUtility::makeInstance(TypoScriptParser::class);
                $parseObj->parse($userTS);
                $this->pagesTSconfig = $parseObj->setup;
                $contentHashCache->set($identifier, $this->pagesTSconfig, ['PAGES_TSconfig'], 0);
            }
        }
        return $this->pagesTSconfig;
    }

    /**
     * Sets JavaScript code in the additionalJavaScript array
     *
     * @param string $key is the key in the array, for num-key let the value be empty. Note reserved key: 'openPic'
     * @param string $content is the content if you want any
     * @see ContentObjectRenderer::imageLinkWrap()
     */
    public function setJS($key, $content = '')
    {
        if ($key === 'openPic') {
            $this->additionalJavaScript[$key] = '	function openPic(url, winName, winParams) {
                var theWindow = window.open(url, winName, winParams);
                if (theWindow)	{theWindow.focus();}
            }';
        } elseif ($key) {
            $this->additionalJavaScript[$key] = $content;
        }
    }

    /**
     * Returns a unique md5 hash.
     * There is no special magic in this, the only point is that you don't have to call md5(uniqid()) which is slow and by this you are sure to get a unique string each time in a little faster way.
     *
     * @param string $str Some string to include in what is hashed. Not significant at all.
     * @return string MD5 hash of ->uniqueString, input string and uniqueCounter
     */
    public function uniqueHash($str = '')
    {
        return md5($this->uniqueString . '_' . $str . $this->uniqueCounter++);
    }

    /**
     * Sets the cache-flag to 1. Could be called from user-included php-files in order to ensure that a page is not cached.
     *
     * @param string $reason An optional reason to be written to the log.
     * @param bool $internal Whether the call is done from core itself (should only be used by core).
     */
    public function set_no_cache($reason = '', $internal = false)
    {
        if ($reason !== '') {
            $warning = '$TSFE->set_no_cache() was triggered. Reason: ' . $reason . '.';
        } else {
            $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1);
            // This is a hack to work around ___FILE___ resolving symbolic links
            $realWebPath = PathUtility::dirname(realpath(Environment::getBackendPath())) . '/';
            $file = $trace[0]['file'];
            if (strpos($file, $realWebPath) === 0) {
                $file = str_replace($realWebPath, '', $file);
            } else {
                $file = str_replace(Environment::getPublicPath() . '/', '', $file);
            }
            $line = $trace[0]['line'];
            $trigger = $file . ' on line ' . $line;
            $warning = '$GLOBALS[\'TSFE\']->set_no_cache() was triggered by ' . $trigger . '.';
        }
        if ($GLOBALS['TYPO3_CONF_VARS']['FE']['disableNoCacheParameter']) {
            $warning .= ' However, $TYPO3_CONF_VARS[\'FE\'][\'disableNoCacheParameter\'] is set, so it will be ignored!';
            $this->getTimeTracker()->setTSlogMessage($warning, 2);
        } else {
            $warning .= ' Caching is disabled!';
            $this->disableCache();
        }
        if ($internal && $this->isBackendUserLoggedIn()) {
            $this->logger->notice($warning);
        } else {
            $this->logger->warning($warning);
        }
    }

    /**
     * Disables caching of the current page.
     *
     * @internal
     */
    protected function disableCache()
    {
        $this->no_cache = true;
    }

    /**
     * Sets the cache-timeout in seconds
     *
     * @param int $seconds Cache-timeout in seconds
     */
    public function set_cache_timeout_default($seconds)
    {
        $this->cacheTimeOutDefault = (int)$seconds;
    }

    /**
     * Get the cache timeout for the current page.
     *
     * @return int The cache timeout for the current page.
     */
    public function get_cache_timeout()
    {
        /** @var \TYPO3\CMS\Core\Cache\Frontend\AbstractFrontend $runtimeCache */
        $runtimeCache = GeneralUtility::makeInstance(CacheManager::class)->getCache('cache_runtime');
        $cachedCacheLifetimeIdentifier = 'core-tslib_fe-get_cache_timeout';
        $cachedCacheLifetime = $runtimeCache->get($cachedCacheLifetimeIdentifier);
        if ($cachedCacheLifetime === false) {
            if ($this->page['cache_timeout']) {
                // Cache period was set for the page:
                $cacheTimeout = $this->page['cache_timeout'];
            } elseif ($this->cacheTimeOutDefault) {
                // Cache period was set for the whole site:
                $cacheTimeout = $this->cacheTimeOutDefault;
            } else {
                // No cache period set at all, so we take one day (60*60*24 seconds = 86400 seconds):
                $cacheTimeout = 86400;
            }
            if (!empty($this->config['config']['cache_clearAtMidnight'])) {
                $timeOutTime = $GLOBALS['EXEC_TIME'] + $cacheTimeout;
                $midnightTime = mktime(0, 0, 0, date('m', $timeOutTime), date('d', $timeOutTime), date('Y', $timeOutTime));
                // If the midnight time of the expire-day is greater than the current time,
                // we may set the timeOutTime to the new midnighttime.
                if ($midnightTime > $GLOBALS['EXEC_TIME']) {
                    $cacheTimeout = $midnightTime - $GLOBALS['EXEC_TIME'];
                }
            }

            // Calculate the timeout time for records on the page and adjust cache timeout if necessary
            $cacheTimeout = min($this->calculatePageCacheTimeout(), $cacheTimeout);

            foreach ($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['tslib/class.tslib_fe.php']['get_cache_timeout'] ?? [] as $_funcRef) {
                $params = ['cacheTimeout' => $cacheTimeout];
                $cacheTimeout = GeneralUtility::callUserFunction($_funcRef, $params, $this);
            }
            $runtimeCache->set($cachedCacheLifetimeIdentifier, $cacheTimeout);
            $cachedCacheLifetime = $cacheTimeout;
        }
        return $cachedCacheLifetime;
    }

    /*********************************************
     *
     * Localization and character set conversion
     *
     *********************************************/
    /**
     * Split Label function for front-end applications.
     *
     * @param string $input Key string. Accepts the "LLL:" prefix.
     * @return string Label value, if any.
     */
    public function sL($input)
    {
        return $this->languageService->sL($input);
    }

    /**
     * Sets all internal measures what language the page should be rendered.
     * This is not for records, but rather the HTML / charset and the locallang labels
     *
     * @param string $language - usually set via TypoScript config.language = dk
     */
    protected function setOutputLanguage($language = 'default')
    {
        $this->pageRenderer->setLanguage($language);
        $this->languageService = GeneralUtility::makeInstance(LanguageService::class);
        // Always disable debugging for TSFE
        $this->languageService->debugKey = false;
        $this->languageService->init($language);
    }

    /**
     * Converts input string from utf-8 to metaCharset IF the two charsets are different.
     *
     * @param string $content Content to be converted.
     * @return string Converted content string.
     * @throws \RuntimeException if an invalid charset was configured
     */
    public function convOutputCharset($content)
    {
        if ($this->metaCharset !== 'utf-8') {
            /** @var CharsetConverter $charsetConverter */
            $charsetConverter = GeneralUtility::makeInstance(CharsetConverter::class);
            try {
                $content = $charsetConverter->conv($content, 'utf-8', $this->metaCharset);
            } catch (UnknownCharsetException $e) {
                throw new \RuntimeException('Invalid config.metaCharset: ' . $e->getMessage(), 1508916185);
            }
        }
        return $content;
    }

    /**
     * Calculates page cache timeout according to the records with starttime/endtime on the page.
     *
     * @return int Page cache timeout or PHP_INT_MAX if cannot be determined
     */
    protected function calculatePageCacheTimeout()
    {
        $result = PHP_INT_MAX;
        // Get the configuration
        $tablesToConsider = $this->getCurrentPageCacheConfiguration();
        // Get the time, rounded to the minute (do not pollute MySQL cache!)
        // It is ok that we do not take seconds into account here because this
        // value will be subtracted later. So we never get the time "before"
        // the cache change.
        $now = $GLOBALS['ACCESS_TIME'];
        // Find timeout by checking every table
        foreach ($tablesToConsider as $tableDef) {
            $result = min($result, $this->getFirstTimeValueForRecord($tableDef, $now));
        }
        // We return + 1 second just to ensure that cache is definitely regenerated
        return $result === PHP_INT_MAX ? PHP_INT_MAX : $result - $now + 1;
    }

    /**
     * Obtains a list of table/pid pairs to consider for page caching.
     *
     * TS configuration looks like this:
     *
     * The cache lifetime of all pages takes starttime and endtime of news records of page 14 into account:
     * config.cache.all = tt_news:14
     *
     * The cache lifetime of page 42 takes starttime and endtime of news records of page 15 and addresses of page 16 into account:
     * config.cache.42 = tt_news:15,tt_address:16
     *
     * @return array Array of 'tablename:pid' pairs. There is at least a current page id in the array
     * @see TypoScriptFrontendController::calculatePageCacheTimeout()
     */
    protected function getCurrentPageCacheConfiguration()
    {
        $result = ['tt_content:' . $this->id];
        if (isset($this->config['config']['cache.'][$this->id])) {
            $result = array_merge($result, GeneralUtility::trimExplode(',', $this->config['config']['cache.'][$this->id]));
        }
        if (isset($this->config['config']['cache.']['all'])) {
            $result = array_merge($result, GeneralUtility::trimExplode(',', $this->config['config']['cache.']['all']));
        }
        return array_unique($result);
    }

    /**
     * Find the minimum starttime or endtime value in the table and pid that is greater than the current time.
     *
     * @param string $tableDef Table definition (format tablename:pid)
     * @param int $now "Now" time value
     * @throws \InvalidArgumentException
     * @return int Value of the next start/stop time or PHP_INT_MAX if not found
     * @see TypoScriptFrontendController::calculatePageCacheTimeout()
     */
    protected function getFirstTimeValueForRecord($tableDef, $now)
    {
        $now = (int)$now;
        $result = PHP_INT_MAX;
        list($tableName, $pid) = GeneralUtility::trimExplode(':', $tableDef);
        if (empty($tableName) || empty($pid)) {
            throw new \InvalidArgumentException('Unexpected value for parameter $tableDef. Expected <tablename>:<pid>, got \'' . htmlspecialchars($tableDef) . '\'.', 1307190365);
        }

        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable($tableName);
        $queryBuilder->getRestrictions()
            ->removeByType(StartTimeRestriction::class)
            ->removeByType(EndTimeRestriction::class);
        $timeFields = [];
        $timeConditions = $queryBuilder->expr()->orX();
        foreach (['starttime', 'endtime'] as $field) {
            if (isset($GLOBALS['TCA'][$tableName]['ctrl']['enablecolumns'][$field])) {
                $timeFields[$field] = $GLOBALS['TCA'][$tableName]['ctrl']['enablecolumns'][$field];
                $queryBuilder->addSelectLiteral(
                    'MIN('
                        . 'CASE WHEN '
                        . $queryBuilder->expr()->lte(
                            $timeFields[$field],
                            $queryBuilder->createNamedParameter($now, \PDO::PARAM_INT)
                        )
                        . ' THEN NULL ELSE ' . $queryBuilder->quoteIdentifier($timeFields[$field]) . ' END'
                        . ') AS ' . $queryBuilder->quoteIdentifier($timeFields[$field])
                );
                $timeConditions->add(
                    $queryBuilder->expr()->gt(
                        $timeFields[$field],
                        $queryBuilder->createNamedParameter($now, \PDO::PARAM_INT)
                    )
                );
            }
        }

        // if starttime or endtime are defined, evaluate them
        if (!empty($timeFields)) {
            // find the timestamp, when the current page's content changes the next time
            $row = $queryBuilder
                ->from($tableName)
                ->where(
                    $queryBuilder->expr()->eq(
                        'pid',
                        $queryBuilder->createNamedParameter($pid, \PDO::PARAM_INT)
                    ),
                    $timeConditions
                )
                ->execute()
                ->fetch();

            if ($row) {
                foreach ($timeFields as $timeField => $_) {
                    // if a MIN value is found, take it into account for the
                    // cache lifetime we have to filter out start/endtimes < $now,
                    // as the SQL query also returns rows with starttime < $now
                    // and endtime > $now (and using a starttime from the past
                    // would be wrong)
                    if ($row[$timeField] !== null && (int)$row[$timeField] > $now) {
                        $result = min($result, (int)$row[$timeField]);
                    }
                }
            }
        }

        return $result;
    }

    /**
     * Fetches the originally requested id, fallsback to $this->id
     *
     * @return int the originally requested page uid
     * @see fetch_the_id()
     */
    public function getRequestedId()
    {
        return $this->requestedId ?: $this->id;
    }

    /**
     * Acquire a page specific lock
     *
     * @param string $type
     * @param string $key
     * @throws \InvalidArgumentException
     * @throws \RuntimeException
     * @throws \TYPO3\CMS\Core\Cache\Exception\NoSuchCacheException
     */
    protected function acquireLock($type, $key)
    {
        $lockFactory = GeneralUtility::makeInstance(LockFactory::class);
        $this->locks[$type]['accessLock'] = $lockFactory->createLocker($type);

        $this->locks[$type]['pageLock'] = $lockFactory->createLocker(
            $key,
            LockingStrategyInterface::LOCK_CAPABILITY_EXCLUSIVE | LockingStrategyInterface::LOCK_CAPABILITY_NOBLOCK
        );

        do {
            if (!$this->locks[$type]['accessLock']->acquire()) {
                throw new \RuntimeException('Could not acquire access lock for "' . $type . '"".', 1294586098);
            }

            try {
                $locked = $this->locks[$type]['pageLock']->acquire(
                    LockingStrategyInterface::LOCK_CAPABILITY_EXCLUSIVE | LockingStrategyInterface::LOCK_CAPABILITY_NOBLOCK
                );
            } catch (LockAcquireWouldBlockException $e) {
                // somebody else has the lock, we keep waiting

                // first release the access lock
                $this->locks[$type]['accessLock']->release();
                // now lets make a short break (100ms) until we try again, since
                // the page generation by the lock owner will take a while anyways
                usleep(100000);
                continue;
            }
            $this->locks[$type]['accessLock']->release();
            if ($locked) {
                break;
            }
            throw new \RuntimeException('Could not acquire page lock for ' . $key . '.', 1460975877);
        } while (true);
    }

    /**
     * Release a page specific lock
     *
     * @param string $type
     * @throws \InvalidArgumentException
     * @throws \RuntimeException
     * @throws \TYPO3\CMS\Core\Cache\Exception\NoSuchCacheException
     */
    protected function releaseLock($type)
    {
        if ($this->locks[$type]['accessLock']) {
            if (!$this->locks[$type]['accessLock']->acquire()) {
                throw new \RuntimeException('Could not acquire access lock for "' . $type . '"".', 1460975902);
            }

            $this->locks[$type]['pageLock']->release();
            $this->locks[$type]['pageLock']->destroy();
            $this->locks[$type]['pageLock'] = null;

            $this->locks[$type]['accessLock']->release();
            $this->locks[$type]['accessLock'] = null;
        }
    }

    /**
     * Send additional headers from config.additionalHeaders
     */
    protected function getAdditionalHeaders(): array
    {
        if (!isset($this->config['config']['additionalHeaders.'])) {
            return [];
        }
        $additionalHeaders = [];
        ksort($this->config['config']['additionalHeaders.']);
        foreach ($this->config['config']['additionalHeaders.'] as $options) {
            if (!is_array($options)) {
                continue;
            }
            $header = trim($options['header'] ?? '');
            if ($header === '') {
                continue;
            }
            $additionalHeaders[] = [
                'header' => $header,
                // "replace existing headers" is turned on by default, unless turned off
                'replace' => ($options['replace'] ?? '') !== '0',
                'statusCode' => (int)($options['httpResponseCode'] ?? 0) ?: null
            ];
        }
        return $additionalHeaders;
    }

    /**
     * Returns the current BE user.
     *
     * @return \TYPO3\CMS\Backend\FrontendBackendUserAuthentication
     */
    protected function getBackendUser()
    {
        return $GLOBALS['BE_USER'];
    }

    /**
     * @return TimeTracker
     */
    protected function getTimeTracker()
    {
        return GeneralUtility::makeInstance(TimeTracker::class);
    }

    /**
     * Returns the currently configured "site language" if a site is configured (= resolved) in the current request.
     *
     * @internal
     */
    protected function getCurrentSiteLanguage(): ?SiteLanguage
    {
        if (isset($GLOBALS['TYPO3_REQUEST'])
            && $GLOBALS['TYPO3_REQUEST'] instanceof ServerRequestInterface
            && $GLOBALS['TYPO3_REQUEST']->getAttribute('language') instanceof SiteLanguage) {
            return $GLOBALS['TYPO3_REQUEST']->getAttribute('language');
        }
        return null;
    }
}
