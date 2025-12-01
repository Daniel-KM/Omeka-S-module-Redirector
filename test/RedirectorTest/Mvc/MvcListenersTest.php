<?php declare(strict_types=1);

namespace RedirectorTest\Mvc;

use Laminas\Http\Request;
use Laminas\Mvc\MvcEvent;
use Laminas\Router\Http\RouteMatch;
use Laminas\Stdlib\Parameters;
use Laminas\Uri\Http as HttpUri;
use Omeka\Test\AbstractHttpControllerTestCase;
use Redirector\Mvc\MvcListeners;
use RedirectorTest\RedirectorTestTrait;

/**
 * Unit tests for MvcListeners redirect functionality.
 */
class MvcListenersTest extends AbstractHttpControllerTestCase
{
    use RedirectorTestTrait;

    protected MvcListeners $listener;

    public function setUp(): void
    {
        parent::setUp();
        $this->loginAdmin();
        $this->listener = new MvcListeners();
    }

    public function tearDown(): void
    {
        $this->cleanupResources();
        parent::tearDown();
    }

    /**
     * Test that listener attaches to MvcEvent::EVENT_ROUTE.
     */
    public function testListenerAttachesToRouteEvent(): void
    {
        $eventManager = $this->getApplication()->getEventManager();

        $this->listener->attach($eventManager);

        // Use reflection to verify listeners were attached.
        $reflection = new \ReflectionClass($this->listener);
        $property = $reflection->getProperty('listeners');
        $property->setAccessible(true);
        $listeners = $property->getValue($this->listener);

        $this->assertNotEmpty($listeners, 'Listener should have attached handlers');
    }

    /**
     * Test internal redirect preserves original query parameters.
     *
     * This tests the fix for the sort parameter issue with redirects.
     */
    public function testInternalRedirectPreservesQueryParameters(): void
    {
        $site = $this->createSite('test-site');
        $config = $this->getCommentRedirectorConfig('test-site');
        $this->setRedirectorConfig($site->id(), $config);

        // Simulate request to /s/test-site/guest/comments?sort_by=created&sort_order=asc
        $this->dispatch('/s/test-site/guest/comments?sort_by=created&sort_order=asc');

        // Get the final query parameters from the request.
        $request = $this->getApplication()->getRequest();
        $query = $request->getQuery();

        // Verify both the redirect's group param and original sort params are present.
        $this->assertEquals('none', $query->get('group'), 'Group parameter from redirect should be set');
        $this->assertEquals('created', $query->get('sort_by'), 'Original sort_by should be preserved');
        $this->assertEquals('asc', $query->get('sort_order'), 'Original sort_order should be preserved');
    }

    /**
     * Test internal redirect with contributions group.
     */
    public function testInternalRedirectWithContributionsGroup(): void
    {
        $site = $this->createSite('test-site');
        $config = $this->getCommentRedirectorConfig('test-site');
        $this->setRedirectorConfig($site->id(), $config);

        // Simulate request to /s/test-site/guest/contributions?page=2
        $this->dispatch('/s/test-site/guest/contributions?page=2');

        $request = $this->getApplication()->getRequest();
        $query = $request->getQuery();

        $this->assertEquals('a-identifier', $query->get('group'), 'Group parameter should be a-identifier');
        $this->assertEquals('2', $query->get('page'), 'Original page parameter should be preserved');
    }

    /**
     * Test that redirect target query params are applied.
     */
    public function testRedirectTargetQueryParamsAreApplied(): void
    {
        $site = $this->createSite('test-site');
        $config = [
            '/s/test-site/custom-redirect' => [
                'target' => '/s/test-site/item?category=special&featured=1',
                'route' => 'site/resource',
                'params' => [
                    'controller' => 'item',
                    'action' => 'browse',
                ],
            ],
        ];
        $this->setRedirectorConfig($site->id(), $config);

        $this->dispatch('/s/test-site/custom-redirect');

        $request = $this->getApplication()->getRequest();
        $query = $request->getQuery();

        $this->assertEquals('special', $query->get('category'), 'Category from target should be set');
        $this->assertEquals('1', $query->get('featured'), 'Featured from target should be set');
    }

    /**
     * Test original query params override target query params.
     */
    public function testOriginalQueryParamsOverrideTargetParams(): void
    {
        $site = $this->createSite('test-site');
        $config = [
            '/s/test-site/search' => [
                'target' => '/s/test-site/item?sort_by=title&sort_order=asc',
                'route' => 'site/resource',
                'params' => [
                    'controller' => 'item',
                    'action' => 'browse',
                ],
            ],
        ];
        $this->setRedirectorConfig($site->id(), $config);

        // Request with different sort params.
        $this->dispatch('/s/test-site/search?sort_by=created&sort_order=desc');

        $request = $this->getApplication()->getRequest();
        $query = $request->getQuery();

        // Original params should override target params.
        $this->assertEquals('created', $query->get('sort_by'), 'Original sort_by should override target');
        $this->assertEquals('desc', $query->get('sort_order'), 'Original sort_order should override target');
    }

