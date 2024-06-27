<?php

declare(strict_types=1);

namespace TYPO3\CMS\Taskcenter\Controller;

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
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Module\ModuleData;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Http\HtmlResponse;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageQueue;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\PathUtility;
use TYPO3\CMS\Fluid\View\StandaloneView;
use TYPO3\CMS\Taskcenter\TaskInterface;

/**
 * This class provides a task center for BE users
 * @internal This is a specific Backend Controller implementation and is not considered part of the Public TYPO3 API.
 */
class TaskModuleController
{
    protected ?ServerRequestInterface $request = null;
    protected ?ResponseFactoryInterface $responseFactory = null;
    protected ?ModuleData $moduleData = null;

    /**
     * Loaded with the global array $mConf which holds some module configuration from the conf.php file of backend modules.
     *
     * @see init()
     * @var array
     */
    protected array $mConf = [];

    /**
     * The integer value of the GET/POST var, 'id'. Used for submodules to the 'Web' module (page id)
     *
     * @see init()
     * @var int
     */
    protected int $id;

    /**
     * The module menu items array. Each key represents a key for which values can range between the items in the array of that key.
     *
     * @see init()
     * @var array
     */
    protected array $modMenu = [
        'function' => [],
    ];

    /**
     * Module TSconfig based on PAGE TSconfig / USER TSconfig
     * Public since task objects use this.
     *
     * @see menuConfig()
     * @var array
     */
    public array $modTSconfig;

    /**
     * Generally used for accumulating the output content of backend modules
     *
     * @var string
     */
    protected string $content = '';

    /**
     * ModuleTemplate Container
     *
     * @var ModuleTemplate
     */
    protected ModuleTemplate $moduleTemplate;

    /**
     * The name of the module
     *
     * @var string
     */
    protected string $moduleName = 'user_task';

    protected ModuleTemplateFactory $moduleTemplateFactory;
    protected UriBuilder $uriBuilder;
    protected PageRenderer $pageRenderer;

    /**
     * Initializes the Module
     */
    public function __construct(
        ModuleTemplateFactory $moduleTemplateFactory,
        UriBuilder $uriBuilder,
        PageRenderer $pageRenderer,
        ResponseFactoryInterface $responseFactory)
    {
        $this->moduleTemplateFactory = $moduleTemplateFactory;
        $this->uriBuilder = $uriBuilder;
        $this->pageRenderer = $pageRenderer;
        $this->responseFactory = $responseFactory;
        $this->getLanguageService()->includeLLFile('EXT:taskcenter/Resources/Private/Language/locallang_task.xlf');
        $this->mConf = [
            'name' => $this->moduleName,
        ];
        // Name might be set from outside
        if (!$this->mConf['name']) {
            $this->mConf = $GLOBALS['MCONF'];
        }
    }

    public function getRequest(): ?ServerRequestInterface
    {
        return $this->request;
    }

    public function getResponseFactory(): ?ResponseFactoryInterface
    {
        return $this->responseFactory;
    }

    /**
     * Adds items to the ->modMenu array. Used for the function menu selector.
     */
    protected function menuConfig(): void
    {
        $this->modMenu = ['mode' => []];
        $languageService = $this->getLanguageService();
        $this->modMenu['mode']['information'] = $languageService->sL('LLL:EXT:taskcenter/Resources/Private/Language/locallang.xlf:task_overview');
        $this->modMenu['mode']['tasks'] = $languageService->sL('LLL:EXT:taskcenter/Resources/Private/Language/locallang.xlf:task_tasks');
        // Copied from parent::menuConfig, because parent is hardcoded to menu.function,
        // however menu.function is already used for the individual tasks. Therefore we use menu.mode here.
        // Page/be_user TSconfig settings and blinding of menu-items
        $this->modTSconfig['properties'] = BackendUtility::getPagesTSconfig($this->id)['mod.'][$this->moduleName . '.'] ?? [];
        $this->modMenu['mode'] = $this->mergeExternalItems($this->mConf['name'], 'mode', $this->modMenu['mode']);
        $blindActions = $this->modTSconfig['properties']['menu.']['mode.'] ?? [];
        foreach ($blindActions as $key => $value) {
            if (!$value && array_key_exists($key, $this->modMenu['mode'])) {
                unset($this->modMenu['mode'][$key]);
            }
        }
        // Page / user TSconfig settings and blinding of menu-items
        // Now overwrite the stuff again for unknown reasons
        $this->modTSconfig['properties'] = BackendUtility::getPagesTSconfig($this->id)['mod.'][$this->mConf['name'] . '.'] ?? [];
        $this->modMenu['function'] = $this->mergeExternalItems($this->mConf['name'], 'function', $this->modMenu['function'] ?? []);
        $blindActions = $this->modTSconfig['properties']['menu.']['function.'] ?? [];
        foreach ($blindActions as $key => $value) {
            if (!$value && array_key_exists($key, $this->modMenu['function'])) {
                unset($this->modMenu['function'][$key]);
            }
        }
    }

