<?php declare(strict_types=1);

namespace RedirectorTest\Mvc;

use Omeka\Test\AbstractHttpControllerTestCase;
use Redirector\Mvc\MvcListeners;
use RedirectorTest\RedirectorTestTrait;

/**
 * Tests for edge cases and security scenarios in MvcListeners.
 */
class MvcListenersEdgeCasesTest extends AbstractHttpControllerTestCase
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
     * Test redirect with very long URL path.
     */
    public function testRedirectWithLongUrlPath(): void
    {
        $site = $this->createSite('long-url-test');
        $longPath = '/s/long-url-test/' . str_repeat('a', 500);
        $config = [
            $longPath => [
                'target' => '/s/long-url-test/item',
                'route' => 'site/resource',
                'params' => ['controller' => 'item', 'action' => 'browse'],
            ],
        ];
        $this->setRedirectorConfig($site->id(), $config);

        $this->dispatch($longPath);

        $routeMatch = $this->getApplication()->getMvcEvent()->getRouteMatch();
        $this->assertEquals('site/resource', $routeMatch->getMatchedRouteName());
    }

    /**
     * Test redirect with URL-encoded special characters in path.
     */
    public function testRedirectWithEncodedSpecialCharsInPath(): void
    {
        $site = $this->createSite('special-char-test');
        $config = [
            '/s/special-char-test/path-with-special' => [
                'target' => '/s/special-char-test/item',
                'route' => 'site/resource',
                'params' => ['controller' => 'item', 'action' => 'browse'],
            ],
        ];
        $this->setRedirectorConfig($site->id(), $config);

        $this->dispatch('/s/special-char-test/path-with-special?search=' . urlencode('test & "quoted" <value>'));

        $query = $this->getApplication()->getRequest()->getQuery();
        $this->assertEquals('test & "quoted" <value>', $query->get('search'));
    }

    /**
     * Test redirect with unicode characters in query.
     */
    public function testRedirectWithUnicodeQuery(): void
    {
        $site = $this->createSite('unicode-test');
        $config = [
            '/s/unicode-test/redirect' => [
                'target' => '/s/unicode-test/item',
                'route' => 'site/resource',
                'params' => ['controller' => 'item', 'action' => 'browse'],
            ],
        ];
        $this->setRedirectorConfig($site->id(), $config);

        $unicodeQuery = urlencode('日本語テスト');
        $this->dispatch("/s/unicode-test/redirect?search=$unicodeQuery");

        $query = $this->getApplication()->getRequest()->getQuery();
        $this->assertEquals('日本語テスト', $query->get('search'));
    }

    /**
     * Test redirect with many query parameters.
     */
    public function testRedirectWithManyQueryParams(): void
    {
        $site = $this->createSite('many-params-test');
        $config = [
            '/s/many-params-test/redirect' => [
                'target' => '/s/many-params-test/item?base=value',
                'route' => 'site/resource',
                'params' => ['controller' => 'item', 'action' => 'browse'],
            ],
        ];
        $this->setRedirectorConfig($site->id(), $config);

        $queryParts = [];
        for ($i = 1; $i <= 20; $i++) {
            $queryParts[] = "param$i=value$i";
        }
        $queryString = implode('&', $queryParts);

        $this->dispatch("/s/many-params-test/redirect?$queryString");

        $query = $this->getApplication()->getRequest()->getQuery();
        $this->assertEquals('value', $query->get('base'));
        $this->assertEquals('value1', $query->get('param1'));
        $this->assertEquals('value20', $query->get('param20'));
    }

    /**
     * Test redirect with nested array query parameters.
     */
    public function testRedirectWithNestedArrayParams(): void
    {
        $site = $this->createSite('nested-array-test');
        $config = [
            '/s/nested-array-test/redirect' => [
                'target' => '/s/nested-array-test/item',
                'route' => 'site/resource',
                'params' => ['controller' => 'item', 'action' => 'browse'],
            ],
        ];
        $this->setRedirectorConfig($site->id(), $config);

        $this->dispatch('/s/nested-array-test/redirect?filter[type]=value&filter[status]=active');

        $query = $this->getApplication()->getRequest()->getQuery();
        $filter = $query->get('filter');
        $this->assertIsArray($filter);
        $this->assertEquals('value', $filter['type']);
        $this->assertEquals('active', $filter['status']);
    }

    /**
     * Test that non-numeric resource ID doesn't cause errors.
     */
    public function testNonNumericResourceIdHandledGracefully(): void
    {
        $site = $this->createSite('non-numeric-test');
        // Config by route name, not resource ID.
        $config = [
            'site/resource-id' => [
                'target' => '/s/non-numeric-test/item',
                'route' => 'site/resource',
                'params' => ['controller' => 'item', 'action' => 'browse'],
            ],
        ];
        $this->setRedirectorConfig($site->id(), $config);

        // Should not throw exception even if no match.
        $this->dispatch('/s/non-numeric-test');
        $this->assertResponseStatusCode(200);
    }

    /**
     * Test redirect with empty query string doesn't add extra ?.
     */
    public function testRedirectWithEmptyQueryString(): void
    {
        $site = $this->createSite('empty-query-test');
        $config = [
            '/s/empty-query-test/redirect' => [
                'target' => '/s/empty-query-test/item',
                'route' => 'site/resource',
                'params' => ['controller' => 'item', 'action' => 'browse'],
            ],
        ];
        $this->setRedirectorConfig($site->id(), $config);

        $this->dispatch('/s/empty-query-test/redirect');

        $routeMatch = $this->getApplication()->getMvcEvent()->getRouteMatch();
        $this->assertEquals('site/resource', $routeMatch->getMatchedRouteName());
    }

    /**
     * Test placeholder replacement with missing values.
     */
    public function testPlaceholderReplacementWithMissingValues(): void
    {
        $listener = new MvcListeners();

        $reflection = new \ReflectionClass($listener);
        $method = $reflection->getMethod('replacePlaceholders');
        $method->setAccessible(true);

        $template = '/s/{site-slug}/item/{missing-param}/view';
        $values = ['site-slug' => 'my-site'];

        $result = $method->invoke($listener, $template, $values);
        // Missing placeholder should be replaced with empty string.
        $this->assertEquals('/s/my-site/item//view', $result);
    }

    /**
     * Test placeholder replacement with special characters in values.
     */
    public function testPlaceholderReplacementWithSpecialChars(): void
    {
        $listener = new MvcListeners();

        $reflection = new \ReflectionClass($listener);
        $method = $reflection->getMethod('replacePlaceholders');
        $method->setAccessible(true);

        $template = '/s/{site-slug}/item/{id}';
        $values = [
            'site-slug' => 'my-site',
            'id' => 'test-&-value',
        ];

        $result = $method->invoke($listener, $template, $values);
        $this->assertEquals('/s/my-site/item/test-&-value', $result);
    }

    /**
     * Test prepareParamsArray filters empty values.
     */
    public function testPrepareParamsArrayFiltersEmptyValues(): void
    {
        $listener = new MvcListeners();

        $reflection = new \ReflectionClass($listener);
        $method = $reflection->getMethod('prepareParamsArray');
        $method->setAccessible(true);

        $map = [
            'action' => 'browse',
            'empty' => '{missing}',
            'present' => '{existing}',
        ];
        $original = ['existing' => 'value'];

        $result = $method->invoke($listener, $map, $original);

        $this->assertArrayHasKey('action', $result);
        $this->assertArrayNotHasKey('empty', $result);
        $this->assertArrayHasKey('present', $result);
        $this->assertEquals('value', $result['present']);
    }

    /**
     * Test config with query params in both target URL and query array.
     */
    public function testQueryParamsFromMultipleSources(): void
    {
        $site = $this->createSite('multi-source-test');
        $config = [
            '/s/multi-source-test/redirect' => [
                'target' => '/s/multi-source-test/item?from_target=1',
                'route' => 'site/resource',
                'params' => ['controller' => 'item', 'action' => 'browse'],
                'query' => ['from_config' => '2'],
            ],
        ];
        $this->setRedirectorConfig($site->id(), $config);

        $this->dispatch('/s/multi-source-test/redirect?from_request=3');

        $query = $this->getApplication()->getRequest()->getQuery();
        $this->assertEquals('1', $query->get('from_target'));
        $this->assertEquals('2', $query->get('from_config'));
        $this->assertEquals('3', $query->get('from_request'));
    }

    /**
     * Test that request params override config params.
     */
    public function testRequestParamsOverrideConfigParams(): void
    {
        $site = $this->createSite('override-test');
        $config = [
            '/s/override-test/redirect' => [
                'target' => '/s/override-test/item?override=from_target',
                'route' => 'site/resource',
                'params' => ['controller' => 'item', 'action' => 'browse'],
                'query' => ['override' => 'from_config'],
            ],
        ];
        $this->setRedirectorConfig($site->id(), $config);

        $this->dispatch('/s/override-test/redirect?override=from_request');

        $query = $this->getApplication()->getRequest()->getQuery();
        // Request params should have highest priority.
        $this->assertEquals('from_request', $query->get('override'));
    }
}
