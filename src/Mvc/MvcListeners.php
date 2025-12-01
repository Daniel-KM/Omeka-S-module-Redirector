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
        if (substr($matchedRouteName, 0, 4) !== 'site') {
            return;
        }

        $services = $event->getApplication()->getServiceManager();
        $siteSettings = $services->get('Omeka\Settings\Site');

        $configs = $siteSettings->get('redirector_redirections_merged', []);
        if (!$configs) {
            return;
        }

        $params = $routeMatch->getParams();

        if ($matchedRouteName === 'site/resource-id') {
            $resourceId = (int) $routeMatch->getParam('id');
        } elseif ($matchedRouteName === 'site/item-set') {
            $resourceId = (int) $routeMatch->getParam('item-set-id');
        } else {
            $resourceId = null;
        }

        // Match by resource id or by route name or by constructed path key.
        $keyCandidates = [];
        if ($resourceId) {
            $keyCandidates[] = (string) $resourceId;
        }
        $keyCandidates[] = $matchedRouteName;

        // Optional path-like key (site specific).
        if (isset($params['site-slug'])) {
            $siteSlug = $params['site-slug'];
            $request = $event->getRequest();
            $uriPath = $request->getUri()->getPath();
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
            } catch (\Exception $e) {
                // Resource not accessible (not found or no permission).
                $logger = $services->get('Omeka\Logger');
                $logger->warn(new PsrMessage(
                    '[Redirector] Rights check failed for resource {resource_id}: {message}', // @translate
                    ['resource_id' => $resourceId, 'message' => $e->getMessage()]
                ));
                return;
            }
        }

        $originalParams = $params;
        $targetTemplate = (string) $config['target'];
        $redirection = $this->replacePlaceholders($targetTemplate, $originalParams);

        // Absolute or external URL handling.
        $isAbsoluteOrExternal = mb_substr($redirection, 0, 1) === '/'
            || mb_substr($redirection, 0, 8) === 'https://'
            || mb_substr($redirection, 0, 7) === 'http://';

        if ($isAbsoluteOrExternal && $internal && mb_substr($redirection, 0, 1) === '/') {
            // Internal forward: rewrite URI + re-match router.
            $request = $event->getRequest();
            $uri = $request->getUri();

            // Split path and query.
            $pathPart = parse_url($redirection, PHP_URL_PATH) ?: '/';
            $queryPart = parse_url($redirection, PHP_URL_QUERY) ?: '';

            $uri->setPath($pathPart);
            $uri->setQuery($queryPart);
            $request->setUri($uri);

            // Merge configured query params with original query params.
            // Original query params (sort_by, sort_order, page, etc.) take precedence.
            $originalQuery = $request->getQuery()->toArray();
            $queryParams = $this->prepareParamsArray($config['query'] ?? [], $originalParams);
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
                $dynamicParams = $this->prepareParamsArray($config['params'] ?? [], $originalParams);
                foreach ($dynamicParams as $k => $v) {
                    $newMatch->setParam($k, $v);
                }
                $event->setRouteMatch($newMatch);
            }
            return;
        }

        if ($isAbsoluteOrExternal) {
            $queryParams = $this->prepareParamsArray($config['query'] ?? [], $originalParams);
            $queryString = $queryParams ? http_build_query($queryParams, '', '&', PHP_QUERY_RFC3986) : '';
            $finalUrl = $redirection . ($queryString ? '?' . $queryString : '');
            $this->redirectToUrlViaHeaders($finalUrl, $status);
            return;
        }

        // Internal page slug flow.
        $routeName = $config['route'] ?? null;
        $siteSlug = $originalParams['site-slug'] ?? null;
        if (!$routeName) {
            $routeName = 'site/page';
            $pageSlug = $redirection;
            $api = $services->get('Omeka\ApiManager');
            try {
                // To use the api is the simplest way to check visibility.
                $site = $api->read('sites', ['slug' => $siteSlug], [], ['responseContent' => 'resource', 'initialize' => false, 'finalize' => false])->getContent();
                $api->read('site_pages', ['site' => $site->getId(), 'slug' => $pageSlug], [], ['responseContent' => 'resource', 'initialize' => false, 'finalize' => false]);
            } catch (\Exception $e) {
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
            $baseParams = $originalParams;
        }

        $dynamicParams = $this->prepareParamsArray($config['params'] ?? [], $originalParams);
        $finalParams = array_filter(array_replace($baseParams, $dynamicParams), static fn($v) => $v !== null && $v !== '');

        $queryParams = $this->prepareParamsArray($config['query'] ?? [], $originalParams);
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

    protected function redirectToUrlViaHeaders(string $url, int $status = 302): void
    {
        // Prepend domain if url is a site-relative path.
        if (mb_substr($url, 0, 1) === '/') {
            $serverUrlHelper = new \Laminas\View\Helper\ServerUrl();
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
