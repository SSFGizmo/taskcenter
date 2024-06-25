<?php

declare(strict_types=1);

use  TYPO3\CMS\Taskcenter\Controller\TaskModuleController;

return [
    'user_task' => [
        'parent' => 'user',
        'access' => 'user',
        'position' => 'top',
        'path' => '/module/user/task',
        'iconIdentifier' => 'module-taskcenter',
        'labels' => 'LLL:EXT:taskcenter/Resources/Private/Language/locallang_mod.xlf',
        'routes' => [
            '_default' => [
                'target' => TaskModuleController::class . '::mainAction',
            ],
        ],
        'moduleData' => [
            'SET' => [
                'function' => '',
                'mode' => '',
            ],
        ],
    ],
];
