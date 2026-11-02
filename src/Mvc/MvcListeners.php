<?php declare(strict_types=1);

namespace Redirector\Mvc;

use Common\Stdlib\PsrMessage;
use Laminas\EventManager\AbstractListenerAggregate;
use Laminas\EventManager\EventManagerInterface;
use Laminas\Http\Response;
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
     *
     * A returned response stops the route event, so the redirection is sent
     * without dispatch.
     */
    public function redirectResource(MvcEvent $event): ?Response
    {
        $routeMatch = $event->getRouteMatch();
        if (!$routeMatch) {
            return null;
        }

        // Never redirect the admin, the api, the login, the install or the
        // migration, so a wrong redirection cannot lock out the admin.
        $matchedRouteName = (string) $routeMatch->getMatchedRouteName();
        if (preg_match('~^(?:admin|api|login|logout|create-password|forgot-password|install|migrate)(?:$|[/-])~', $matchedRouteName)) {
            return null;
        }

        /**
         * @var \Omeka\Settings\Settings $settings
         * @var \Omeka\Settings\SiteSettings $siteSettings
         */
        $services = $event->getApplication()->getServiceManager();
        $settings = $services->get('Omeka\Settings');
        $isSite = $matchedRouteName === 'site' || strpos($matchedRouteName, 'site/') === 0;
        $siteSettings = $isSite ? $services->get('Omeka\Settings\Site') : null;

        // The redirections of the site override the main ones.
        $configs = $siteSettings
            ? array_replace($this->redirections($settings), $this->redirections($siteSettings))
            : $this->redirections($settings);
        if (!$configs) {
            return null;
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

        // The keys are paths relative to the root of Omeka, that may be
        // installed in a sub-directory.
        $uriPath = $event->getRequest()->getUri()->getPath();
        $baseUrl = $this->baseUrl($event);
        if ($baseUrl !== '' && strpos($uriPath, $baseUrl . '/') === 0) {
            $uriPath = substr($uriPath, strlen($baseUrl));
        }
        // Use raw path as a key if present in config.
        $keyCandidates[] = $uriPath;
        // Also without leading slash.
        $keyCandidates[] = ltrim($uriPath, '/');

        $config = [];
        foreach ($keyCandidates as $candidate) {
            if (isset($configs[$candidate])) {
                $config = $configs[$candidate];
                break;
            }
        }
        if (empty($config['target'])) {
            return null;
        }

        // Default to internal forwarding unless explicit status.
        $status = isset($config['status']) && in_array((int) $config['status'], [301,302,303,307,308], true)
            ? (int) $config['status']
            : null;

        $internal = !$status;

        // Rights check (only when resource id available).
        $checkRights = $settings->get('redirector_check_rights')
            || ($siteSettings && $siteSettings->get('redirector_check_rights'));
        if ($resourceId && $checkRights) {
            /**@var \Omeka\Api\Manager $api */
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
                return null;
            }
        }

        $targetTemplate = (string) $config['target'];
        $redirection = $this->replacePlaceholders($targetTemplate, $params, true);

        // A target that is a number is the id of another resource of the site.
        if (ctype_digit($redirection)) {
            $redirection = $this->resourcePath($services, (int) $redirection, $params['site-slug'] ?? null);
            if ($redirection === null) {
                return null;
            }
        }

        $isInternalAbsolutePath = strpos($redirection, '/') === 0;
        $isExternalUrl = strpos($redirection, 'https://') === 0 || strpos($redirection, 'http://') === 0;

        if ($isInternalAbsolutePath && $internal) {
            // Internal forward: rewrite URI + re-match router.
            $request = $event->getRequest();

            // Split path and query. parse_url returns null when the component
            // is absent and false on malformed input; coalesce explicitly so
            // an empty string (a valid result) is preserved when relevant.
            $pathPart = parse_url($redirection, PHP_URL_PATH);
            $pathPart = ($pathPart === null || $pathPart === false || $pathPart === '') ? '/' : $pathPart;
            $queryPart = parse_url($redirection, PHP_URL_QUERY);
            $queryPart = ($queryPart === null || $queryPart === false) ? '' : (string) $queryPart;

            // Match the new path on a copy, so the request is unchanged when
            // the target does not match any route.
            $uri = clone $request->getUri();
            $uri->setPath($this->baseUrl($event) . $pathPart);
            $uri->setQuery($queryPart);
            $newRequest = clone $request;
            $newRequest->setUri($uri);
            $newMatch = $event->getRouter()->match($newRequest);
            if (!$newMatch instanceof RouteMatch) {
                return null;
            }
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

            // Apply dynamic params override if provided.
            $dynamicParams = $this->prepareParamsArray($config['params'] ?? [], $params);
            foreach ($dynamicParams as $k => $v) {
                $newMatch->setParam($k, $v);
            }
            $event->setRouteMatch($newMatch);
            return null;
        }

        if ($isInternalAbsolutePath || $isExternalUrl) {
            $queryParams = $this->prepareParamsArray($config['query'] ?? [], $params);
            $queryString = $queryParams ? http_build_query($queryParams, '', '&', PHP_QUERY_RFC3986) : '';
            $finalUrl = $redirection . ($queryString ? '?' . $queryString : '');
            // An external url cannot be forwarded internally.
            return $this->redirectToUrl($event, $finalUrl, $status ?? 302, $services);
        }

        // Internal page slug flow.
        $routeName = $config['route'] ?? null;
        $siteSlug = $params['site-slug'] ?? null;
        if (!$routeName) {
            // Cannot redirect to page without site context.
            if (!$siteSlug) {
                return null;
            }
            $routeName = 'site/page';
            $pageSlug = $redirection;
            // Reject anything that does not match a plausible page slug to
            // avoid forging RouteMatch params with arbitrary characters.
            if (!preg_match('/^[A-Za-z0-9._-]+$/', $pageSlug)) {
                return null;
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
                return null;
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
        return null;
    }

    /**
     * Get the path of a resource in a site, if it is visible by the user.
     */
    protected function resourcePath($services, int $resourceId, ?string $siteSlug): ?string
    {
        if (!$resourceId || !$siteSlug) {
            return null;
        }
        try {
            /** @var \Omeka\Api\Representation\AbstractResourceEntityRepresentation $resource */
            $resource = $services->get('Omeka\ApiManager')->read('resources', $resourceId)->getContent();
        } catch (\Throwable $e) {
            $services->get('Omeka\Logger')->warn(new PsrMessage(
                '[Redirector] Redirect to resource {resource_id} failed: {message}', // @translate
                ['resource_id' => $resourceId, 'message' => $e->getMessage()]
            ));
            return null;
        }
        return '/s/' . $siteSlug . '/' . $resource->getControllerName() . '/' . $resourceId;
    }

    /**
     * Merge the simple and advanced redirections of the main or site settings.
     *
     * The settings are read on each request, so they are always up to date,
     * even when they are updated outside of the form. The advanced ones are
     * checked again, because they may be set without the form too.
     */
    protected function redirections(\Omeka\Settings\SettingsInterface $settings): array
    {
        $simple = $settings->get('redirector_redirections', []);
        $advanced = json_decode((string) $settings->get('redirector_redirections_advanced'), true);

        $redirections = [];
        foreach (is_array($simple) ? $simple : [] as $key => $target) {
            $redirections[(string) $key] = ['target' => (string) $target];
        }

        // Advanced overrides simple.
        foreach (is_array($advanced) ? $advanced : [] as $key => $config) {
            if (is_array($config) && !empty($config['target']) && is_string($config['target'])) {
                $redirections[(string) $key] = $config;
            }
        }

        return $redirections;
    }

    /**
     * Get the base url of Omeka, empty when installed at the root.
     */
    protected function baseUrl(MvcEvent $event): string
    {
        $router = $event->getRouter();
        return method_exists($router, 'getBaseUrl')
            ? rtrim((string) $router->getBaseUrl(), '/')
            : '';
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

    /**
     * Replace the placeholders like "{id}" by the values of the route.
     *
     * The values are encoded when the template is an url, so they cannot change
     * its structure. The values that are not scalar are skipped.
     */
    protected function replacePlaceholders(string $template, array $values, bool $isUrl = false): string
    {
        return preg_replace_callback(
            '/\{([^}]+)\}/u',
            function ($m) use ($values, $isUrl): string {
                $value = $values[$m[1]] ?? '';
                $value = is_scalar($value) ? (string) $value : '';
                return $isUrl ? rawurlencode($value) : $value;
            },
            $template
        ) ?? '';
    }

    protected function redirectToUrl(MvcEvent $event, string $url, int $status, $services): ?Response
    {
        // Prepend domain and base url if url is a site-relative path.
        if (strpos($url, '/') === 0) {
            $serverUrlHelper = $services->get('ViewHelperManager')->get('serverUrl');
            $url = rtrim($serverUrlHelper(), '/') . $this->baseUrl($event) . $url;
        }

        // Reject urls with newline characters (header injection).
        if (preg_match('/[\r\n]/', $url)) {
            return null;
        }

        // Allow only http and https.
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        $status = in_array($status, [301, 302, 303, 307, 308], true)
            ? $status
            : 302;

        $services->get('Omeka\Logger')->info(new PsrMessage(
            '[Redirector] External redirect to {url} with status {status}.', // @translate
            ['url' => $url, 'status' => $status]
        ));

        /** @see \Laminas\Mvc\Controller\Plugin\Redirect::toUrl() */
        $response = $event->getResponse();
        $response->getHeaders()->addHeaderLine('Location', $url);
        $response->setStatusCode($status);
        return $response;
    }
}
