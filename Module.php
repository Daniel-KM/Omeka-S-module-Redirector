<?php declare(strict_types=1);

namespace Redirector;

if (!class_exists('Common\TraitModule', false)) {
    require_once file_exists(dirname(__DIR__) . '/Common/src/TraitModule.php')
        ? dirname(__DIR__) . '/Common/src/TraitModule.php'
        : dirname(__DIR__) . '/Common/TraitModule.php';
}

use Common\TraitModule;
use Laminas\EventManager\SharedEventManagerInterface;
use Omeka\Module\AbstractModule;

/**
 * Redirector.
 *
 * @copyright Daniel Berthereau, 2024-2026
 * @license http://www.cecill.info/licences/Licence_CeCILL_V2.1-en.txt
 */
class Module extends AbstractModule
{
    use TraitModule;

    const NAMESPACE = __NAMESPACE__;

    protected function preInstall(): void
    {
        $services = $this->getServiceLocator();
        $plugins = $services->get('ControllerPluginManager');
        $translator = $services->get('MvcTranslator');

        if (!method_exists($this, 'checkModuleActiveVersion') || !$this->checkModuleActiveVersion('Common', '3.4.85')) {
            $message = new \Omeka\Stdlib\Message(
                $translator->translate('The module %1$s should be upgraded to version %2$s or later.'), // @translate
                'Common', '3.4.85'
            );
            throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $message);
        }
    }

    public function attachListeners(SharedEventManagerInterface $sharedEventManager): void
    {
        $sharedEventManager->attach(
            \Omeka\Form\SettingForm::class,
            'form.add_elements',
            [$this, 'handleMainSettings']
        );
        $sharedEventManager->attach(
            \Omeka\Form\SiteSettingsForm::class,
            'form.add_elements',
            [$this, 'handleSiteSettings']
        );
    }
}
