<?php declare(strict_types=1);

namespace Redirector\Mvc;

use Common\Stdlib\PsrMessage;
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
        if (!$routeMatch) {
            return;
        }

        $matchedRouteName = $routeMatch->getMatchedRouteName();
        if ($matchedRouteName !== 'site' && strpos($matchedRouteName, 'site/') !== 0) {
            return;
        }

        $services = $event->getApplication()->getServiceManager();
        $siteSettings = $services->get('Omeka\Settings\Site');

        $configs = $siteSettings->get('redirector_redirections_merged', []);
        if (!$configs) {
            // Lazy fallback: regenerate the merged setting from the source
            // ones. Useful right after install when the admin has not yet
            // saved the form.
            $simple = $siteSettings->get('redirector_redirections', []);
            $advancedRaw = (string) $siteSettings->get('redirector_redirections_advanced');
            if (!$simple && !$advancedRaw) {
                return;
            }
            $module = $services->get('Omeka\ModuleManager')->getModule('Redirector');
            if ($module && method_exists($module, 'finalizeSiteSettings')) {
                $module->finalizeSiteSettings($siteSettings);
                $configs = $siteSettings->get('redirector_redirections_merged', []);
            }
            if (!$configs) {
                return;
            }
        }

        $params = $routeMatch->getParams();

        $resourceId = null;
        if ($matchedRouteName === 'site/resource-id') {
            $id = $routeMatch->getParam('id');
            $resourceId = is_numeric($id) && (int) $id > 0 ? (int) $id : null;
        } elseif ($matchedRouteName === 'site/item-set') {
            $id = $routeMatch->getParam('item-set-id');
            $resourceId = is_numeric($id) && (int) $id > 0 ? (int) $id : null;
        }

        // Match by resource id or by route name or by constructed path key.
        $keyCandidates = [];
        if ($resourceId) {
            $keyCandidates[] = (string) $resourceId;
        }
        $keyCandidates[] = $matchedRouteName;

        // Optional path-like key (site specific).
        if (isset($params['site-slug'])) {
            $uriPath = $event->getRequest()->getUri()->getPath();
            // Use raw path as a key if present in config.
            $keyCandidates[] = $uriPath;
            // Also without leading slash.
            $keyCandidates[] = ltrim($uriPath, '/');
        }

        $config = [];
        foreach ($keyCandidates as $candidate) {
            if (isset($configs[$candidate])) {
                $config = $configs[$candidate];
                break;
            }
        }
        if (empty($config['target'])) {
            return;
        }

        // Default to internal forwarding unless explicit status.
        $status = isset($config['status']) && in_array((int) $config['status'], [301,302,303,307,308], true)
            ? (int) $config['status']
            : null;

        $internal = !$status;

        // Rights check (only when resource id available).
        if ($resourceId && $siteSettings->get('redirector_check_rights')) {
            $api = $services->get('Omeka\ApiManager');
            try {
                // To use the api is the simplest way to check visibility.
                $api->read('resources', ['id' => $resourceId], [], ['responseContent' => 'resource', 'initialize' => false, 'finalize' => false]);
            } catch (\Throwable $e) {
                // Resource not accessible (not found or no permission).
                $logger = $services->get('Omeka\Logger');
                $logger->warn(new PsrMessage(
                    '[Redirector] Rights check failed for resource {resource_id}: {message}', // @translate
                    ['resource_id' => $resourceId, 'message' => $e->getMessage()]
                ));
                return;
            }
        }

        $targetTemplate = (string) $config['target'];
        $redirection = $this->replacePlaceholders($targetTemplate, $params);

        $isInternalAbsolutePath = strpos($redirection, '/') === 0;
        $isExternalUrl = strpos($redirection, 'https://') === 0 || strpos($redirection, 'http://') === 0;

        if ($isInternalAbsolutePath && $internal) {
            // Internal forward: rewrite URI + re-match router.
            $request = $event->getRequest();
            $uri = $request->getUri();

            // Split path and query. parse_url returns null when the component
            // is absent and false on malformed input; coalesce explicitly so
            // an empty string (a valid result) is preserved when relevant.
            $pathPart = parse_url($redirection, PHP_URL_PATH);
            $pathPart = ($pathPart === null || $pathPart === false || $pathPart === '') ? '/' : $pathPart;
            $queryPart = parse_url($redirection, PHP_URL_QUERY);
            $queryPart = ($queryPart === null || $queryPart === false) ? '' : (string) $queryPart;

            $uri->setPath($pathPart);
            $uri->setQuery($queryPart);
            $request->setUri($uri);

            // Merge configured query params with original query params.
            // Original query params (sort_by, sort_order, page, etc.) take precedence.
            $originalQuery = $request->getQuery()->toArray();
            $queryParams = $this->prepareParamsArray($config['query'] ?? [], $params);
            if ($queryPart) {
                $fromTargetQuery = [];
                parse_str($queryPart, $fromTargetQuery);
                $queryParams = array_replace($fromTargetQuery, $queryParams);
            }
            // Merge: redirect params first, then original params override.
            $queryParams = array_replace($queryParams, $originalQuery);
            if ($queryParams) {
                $request->getQuery()->fromArray($queryParams);
            }

            // Re-match router for new path.
            $router = $event->getRouter();
            $newMatch = $router->match($request);
            if ($newMatch instanceof RouteMatch) {
                // Apply dynamic params override if provided.
                $dynamicParams = $this->prepareParamsArray($config['params'] ?? [], $params);
                foreach ($dynamicParams as $k => $v) {
                    $newMatch->setParam($k, $v);
                }
                $event->setRouteMatch($newMatch);
            }
            return;
        }

        if ($isInternalAbsolutePath || $isExternalUrl) {
            $queryParams = $this->prepareParamsArray($config['query'] ?? [], $params);
            $queryString = $queryParams ? http_build_query($queryParams, '', '&', PHP_QUERY_RFC3986) : '';
            $finalUrl = $redirection . ($queryString ? '?' . $queryString : '');
            $this->redirectToUrlViaHeaders($finalUrl, $status, $services);
            return;
        }

        // Internal page slug flow.
        $routeName = $config['route'] ?? null;
        $siteSlug = $params['site-slug'] ?? null;
        if (!$routeName) {
            // Cannot redirect to page without site context.
            if (!$siteSlug) {
                return;
            }
            $routeName = 'site/page';
            $pageSlug = $redirection;
            // Reject anything that does not match a plausible page slug to
            // avoid forging RouteMatch params with arbitrary characters.
            if (!preg_match('/^[A-Za-z0-9._-]+$/', $pageSlug)) {
                return;
            }
            $api = $services->get('Omeka\ApiManager');
            try {
                // To use the api read is the simplest way to check visibility.
                $site = $api->read('sites', ['slug' => $siteSlug], [], ['responseContent' => 'resource', 'initialize' => false, 'finalize' => false])->getContent();
                $api->read('site_pages', ['site' => $site->getId(), 'slug' => $pageSlug], [], ['responseContent' => 'resource', 'initialize' => false, 'finalize' => false]);
            } catch (\Throwable $e) {
                // Site or page not accessible (not found or no permission).
                $logger = $services->get('Omeka\Logger');
                $logger->warn(new PsrMessage(
                    '[Redirector] Page redirect failed for site "{site_slug}", page "{page_slug}": {message}', // @translate
                    ['site_slug' => $siteSlug, 'page_slug' => $pageSlug, 'message' => $e->getMessage()]
                ));
                return;
            }
            $baseParams = [
                '__NAMESPACE__' => 'Omeka\Controller\Site',
                '__CONTROLLER__' => 'Page',
                '__SITE__' => true,
                'controller' => 'Omeka\Controller\Site\Page',
                'action' => 'show',
                'site-slug' => $siteSlug,
                'page-slug' => $pageSlug,
            ];
        } else {
            $baseParams = $params;
        }

        $dynamicParams = $this->prepareParamsArray($config['params'] ?? [], $params);
        // Reject override of routing internals: an admin-supplied params map
        // must not be able to point the dispatcher to an arbitrary controller
        // or namespace. Allow only when no explicit page route was forged.
        if (!isset($config['route'])) {
            $reserved = ['__NAMESPACE__', '__CONTROLLER__', '__SITE__', 'controller'];
            foreach ($reserved as $key) {
                unset($dynamicParams[$key]);
            }
        }
        // Remove useless null and empty string to avoid overriding route part.
        $finalParams = array_filter(array_replace($baseParams, $dynamicParams), static fn($v) => $v !== null && $v !== '');

        $queryParams = $this->prepareParamsArray($config['query'] ?? [], $params);
        if ($queryParams) {
            $event->getRequest()->getQuery()->fromArray($queryParams);
        }

        $newMatch = new RouteMatch($finalParams);
        $newMatch->setMatchedRouteName($routeName);
        $event->setRouteMatch($newMatch);
    }

    protected function prepareParamsArray(array $map, array $original): array
    {
        $result = [];
        foreach ($map as $k => $v) {
            $resolved = $this->replacePlaceholders((string) $v, $original);
            if ($resolved !== '') {
                $result[$k] = $resolved;
            }
        }
        return $result;
    }

    protected function replacePlaceholders(string $template, array $values): string
    {
        return preg_replace_callback(
            '/\{([^}]+)\}/u',
            fn($m) => (string) ($values[$m[1]] ?? ''),
            $template
        ) ?? '';
    }

    protected function redirectToUrlViaHeaders(string $url, int $status = 302, $services = null): void
    {
        // Prepend domain if url is a site-relative path.
        if (strpos($url, '/') === 0) {
            $serverUrlHelper = $services
                ? $services->get('ViewHelperManager')->get('serverUrl')
                : new \Laminas\View\Helper\ServerUrl();
            $base = rtrim($serverUrlHelper(), '/');
            $url = $base . $url;
        }

        // Security:
        // Validate url to prevent header injection and XSS.
        // Reject urls with newline characters (header injection).
        if (preg_match('/[\r\n]/', $url)) {
            return;
        }

        // Validate url scheme - only allow http/https.
        $parsedUrl = parse_url($url);
        if (!$parsedUrl || !isset($parsedUrl['scheme'])) {
            return;
        }

        $scheme = strtolower($parsedUrl['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            return;
        }

        $status = in_array($status, [301, 302, 303, 307, 308], true)
            ? $status
            : 302;

        if ($services) {
            try {
                $services->get('Omeka\Logger')->info(new PsrMessage(
                    '[Redirector] External redirect to {url} with status {status}.', // @translate
                    ['url' => $url, 'status' => $status]
                ));
            } catch (\Throwable $e) {
                // Logger optional - never block redirect on log failure.
            }
        }

        /** @see \Laminas\Mvc\Controller\Plugin\Redirect::toUrl() */
        /* // TODO Use event response in order to get statistics.
        $event->setResponse(new \Laminas\Http\Response);
        $event->getResponse()
            ->setStatusCode($status)
            ->getHeaders()->addHeaderLine('Location', $url);
        return;
         */
        if (headers_sent()) {
            // Use url-safe escaping for html context.
            $urlEscaped = htmlspecialchars($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            echo '<script>window.location.href="' . $urlEscaped . '";</script>';
            echo '<noscript><meta http-equiv="refresh" content="0;url=' . $urlEscaped . '"></noscript>';
        } else {
            header('Location: ' . $url, true, $status);
        }
        die();
    }
}
