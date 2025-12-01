<?php declare(strict_types=1);

namespace RedirectorTest;

use Omeka\Test\AbstractHttpControllerTestCase;

/**
 * Integration tests for Redirector module functionality.
 *
 * These tests verify end-to-end redirect behavior with various configurations.
 */
class RedirectionIntegrationTest extends AbstractHttpControllerTestCase
{
    use RedirectorTestTrait;

    public function setUp(): void
    {
        parent::setUp();
        $this->loginAdmin();
    }

    public function tearDown(): void
    {
        $this->cleanupResources();
        parent::tearDown();
    }

    /**
     * Test basic internal forward redirect.
     */
    public function testBasicInternalForward(): void
    {
        $site = $this->createSite('forward-test');
        $config = [
            '/s/forward-test/source-page' => [
                'target' => '/s/forward-test/item',
                'route' => 'site/resource',
                'params' => [
                    'controller' => 'item',
                    'action' => 'browse',
                ],
            ],
        ];
        $this->setRedirectorConfig($site->id(), $config);

        $this->dispatch('/s/forward-test/source-page');

        // Verify the request was forwarded (not HTTP redirect).
        $routeMatch = $this->getApplication()->getMvcEvent()->getRouteMatch();
        $this->assertEquals('site/resource', $routeMatch->getMatchedRouteName());
        $this->assertEquals('item', $routeMatch->getParam('controller'));
        $this->assertEquals('browse', $routeMatch->getParam('action'));
    }

    /**
     * Test forward preserves all types of query parameters.
     *
     * @dataProvider queryParameterProvider
     */
    public function testForwardPreservesVariousQueryParameters(
        string $originalQuery,
        string $targetQuery,
        array $expectedParams
    ): void {
        $site = $this->createSite('query-test');
        $config = [
            '/s/query-test/redirect' => [
                'target' => '/s/query-test/item' . ($targetQuery ? "?$targetQuery" : ''),
                'route' => 'site/resource',
                'params' => [
                    'controller' => 'item',
                    'action' => 'browse',
                ],
            ],
        ];
        $this->setRedirectorConfig($site->id(), $config);

        $url = '/s/query-test/redirect' . ($originalQuery ? "?$originalQuery" : '');
        $this->dispatch($url);

        $request = $this->getApplication()->getRequest();
        $query = $request->getQuery();

        foreach ($expectedParams as $key => $expectedValue) {
            $this->assertEquals(
                $expectedValue,
                $query->get($key),
                "Query parameter '$key' should equal '$expectedValue'"
            );
        }
    }

    /**
     * Data provider for query parameter tests.
     */
    public function queryParameterProvider(): array
    {
        return [
            'original only' => [
                'originalQuery' => 'page=1&per_page=10',
                'targetQuery' => '',
                'expectedParams' => ['page' => '1', 'per_page' => '10'],
            ],
            'target only' => [
                'originalQuery' => '',
                'targetQuery' => 'group=none&featured=1',
                'expectedParams' => ['group' => 'none', 'featured' => '1'],
            ],
            'original overrides target' => [
                'originalQuery' => 'sort_by=title&sort_order=asc',
                'targetQuery' => 'sort_by=created&sort_order=desc',
                'expectedParams' => ['sort_by' => 'title', 'sort_order' => 'asc'],
            ],
            'merged parameters' => [
                'originalQuery' => 'page=2&sort_by=title',
                'targetQuery' => 'group=special&featured=1',
                'expectedParams' => [
                    'page' => '2',
                    'sort_by' => 'title',
                    'group' => 'special',
                    'featured' => '1',
                ],
            ],
            'complex sort with group' => [
                'originalQuery' => 'sort_by=created&sort_order=asc&page=1',
                'targetQuery' => 'group=a-identifier',
                'expectedParams' => [
                    'sort_by' => 'created',
                    'sort_order' => 'asc',
                    'page' => '1',
                    'group' => 'a-identifier',
                ],
            ],
        ];
    }