    /**
     * Generates the menu based on $this->modMenu
     *
     * @throws \InvalidArgumentException
     */
    protected function generateMenu(): void
    {
        $menu = $this->moduleTemplate->getDocHeaderComponent()->getMenuRegistry()->makeMenu();
        $menu->setIdentifier('WebFuncJumpMenu');
        foreach ($this->modMenu['mode'] as $controller => $title) {
            $item = $menu
                ->makeMenuItem()
                ->setHref(
                    (string)$this->uriBuilder->buildUriFromRoute(
                        $this->moduleName,
                        [
                            'id' => $this->id,
                            'SET' => [
                                'mode' => $controller,
                            ],
                        ]
                    )
                )
                ->setTitle($title);
            if ($controller === $this->moduleData->get('SET')['mode']) {
                $item->setActive(true);
            }
            $menu->addMenuItem($item);
        }
        $this->moduleTemplate->getDocHeaderComponent()->getMenuRegistry()->addMenu($menu);
    }

    /**
     * Injects the request object for the current request or subrequest
     * Simply calls main() and writes the content to the response
     *
     * @param ServerRequestInterface $request the current request
     * @return ResponseInterface the response with the content
     */
    public function mainAction(ServerRequestInterface $request): ResponseInterface
    {
        $this->request = $request;
        $this->id = (int)($request->getQueryParams()['id'] ?? $request->getParsedBody()['id'] ?? 0);
        $this->moduleData = $request->getAttribute('moduleData');
        $this->moduleTemplate = $this->moduleTemplateFactory->create($request);
        $this->menuConfig();
        $this->main();
        $this->moduleTemplate->setContent($this->content);
        //@TODO https://docs.typo3.org/c/typo3/cms-core/main/en-us/Changelog/12.0/Feature-96730-SimplifiedExtbackendModuleTemplateAPI.html
        return new HtmlResponse($this->moduleTemplate->renderContent());
    }

    /**
     * Creates the module's content. In this case it rather acts as a kind of #
     * dispatcher redirecting requests to specific tasks.
     */
    protected function main(): void
    {
        $this->getButtons();
        $this->generateMenu();

        // Render content depending on the mode
        $mode = (string)$this->moduleData->get('SET')['mode'];
        if ($mode === 'information') {
            $this->renderInformationContent();
        } else {
            $this->renderModuleContent();
        }
    }

