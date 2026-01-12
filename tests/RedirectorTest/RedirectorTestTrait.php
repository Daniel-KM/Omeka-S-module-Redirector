<?php declare(strict_types=1);

namespace RedirectorTest;

use Laminas\ServiceManager\ServiceLocatorInterface;
use Omeka\Api\Manager as ApiManager;

/**
 * Shared test helpers for Redirector module tests.
 */
trait RedirectorTestTrait
{
    /**
     * @var ServiceLocatorInterface
     */
    protected $services;

    /**
     * @var int|null Created site ID for cleanup.
     */
    protected $createdSiteId;

    /**
     * Get the API manager.
     */
    protected function api(): ApiManager
    {
        return $this->getServiceLocator()->get('Omeka\ApiManager');
    }

    /**
     * Get the service locator.
     */
    protected function getServiceLocator(): ServiceLocatorInterface
    {
        if ($this->services === null) {
            $this->services = $this->getApplication()->getServiceManager();
        }
        return $this->services;
    }

    /**
     * Get the entity manager.
     */
    protected function getEntityManager()
    {
        return $this->getServiceLocator()->get('Omeka\EntityManager');
    }

    /**
     * Login as admin user.
     */
    protected function loginAdmin(): void
    {
        $auth = $this->getServiceLocator()->get('Omeka\AuthenticationService');
        $adapter = $auth->getAdapter();
        $adapter->setIdentity('admin@example.com');
        $adapter->setCredential('root');
        $auth->authenticate();
    }

    /**
     * Logout current user.
     */
    protected function logout(): void
    {
        $auth = $this->getServiceLocator()->get('Omeka\AuthenticationService');
        $auth->clearIdentity();
    }

    /**
     * Create a test site with optional redirector settings.
     *
     * @param string $slug Site slug.
     * @param array $redirections Redirector configuration.
     * @return \Omeka\Api\Representation\SiteRepresentation
     */
    protected function createSite(string $slug, array $redirections = [])
    {
        $response = $this->api()->create('sites', [
            'o:slug' => $slug,
            'o:title' => 'Test Site ' . $slug,
            'o:theme' => 'default',
            'o:is_public' => true,
        ]);
        $site = $response->getContent();
        $this->createdSiteId = $site->id();

        // Set redirector settings if provided.
        if ($redirections) {
            $siteSettings = $this->getServiceLocator()->get('Omeka\Settings\Site');
            $siteSettings->setTargetId($site->id());
            $siteSettings->set('redirector_redirections_merged', $redirections);
        }

        return $site;
    }

    /**
     * Set redirector configuration for a site.
     *
     * @param int $siteId Site ID.
     * @param array $redirections Redirections configuration.
     */
    protected function setRedirectorConfig(int $siteId, array $redirections): void
    {
        $siteSettings = $this->getServiceLocator()->get('Omeka\Settings\Site');
        $siteSettings->setTargetId($siteId);
        $siteSettings->set('redirector_redirections_merged', $redirections);
    }

    /**
     * Get sample redirector configuration for comments/contributions.
     *
     * @param string $siteSlug Site slug to use in paths.
     * @return array
     */
    protected function getCommentRedirectorConfig(string $siteSlug): array
    {
        return [
            "/s/$siteSlug/guest/comments" => [
                'target' => "/s/$siteSlug/guest/comment?group=none",
                'route' => 'site/guest/comment',
                'params' => [
                    '__NAMESPACE__' => 'Comment\\Controller\\Site',
                    'controller' => 'Comment\\Controller\\Site\\CommentController',
                    'action' => 'browse',
                ],
            ],
            "/s/$siteSlug/guest/contributions" => [
                'target' => "/s/$siteSlug/guest/comment?group=a-identifier",
                'route' => 'site/guest/comment',
                'params' => [
                    '__NAMESPACE__' => 'Comment\\Controller\\Site',
                    'controller' => 'Comment\\Controller\\Site\\CommentController',
                    'action' => 'browse',
                ],
            ],
        ];
    }

    /**
     * Get sample redirector configuration for HTTP redirect (301/302).
     *
     * @param string $siteSlug Site slug to use in paths.
     * @return array
     */
    protected function getHttpRedirectConfig(string $siteSlug): array
    {
        return [
            "/s/$siteSlug/old-page" => [
                'target' => "/s/$siteSlug/new-page",
                'status' => 301,
            ],
            "/s/$siteSlug/temp-redirect" => [
                'target' => "/s/$siteSlug/temporary-target",
                'status' => 302,
            ],
        ];
    }

    /**
     * Get sample redirector configuration with placeholders.
     *
     * @return array
     */
    protected function getPlaceholderConfig(): array
    {
        return [
            'site/resource-id' => [
                'target' => '/s/{site-slug}/item/{id}/custom',
                'route' => 'site/resource',
                'params' => [
                    'controller' => 'item',
                    'action' => 'show',
                ],
            ],
        ];
    }

    /**
     * Get sample redirector configuration for external URL.
     *
     * @return array
     */
    protected function getExternalRedirectConfig(): array
    {
        return [
            '/s/test/external' => [
                'target' => 'https://example.org/external-page',
                'status' => 302,
            ],
        ];
    }

    /**
     * Get sample redirector configuration with query parameters.
     *
     * @param string $siteSlug Site slug.
     * @return array
     */
    protected function getQueryParamsConfig(string $siteSlug): array
    {
        return [
            "/s/$siteSlug/search-redirect" => [
                'target' => "/s/$siteSlug/item?fulltext_search=test",
                'route' => 'site/resource',
                'params' => [
                    'controller' => 'item',
                    'action' => 'browse',
                ],
                'query' => [
                    'sort_by' => 'created',
                    'sort_order' => 'desc',
                ],
            ],
        ];
    }

    /**
     * Clean up created resources after test.
     */
    protected function cleanupResources(): void
    {
        // Delete created site.
        if ($this->createdSiteId) {
            try {
                $this->api()->delete('sites', $this->createdSiteId);
            } catch (\Exception $e) {
                // Ignore errors during cleanup.
            }
            $this->createdSiteId = null;
        }
    }
}