    /**
     * Test multiple redirections on same site.
     */
    public function testMultipleRedirectionsOnSameSite(): void
    {
        $site = $this->createSite('multi-test');
        $config = [
            '/s/multi-test/path-a' => [
                'target' => '/s/multi-test/item?type=a',
                'route' => 'site/resource',
                'params' => ['controller' => 'item', 'action' => 'browse'],
            ],
            '/s/multi-test/path-b' => [
                'target' => '/s/multi-test/item?type=b',
                'route' => 'site/resource',
                'params' => ['controller' => 'item', 'action' => 'browse'],
            ],
            '/s/multi-test/path-c' => [
                'target' => '/s/multi-test/item?type=c',
                'route' => 'site/resource',
                'params' => ['controller' => 'item', 'action' => 'browse'],
            ],
        ];
        $this->setRedirectorConfig($site->id(), $config);

        // Test path-a.
        $this->dispatch('/s/multi-test/path-a');
        $query = $this->getApplication()->getRequest()->getQuery();
        $this->assertEquals('a', $query->get('type'));

        // Reset for next request.
        $this->reset();
        $this->loginAdmin();

        // Test path-b.
        $this->dispatch('/s/multi-test/path-b');
        $query = $this->getApplication()->getRequest()->getQuery();
        $this->assertEquals('b', $query->get('type'));

        // Reset for next request.
        $this->reset();
        $this->loginAdmin();

        // Test path-c.
        $this->dispatch('/s/multi-test/path-c');
        $query = $this->getApplication()->getRequest()->getQuery();
        $this->assertEquals('c', $query->get('type'));
    }

    /**
     * Test redirect with pagination parameters.
     */
    public function testRedirectWithPagination(): void
    {
        $site = $this->createSite('pagination-test');
        $config = $this->getCommentRedirectorConfig('pagination-test');
        $this->setRedirectorConfig($site->id(), $config);

        $this->dispatch('/s/pagination-test/guest/comments?page=5&per_page=25');

        $query = $this->getApplication()->getRequest()->getQuery();

        $this->assertEquals('none', $query->get('group'));
        $this->assertEquals('5', $query->get('page'));
        $this->assertEquals('25', $query->get('per_page'));
    }

    /**
     * Test redirect with fulltext search parameter.
     */
    public function testRedirectWithFulltextSearch(): void
    {
        $site = $this->createSite('search-test');
        $config = $this->getCommentRedirectorConfig('search-test');
        $this->setRedirectorConfig($site->id(), $config);

        $this->dispatch('/s/search-test/guest/comments?fulltext_search=test+query&sort_by=relevance');

        $query = $this->getApplication()->getRequest()->getQuery();

        $this->assertEquals('none', $query->get('group'));
        $this->assertEquals('test query', $query->get('fulltext_search'));
        $this->assertEquals('relevance', $query->get('sort_by'));
    }

    /**
     * Test URL-encoded query parameters are preserved.
     */
    public function testUrlEncodedParametersArePreserved(): void
    {
        $site = $this->createSite('encoded-test');
        $config = [
            '/s/encoded-test/redirect' => [
                'target' => '/s/encoded-test/item',
                'route' => 'site/resource',
                'params' => ['controller' => 'item', 'action' => 'browse'],
            ],
        ];
        $this->setRedirectorConfig($site->id(), $config);

        // Use URL-encoded special characters.
        $this->dispatch('/s/encoded-test/redirect?search=' . urlencode('test & value'));

        $query = $this->getApplication()->getRequest()->getQuery();
        $this->assertEquals('test & value', $query->get('search'));
    }

    /**
     * Test array query parameters.
     */
    public function testArrayQueryParameters(): void
    {
        $site = $this->createSite('array-test');
        $config = [
            '/s/array-test/redirect' => [
                'target' => '/s/array-test/item',
                'route' => 'site/resource',
                'params' => ['controller' => 'item', 'action' => 'browse'],
            ],
        ];
        $this->setRedirectorConfig($site->id(), $config);

        $this->dispatch('/s/array-test/redirect?property[]=1&property[]=2&property[]=3');

        $query = $this->getApplication()->getRequest()->getQuery();
        $property = $query->get('property');

        $this->assertIsArray($property);
        $this->assertCount(3, $property);
        $this->assertEquals(['1', '2', '3'], $property);
    }