    /**
     * Generates the module content by calling the selected task
     */
    protected function renderModuleContent(): void
    {
        $languageService = $this->getLanguageService();
        $chosenTask = (string)$this->moduleData->get('SET')['function'];
        // Render the taskcenter task as default
        if (empty($chosenTask) || $chosenTask === 'index') {
            $chosenTask = 'taskcenter.tasks';
        }
        // Render the task
        $actionContent = '';
        $flashMessage = null;
        [$extKey, $taskClass] = explode('.', $chosenTask, 2);
        if (class_exists($taskClass)) {
            $taskInstance = GeneralUtility::makeInstance($taskClass, $this, $this->pageRenderer);
            if ($taskInstance instanceof TaskInterface) {
                // Check if the task is restricted to admins only
                if ($this->checkAccess($extKey, $taskClass)) {
                    $actionContent .= $taskInstance->getTask();
                } else {
                    $flashMessage = GeneralUtility::makeInstance(
                        FlashMessage::class,
                        $languageService->sL('LLL:EXT:taskcenter/Resources/Private/Language/locallang_task.xlf:error-access'),
                        $languageService->sL('LLL:EXT:taskcenter/Resources/Private/Language/locallang_task.xlf:error_header'),
                        ContextualFeedbackSeverity::ERROR
                    );
                }
            } else {
                // Error if the task is not an instance of \TYPO3\CMS\Taskcenter\TaskInterface
                $flashMessage = GeneralUtility::makeInstance(
                    FlashMessage::class,
                    sprintf($languageService->sL('LLL:EXT:taskcenter/Resources/Private/Language/locallang_task.xlf:error_no-instance'), $taskClass, TaskInterface::class),
                    $languageService->sL('LLL:EXT:taskcenter/Resources/Private/Language/locallang_task.xlf:error_header'),
                    ContextualFeedbackSeverity::ERROR
                );
            }
        } else {
            $flashMessage = GeneralUtility::makeInstance(
                FlashMessage::class,
                $languageService->sL('LLL:EXT:taskcenter/Resources/Private/Language/locallang_mod.xlf:mlang_labels_tabdescr'),
                $languageService->sL('LLL:EXT:taskcenter/Resources/Private/Language/locallang_mod.xlf:mlang_tabs_tab'),
                ContextualFeedbackSeverity::INFO
            );
        }

        if ($flashMessage) {
            /** @var FlashMessageService $flashMessageService */
            $flashMessageService = GeneralUtility::makeInstance(FlashMessageService::class);
            /** @var FlashMessageQueue $defaultFlashMessageQueue */
            $defaultFlashMessageQueue = $flashMessageService->getMessageQueueByIdentifier();
            $defaultFlashMessageQueue->enqueue($flashMessage);
        }

        $assigns = [];
        $assigns['reports'] = $this->indexAction();
        $assigns['taskClass'] = strtolower(str_replace('\\', '-', htmlspecialchars($extKey . '-' . $taskClass)));
        $assigns['actionContent'] = $actionContent;

        // Rendering of the output via fluid
        $view = GeneralUtility::makeInstance(StandaloneView::class);
        $view->setTemplatePathAndFilename(GeneralUtility::getFileAbsFileName(
            'EXT:taskcenter/Resources/Private/Templates/ModuleContent.html'
        ));
        $view->assignMultiple($assigns);
        $this->content .= $view->render();
    }

    /**
     * Generates the information content
     */
    protected function renderInformationContent(): void
    {
        $assigns = [];
        $assigns['LLPrefix'] = 'LLL:EXT:taskcenter/Resources/Private/Language/locallang.xlf:';
        $assigns['LLPrefixMod'] = 'LLL:EXT:taskcenter/Resources/Private/Language/locallang_mod.xlf:';
        $assigns['LLPrefixTask'] = 'LLL:EXT:taskcenter/Resources/Private/Language/locallang_task.xlf:';
        $assigns['admin'] = $this->getBackendUser()->isAdmin();

        // Rendering of the output via fluid
        $view = GeneralUtility::makeInstance(StandaloneView::class);
        $view->setTemplateRootPaths([GeneralUtility::getFileAbsFileName('EXT:taskcenter/Resources/Private/Templates')]);
        $view->setPartialRootPaths([GeneralUtility::getFileAbsFileName('EXT:taskcenter/Resources/Private/Partials')]);
        $view->setTemplatePathAndFilename(GeneralUtility::getFileAbsFileName(
            'EXT:taskcenter/Resources/Private/Templates/InformationContent.html'
        ));
        $view->assignMultiple($assigns);
        $this->content = $view->render();
    }

    /**
     * Render the headline of a task including a title and an optional description.
     * Public since task objects use this.
     *
     * @param string $title Title
     * @param string $description Description
     * @return string formatted title and description
     */
    public function description(string $title, string $description = ''): string
    {
        $descriptionView = GeneralUtility::makeInstance(StandaloneView::class);
        $descriptionView->setTemplatePathAndFilename(GeneralUtility::getFileAbsFileName(
            'EXT:taskcenter/Resources/Private/Partials/Description.html'
        ));
        $descriptionView->assign('title', $title);
        $descriptionView->assign('description', $description);
        return $descriptionView->render();
    }