    /**
     * Test config query params are merged.
     */
    public function testConfigQueryParamsAreMerged(): void
    {
        $site = $this->createSite('test-site');
        $config = $this->getQueryParamsConfig('test-site');
        $this->setRedirectorConfig($site->id(), $config);

        $this->dispatch('/s/test-site/search-redirect?extra=value');

        $request = $this->getApplication()->getRequest();
        $query = $request->getQuery();

        // Target query params.
        $this->assertEquals('test', $query->get('fulltext_search'), 'fulltext_search from target should be set');
        // Config query params.
        $this->assertEquals('created', $query->get('sort_by'), 'sort_by from config should be set');
        $this->assertEquals('desc', $query->get('sort_order'), 'sort_order from config should be set');
        // Original query params.
        $this->assertEquals('value', $query->get('extra'), 'Original extra param should be preserved');
    }

    /**
     * Test placeholder replacement in redirect target.
     */
    public function testPlaceholderReplacement(): void
    {
        $listener = new MvcListeners();

        // Use reflection to test protected method.
        $reflection = new \ReflectionClass($listener);
        $method = $reflection->getMethod('replacePlaceholders');
        $method->setAccessible(true);

        $template = '/s/{site-slug}/item/{id}/view';
        $values = [
            'site-slug' => 'my-site',
            'id' => '123',
        ];

        $result = $method->invoke($listener, $template, $values);
        $this->assertEquals('/s/my-site/item/123/view', $result);
    }

    /**
     * Test placeholder with missing value returns empty string.
     */
    public function testPlaceholderMissingValueReturnsEmpty(): void
    {
        $listener = new MvcListeners();

        $reflection = new \ReflectionClass($listener);
        $method = $reflection->getMethod('replacePlaceholders');
        $method->setAccessible(true);

        $template = '/s/{site-slug}/item/{missing}/view';
        $values = [
            'site-slug' => 'my-site',
        ];

        $result = $method->invoke($listener, $template, $values);
        $this->assertEquals('/s/my-site/item//view', $result);
    }

    /**
     * Test prepareParamsArray method.
     */
    public function testPrepareParamsArray(): void
    {
        $listener = new MvcListeners();

        $reflection = new \ReflectionClass($listener);
        $method = $reflection->getMethod('prepareParamsArray');
        $method->setAccessible(true);

        $map = [
            'action' => 'browse',
            'site-slug' => '{site-slug}',
            'id' => '{resource-id}',
        ];
        $original = [
            'site-slug' => 'test-site',
            'resource-id' => '456',
        ];

        $result = $method->invoke($listener, $map, $original);

        $this->assertEquals('browse', $result['action']);
        $this->assertEquals('test-site', $result['site-slug']);
        $this->assertEquals('456', $result['id']);
    }

    /**
     * Test that non-site routes are ignored.
     */
    public function testNonSiteRoutesAreIgnored(): void
    {
        // Admin routes should not be processed by the redirector.
        // We just verify that the listener checks for site routes.
        $listener = new MvcListeners();

        // Create a mock event with a non-site route.
        $routeMatch = new RouteMatch([
            'controller' => 'Omeka\Controller\Admin\Item',
            'action' => 'browse',
        ]);
        $routeMatch->setMatchedRouteName('admin/default');

        $event = new MvcEvent();
        $event->setRouteMatch($routeMatch);
        $event->setApplication($this->getApplication());

        // The listener should return early without modifying anything.
        $listener->redirectResource($event);

        // Verify route match was not modified.
        $this->assertEquals('admin/default', $event->getRouteMatch()->getMatchedRouteName());
    }

    /**
     * Test redirect without configuration does nothing.
     */
    public function testNoConfigDoesNothing(): void
    {
        $site = $this->createSite('empty-site');
        // Don't set any redirector config.

        $this->dispatch('/s/empty-site');

        // Should proceed normally without redirect.
        $this->assertResponseStatusCode(200);
    }

    /**
     * Test that route match params can be overridden.
     */
    public function testRouteMatchParamsCanBeOverridden(): void
    {
        $site = $this->createSite('test-site');
        $config = [
            '/s/test-site/custom-action' => [
                'target' => '/s/test-site/item',
                'route' => 'site/resource',
                'params' => [
                    'controller' => 'item',
                    'action' => 'browse',
                    '__NAMESPACE__' => 'Omeka\\Controller\\Site',
                ],
            ],
        ];
        $this->setRedirectorConfig($site->id(), $config);

        $this->dispatch('/s/test-site/custom-action');

        // Verify the route was matched correctly.
        $routeMatch = $this->getApplication()->getMvcEvent()->getRouteMatch();
        $this->assertEquals('browse', $routeMatch->getParam('action'));
        $this->assertEquals('item', $routeMatch->getParam('controller'));
    }
}
