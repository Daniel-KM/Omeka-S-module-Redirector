<?php declare(strict_types=1);

namespace Redirector;

if (!class_exists('Common\TraitModule', false)) {
    require_once dirname(__DIR__) . '/Common/TraitModule.php';
}

use Common\TraitModule;
use Laminas\EventManager\Event;
use Laminas\EventManager\SharedEventManagerInterface;
use Omeka\Module\AbstractModule;
use Omeka\Settings\SiteSettings;

/**
 * Redirector.
 *
 * @copyright Daniel Berthereau, 2024-2025
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
        $translate = $plugins->get('translate');

        if (!method_exists($this, 'checkModuleActiveVersion') || !$this->checkModuleActiveVersion('Common', '3.4.76')) {
            $message = new \Omeka\Stdlib\Message(
                $translate('The module %1$s should be upgraded to version %2$s or later.'), // @translate
                'Common', '3.4.76'
            );
            throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $message);
        }
    }

    public function attachListeners(SharedEventManagerInterface $sharedEventManager): void
    {
        $sharedEventManager->attach(
            \Omeka\Form\SiteSettingsForm::class,
            'form.add_elements',
            [$this, 'handleSiteSettings']
        );
    }

    public function handleSiteSettings(Event $event): void
    {
        $this->handleAnySettings($event, 'site_settings');
        $this->finalizeSiteSettings();
    }

    /**
     * Check and merge redirections in one setting.
     */
    protected function finalizeSiteSettings(?SiteSettings $siteSettings = null): void
    {
        /**
         * @var \Omeka\Settings\SiteSettings $siteSettings
         */
        $siteSettings ??= $this->getServiceLocator()->get('Omeka\Settings\Site');

        $simple = $siteSettings->get('redirector_redirections', []);
        if (!is_array($simple)) {
            $simple = [];
        }

        $advancedRaw = (string) $siteSettings->get('redirector_redirections_advanced');
        $advanced = $advancedRaw
            ? json_decode($advancedRaw, true)
            : [];
        if ($advancedRaw && !$advanced) {
            $plugins = $this->getServiceLocator()->get('ControllerPluginManager');
            $messenger = $plugins->get('messenger');
            $messenger->addWarning(new \Common\Stdlib\PsrMessage(
                'The advanced redirections are not a valid json. Check quotes, commas, escapes and backslash.') // @translate
            );
        }
        if (!is_array($advanced)) {
            $advanced = [];
        }

        $normalized = [];
        foreach ($simple as $k => $v) {
            $normalized[(string) $k] = ['target' => (string) $v];
        }

        // Advanced overrides simple.
        $configs = array_replace($normalized, $advanced);

        $siteSettings->set('redirector_redirections_merged', $configs);
    }
}