    /**
     * Render a list of items as a nicely formatted definition list including a link, icon, title and description.
     * The keys of a single item are:
     * - title:             Title of the item
     * - link:              Link to the task
     * - icon:              Path to the icon or Icon as HTML if it begins with <img
     * - description:       Description of the task, using htmlspecialchars()
     * - descriptionHtml:   Description allowing HTML tags which will override the description
     * Public since task objects use this.
     *
     * @param array $items List of items to be displayed in the definition list.
     * @param bool $mainMenu Set it to TRUE to render the main menu
     * @return string Formatted definition list
     */
    public function renderListMenu(
        array $items,
        bool $mainMenu = false): string
    {
        $assigns = [];
        $assigns['mainMenu'] = $mainMenu;

        // Change the sorting of items to the user's one
        if ($mainMenu) {
            $userSorting = unserialize($this->getBackendUser()->uc['taskcenter']['sorting'] ?? '');
            if (is_array($userSorting)) {
                $newSorting = [];
                foreach ($userSorting as $item) {
                    if (isset($items[$item])) {
                        $newSorting[] = $items[$item];
                        unset($items[$item]);
                    }
                }
                $items = $newSorting + $items;
            }
        }
        if (is_array($items) && !empty($items)) {
            foreach ($items as &$item) {
                // Check for custom icon
                if (!empty($item['icon']) && (strpos($item['icon'], '<img ') === false)) {
                    $iconFile = GeneralUtility::getFileAbsFileName($item['icon']);
                    if (@is_file($iconFile)) {
                        $item['iconFile'] = PathUtility::getAbsoluteWebPath($iconFile);
                    }
                }
                $id = $this->getUniqueKey($item['uid']);
                $contentId = strtolower(str_replace('\\', '-', $id));
                $item['uniqueKey'] = $id;
                $item['contentId'] = $contentId;
                // Collapsed & expanded menu items
                if (isset($this->getBackendUser()->uc['taskcenter']['states'][$id]) && $this->getBackendUser()->uc['taskcenter']['states'][$id]) {
                    $item['ariaExpanded'] = 'true';
                    $item['collapseIcon'] = 'actions-view-list-expand';
                    $item['collapsed'] = '';
                } else {
                    $item['ariaExpanded'] = 'false';
                    $item['collapseIcon'] = 'actions-view-list-collapse';
                    $item['collapsed'] = 'show';
                }
                // Active menu item
                $panelState = (string)$this->moduleData->get('SET')['function'] == $item['uid'] ? 'bg-info' : 'bg-default';
                $item['panelState'] = $panelState;
            }
        }
        $assigns['items'] = $items;

        // Rendering of the output via fluid
        $view = GeneralUtility::makeInstance(StandaloneView::class);
        $view->setTemplatePathAndFilename(GeneralUtility::getFileAbsFileName(
            'EXT:taskcenter/Resources/Private/Templates/ListMenu.html'
        ));
        $view->assignMultiple($assigns);
        return $view->render();
    }

    /**
     * Shows an overview list of available reports.
     *
     * @return string List of available reports
     */
    protected function indexAction(): string
    {
        $languageService = $this->getLanguageService();
        $content = '';
        $tasks = [];
        $defaultIcon = 'EXT:taskcenter/Resources/Public/Icons/module-taskcenter.svg';
        // Render the tasks only if there are any available
        if (count($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['taskcenter'] ?? [])) {
            foreach ($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['taskcenter'] as $extKey => $extensionReports) {
                foreach ($extensionReports as $taskClass => $task) {
                    if (!$this->checkAccess($extKey, $taskClass)) {
                        continue;
                    }
                    $link = (string)$this->uriBuilder->buildUriFromRoute('user_task') . '&SET[function]=' . $extKey . '.' . $taskClass;
                    $taskTitle = $languageService->sL($task['title']);
                    $taskDescriptionHtml = '';

                    if (class_exists($taskClass)) {
                        $taskInstance = GeneralUtility::makeInstance($taskClass, $this, $this->pageRenderer);
                        if ($taskInstance instanceof TaskInterface) {
                            $taskDescriptionHtml = $taskInstance->getOverview();
                        }
                    }
                    // Generate an array of all tasks
                    $uniqueKey = $this->getUniqueKey($extKey . '.' . $taskClass);
                    $tasks[$uniqueKey] = [
                        'title' => $taskTitle,
                        'descriptionHtml' => $taskDescriptionHtml,
                        'description' => $languageService->sL($task['description']),
                        'icon' => !empty($task['icon']) ? $task['icon'] : $defaultIcon,
                        'link' => $link,
                        'uid' => $extKey . '.' . $taskClass,
                    ];
                }
            }
            $content .= $this->renderListMenu($tasks, true);
        } else {
            $flashMessage = GeneralUtility::makeInstance(
                FlashMessage::class,
                $languageService->sL('LLL:EXT:taskcenter/Resources/Private/Language/locallang_task.xlf:no-tasks'),
                '',
                ContextualFeedbackSeverity::INFO
            );
            /** @var FlashMessageService $flashMessageService */
            $flashMessageService = GeneralUtility::makeInstance(FlashMessageService::class);
            /** @var FlashMessageQueue $defaultFlashMessageQueue */
            $defaultFlashMessageQueue = $flashMessageService->getMessageQueueByIdentifier();
            $defaultFlashMessageQueue->enqueue($flashMessage);
        }
        return $content;
    }

