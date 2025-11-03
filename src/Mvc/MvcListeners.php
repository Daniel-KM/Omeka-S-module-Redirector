<?php declare(strict_types=1);

namespace Redirector\Mvc;

use Laminas\EventManager\AbstractListenerAggregate;
use Laminas\EventManager\EventManagerInterface;
use Laminas\Mvc\MvcEvent;
use Laminas\Router\Http\RouteMatch;

class MvcListeners extends AbstractListenerAggregate
{
    public function attach(EventManagerInterface $events, $priority = 1): void
    {
        $this->listeners[] = $events->attach(
            MvcEvent::EVENT_ROUTE,
            [$this, 'redirectResource'],
            // Before module Advanced Search.
            -5
        );
    }

    /**
     * Redirect any resource page to any site page or url.
     */
    public function redirectResource(MvcEvent $event): void
    {
        /**
         * @var \Omeka\Api\Manager $api
         * @var \Omeka\Settings\SiteSettings $siteSettings
         */

        $routeMatch = $event->getRouteMatch();
        $matchedRouteName = $routeMatch->getMatchedRouteName();

        if ($matchedRouteName === 'site/resource-id') {
            $resourceId = (int) $routeMatch->getParam('id');
        } elseif ($matchedRouteName === 'site/item-set') {
            $resourceId = (int) $routeMatch->getParam('item-set-id');
        } else {
            return;
        }

        if (!$resourceId) {
            return;
        }

        $services = $event->getApplication()->getServiceManager();
        $siteSettings = $services->get('Omeka\Settings\Site');
        $redirections = $siteSettings->get('redirector_redirections', []);
        if (!count($redirections) || !isset($redirections[$resourceId])) {
            return;
        }

        $redirection = &$redirections[$resourceId];

        $checkRights = (bool) $siteSettings->get('redirector_check_rights');
        if ($checkRights) {
            $api = $services->get('Omeka\ApiManager');
            try {
                // To use the api is the simplest way to check visibility.
                $api->read('resources', ['id' => $resourceId], [], ['responseContent' => 'resource', 'initialize' => false, 'finalize' => false]);
            } catch (\Exception $e) {
                return;
            }
        }

        // External or absolute path redirect.
        if (mb_substr($redirection, 0, 1) === '/'
            || mb_substr($redirection, 0, 8) === 'https://'
            || mb_substr($redirection, 0, 7) === 'http://'
        ) {
            $this->redirectToUrlViaHeaders($redirection);
            return;
        }

        // This is a page slug. Check for its presence and visibility.
        $api = $services->get('Omeka\ApiManager');
        $siteSlug = $routeMatch->getParam('site-slug');
        try {
            $site = $api->read('sites', ['slug' => $siteSlug], [], ['responseContent' => 'resource', 'initialize' => false, 'finalize' => false])->getContent();
            $api->read('site_pages', ['site' => $site->getId(), 'slug' => $redirection], [], ['responseContent' => 'resource', 'initialize' => false, 'finalize' => false]);
        } catch (\Exception $e) {
            return;
        }
        $params = [
            '__NAMESPACE__' => 'Omeka\Controller\Site',
            '__CONTROLLER__' => 'Page',
            '__SITE__' => true,
            'controller' => 'Omeka\Controller\Site\Page',
            'action' => 'show',
            'site-slug' => $siteSlug,
            'page-slug' => $redirection,
        ];
        $routeMatch = new RouteMatch($params);
        $routeMatch->setMatchedRouteName('site/page');
        $event->setRouteMatch($routeMatch);
    }

    protected function redirectToUrlViaHeaders(string $url, int $status = 302): void
    {
        // Prepend domain if url is a site-relative path.
        if (mb_substr($url, 0, 1) === '/') {
            $serverUrlHelper = new \Laminas\View\Helper\ServerUrl();
            $base = rtrim($serverUrlHelper(), '/');
            $url = $base . $url;
        }

        $status = in_array($status, [301, 302, 303, 307, 308], true)
            ? $status
            : 302;

        /** @see \Laminas\Mvc\Controller\Plugin\Redirect::toUrl() */
        /* // TODO Use event response in order to get statistics.
        $event->setResponse(new \Laminas\Http\Response);
        $event->getResponse()
            ->setStatusCode($status)
            ->getHeaders()->addHeaderLine('Location', $url);
        return;
         */
        if (headers_sent()) {
            $urlEscaped = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
            echo '<script>window.location.href="' . $urlEscaped . '";</script>';
            echo '<noscript><meta http-equiv="refresh" content="0;url=' . $urlEscaped . '"></noscript>';
        } else {
            $serverUrl = new \Laminas\View\Helper\ServerUrl();
            header('Referer: ' . $serverUrl(true));
            header('Location: ' . $url, true, $status);
        }
        die();
    }
}