    /**
     * Test that empty config key doesn't cause errors.
     */
    public function testEmptyConfigKeyHandledGracefully(): void
    {
        $site = $this->createSite('empty-key-test');
        $config = [
            '' => [
                'target' => '/s/empty-key-test/item',
            ],
            '/s/empty-key-test/valid' => [
                'target' => '/s/empty-key-test/item',
                'route' => 'site/resource',
                'params' => ['controller' => 'item', 'action' => 'browse'],
            ],
        ];
        $this->setRedirectorConfig($site->id(), $config);

        // Should not throw exception - redirect to items browse.
        $this->dispatch('/s/empty-key-test/valid');

        // The redirect should work - items browse returns 200.
        $routeMatch = $this->getApplication()->getMvcEvent()->getRouteMatch();
        $this->assertEquals('site/resource', $routeMatch->getMatchedRouteName());
        $this->assertEquals('item', $routeMatch->getParam('controller'));
        $this->assertEquals('browse', $routeMatch->getParam('action'));
    }

    /**
     * Test real-world Comment module redirect scenario.
     *
     * Simulates the exact use case from production:
     * - /guest/comments -> /guest/comment?group=none
     * - /guest/contributions -> /guest/comment?group=a-identifier
     * - User applies sorting via form submission.
     */
    public function testCommentModuleRedirectScenario(): void
    {
        $site = $this->createSite('comment-scenario');
        $config = $this->getCommentRedirectorConfig('comment-scenario');
        $this->setRedirectorConfig($site->id(), $config);

        // Step 1: User visits /guest/comments (initial load).
        $this->dispatch('/s/comment-scenario/guest/comments');
        $query = $this->getApplication()->getRequest()->getQuery();
        $this->assertEquals('none', $query->get('group'), 'Initial visit should set group=none');

        // Step 2: User applies sorting (form submission with sort params).
        $this->reset();
        $this->loginAdmin();
        $this->dispatch('/s/comment-scenario/guest/comments?sort_by=created&sort_order=asc');

        $query = $this->getApplication()->getRequest()->getQuery();
        $this->assertEquals('none', $query->get('group'), 'Group should remain set');
        $this->assertEquals('created', $query->get('sort_by'), 'Sort by should be preserved');
        $this->assertEquals('asc', $query->get('sort_order'), 'Sort order should be preserved');

        // Step 3: User changes to descending order.
        $this->reset();
        $this->loginAdmin();
        $this->dispatch('/s/comment-scenario/guest/comments?sort_by=created&sort_order=desc&page=2');

        $query = $this->getApplication()->getRequest()->getQuery();
        $this->assertEquals('none', $query->get('group'));
        $this->assertEquals('created', $query->get('sort_by'));
        $this->assertEquals('desc', $query->get('sort_order'));
        $this->assertEquals('2', $query->get('page'));
    }

    /**
     * Test contributions redirect scenario with sorting.
     */
    public function testContributionsRedirectScenario(): void
    {
        $site = $this->createSite('contrib-scenario');
        $config = $this->getCommentRedirectorConfig('contrib-scenario');
        $this->setRedirectorConfig($site->id(), $config);

        // Visit contributions with sort parameters.
        $this->dispatch('/s/contrib-scenario/guest/contributions?sort_by=modified&sort_order=desc');

        $query = $this->getApplication()->getRequest()->getQuery();
        $this->assertEquals('a-identifier', $query->get('group'), 'Group should be a-identifier for contributions');
        $this->assertEquals('modified', $query->get('sort_by'));
        $this->assertEquals('desc', $query->get('sort_order'));
    }
}