    /**
     * Create the panel of buttons for submitting the form or otherwise
     * perform operations.
     */
    protected function getButtons(): void
    {
        $buttonBar = $this->moduleTemplate->getDocHeaderComponent()->getButtonBar();

        // Shortcut
        $shortcutButton = $buttonBar->makeShortcutButton()
            ->setRouteIdentifier('user_task')
            ->setDisplayName($this->getLanguageService()->sL('LLL:EXT:taskcenter/Resources/Private/Language/locallang_mod.xlf:mlang_tabs_tab'));

        $buttonBar->addButton($shortcutButton);
    }

    /**
     * Check the access to a task. Considered are:
     * - Admins are always allowed
     * - Tasks can be restriced to admins only
     * - Tasks can be blinded for Users with TsConfig taskcenter.<extensionkey>.<taskName> = 0
     *
     * @param string $extKey Extension key
     * @param string $taskClass Name of the task
     * @return bool Access to the task allowed or not
     */
    protected function checkAccess(string $extKey, string $taskClass): bool
    {
        $backendUser = $this->getBackendUser();
        // Admins are always allowed
        if ($backendUser->isAdmin()) {
            return true;
        }
        // Check if task is restricted to admins
        if (isset($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['taskcenter'][$extKey][$taskClass]['admin']) && (int)$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['taskcenter'][$extKey][$taskClass]['admin'] === 1) {
            return false;
        }
        // Check if task is blinded with TsConfig (taskcenter.<extkey>.<taskName>
        return (bool)($backendUser->getTSConfig()['taskcenter.'][$extKey . '.'][$taskClass] ?? true);
    }

    /**
     * Create a unique key from a string which can be used in JS for sorting
     * Therefore '_' are replaced
     *
     * @param string $string string which is used to generate the identifier
     * @return string Modified string
     */
    protected function getUniqueKey(string $string): string
    {
        $search = ['.', '_'];
        $replace = ['-', ''];
        return str_replace($search, $replace, $string);
    }

    /**
     * Returns the current BE user.
     *
     * @return BackendUserAuthentication
     */
    protected function getBackendUser(): BackendUserAuthentication
    {
        return $GLOBALS['BE_USER'];
    }

    /**
     * Returns LanguageService
     *
     * @return LanguageService
     */
    protected function getLanguageService(): LanguageService
    {
        return $GLOBALS['LANG'];
    }

    /**
     * Public since task objects use this.
     *
     * @return ModuleTemplate
     */
    public function getModuleTemplate(): ModuleTemplate
    {
        return $this->moduleTemplate;
    }

    /**
     * Merges menu items from global array $TBE_MODULES_EXT
     *
     * @param string $modName Module name for which to find value
     * @param string $menuKey Menu key, eg. 'function' for the function menu.
     * @param array $menuArr The part of a modMenu array to work on.
     * @return array Modified array part.
     * @internal
     * @see \TYPO3\CMS\Core\Utility\ExtensionManagementUtility::insertModuleFunction(), menuConfig()
     */
    protected function mergeExternalItems(string $modName, string $menuKey, array $menuArr): array
    {
        $mergeArray = $GLOBALS['TBE_MODULES_EXT'][$modName]['modMenu'][$menuKey] ?? false;
        if (is_array($mergeArray)) {
            foreach ($mergeArray as $k => $v) {
                if (((string)$v['ws'] === '' || $this->getBackendUser()->workspace === 0 && GeneralUtility::inList($v['ws'], 'online')) || $this->getBackendUser()->workspace === -1 && GeneralUtility::inList($v['ws'], 'offline') || $this->getBackendUser()->workspace > 0 && GeneralUtility::inList($v['ws'], 'custom')) {
                    $menuArr[$k] = $this->getLanguageService()->sL($v['title']);
                }
            }
        }
        return $menuArr;
    }
}
