<?php declare(strict_types=1);

namespace Redirector;

return [
    'service_manager' => [
        'invokables' => [
            Mvc\MvcListeners::class => Mvc\MvcListeners::class,
        ],
    ],
    'listeners' => [
        Mvc\MvcListeners::class,
    ],
    'form_elements' => [
        'invokables' => [
            Form\SiteSettingsFieldset::class => Form\SiteSettingsFieldset::class,
        ],
    ],
    'redirector' => [
        'site_settings' => [
            'redirector_redirections' => [],
            'redirector_check_rights' => false,
        ],
    ],
];
