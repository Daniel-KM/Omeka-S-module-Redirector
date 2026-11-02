<?php declare(strict_types=1);

namespace RedirectorTest\Mvc;

use Omeka\Test\AbstractHttpControllerTestCase;
use Redirector\Mvc\MvcListeners;
use RedirectorTest\RedirectorTestTrait;

/**
 * Security tests for MvcListeners URL validation.
 *
 * These tests verify that the redirections to urls reject malicious urls.
 */
class MvcListenersSecurityTest extends AbstractHttpControllerTestCase
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
     * Test URL validation rejects newline characters (header injection).
     *
     * @dataProvider headerInjectionUrlsProvider
     */
    public function testUrlWithNewlinesIsRejected(string $maliciousUrl): void
    {
        $this->dispatchRedirection($maliciousUrl);
        $this->assertNotRedirected();
    }

    public function headerInjectionUrlsProvider(): array
    {
        return [
            'carriage return' => ["https://example.org/page\rSet-Cookie: evil=value"],
            'line feed' => ["https://example.org/page\nSet-Cookie: evil=value"],
            'crlf' => ["https://example.org/page\r\nSet-Cookie: evil=value"],
            'encoded cr' => [urldecode("https://example.org/page%0dSet-Cookie: evil=value")],
            'encoded lf' => [urldecode("https://example.org/page%0aSet-Cookie: evil=value")],
        ];
    }

    /**
     * Test URL validation accepts valid HTTP/HTTPS URLs.
     *
     * @dataProvider validUrlsProvider
     */
    public function testValidUrlsAreAccepted(string $url): void
    {
        $this->dispatchRedirection($url);
        $this->assertResponseStatusCode(302);
        $this->assertSame($url, $this->getResponse()->getHeaders()->get('Location')->getUri());
    }

    public function validUrlsProvider(): array
    {
        return [
            'simple https' => ['https://example.org'],
            'https with path' => ['https://example.org/page/subpage'],
            'https with query' => ['https://example.org/page?foo=bar&baz=qux'],
            'https with port' => ['https://example.org:8443/page'],
            'http url' => ['http://example.org/page'],
            'https with fragment' => ['https://example.org/page#section'],
            'long path' => ['https://example.org/' . str_repeat('path/', 50)],
        ];
    }

    /**
     * Test URL validation rejects non-HTTP schemes.
     *
     * @dataProvider invalidSchemeUrlsProvider
     */
    public function testInvalidSchemesAreRejected(string $url): void
    {
        $this->dispatchRedirection($url);
        $this->assertNotRedirected();
    }

    public function invalidSchemeUrlsProvider(): array
    {
        return [
            'javascript' => ['javascript:alert(1)'],
            'data uri' => ['data:text/html,<script>alert(1)</script>'],
            'file' => ['file:///etc/passwd'],
            'ftp' => ['ftp://example.org/file'],
            'mailto' => ['mailto:test@example.org'],
            'tel' => ['tel:+1234567890'],
        ];
    }

    /**
     * Test that relative paths are prepended with the server url.
     */
    public function testRelativePathsArePrepended(): void
    {
        // The server url is built from the host, missing in cli.
        $_SERVER['HTTP_HOST'] = 'example.org';
        try {
            $this->dispatchRedirection('/s/security-test/page/new', 301);
        } finally {
            unset($_SERVER['HTTP_HOST']);
        }
        $this->assertResponseStatusCode(301);
        $location = $this->getResponse()->getHeaders()->get('Location')->getUri();
        $this->assertSame('http://example.org/s/security-test/page/new', $location);
    }

    /**
     * Test that an external url without status is redirected with 302.
     */
    public function testExternalUrlWithoutStatusIsRedirected(): void
    {
        $site = $this->createSite('security-test');
        $this->setRedirectorConfig($site->id(), [
            '/s/security-test/page/old' => ['target' => 'https://omeka.org/s'],
        ]);
        $this->dispatch('/s/security-test/page/old');
        $this->assertResponseStatusCode(302);
        $this->assertSame('https://omeka.org/s', $this->getResponse()->getHeaders()->get('Location')->getUri());
    }

    /**
     * Test that the simple redirections are used without saving the form.
     */
    public function testSimpleRedirectionSetOutsideForm(): void
    {
        $site = $this->createSite('security-test');
        $siteSettings = $this->getServiceLocator()->get('Omeka\Settings\Site');
        $siteSettings->setTargetId($site->id());
        $siteSettings->set('redirector_redirections', ['/s/security-test/page/old' => 'https://omeka.org/s']);
        $this->dispatch('/s/security-test/page/old');
        $this->assertResponseStatusCode(302);
        $this->assertSame('https://omeka.org/s', $this->getResponse()->getHeaders()->get('Location')->getUri());
    }

    /**
     * Dispatch a page of a site redirected to the specified target.
     */
    protected function dispatchRedirection(string $target, int $status = 302): void
    {
        $site = $this->createSite('security-test');
        $this->setRedirectorConfig($site->id(), [
            '/s/security-test/page/old' => [
                'target' => $target,
                'status' => $status,
            ],
        ]);
        $this->dispatch('/s/security-test/page/old');
    }

    protected function assertNotRedirected(): void
    {
        $this->assertNotContains($this->getResponse()->getStatusCode(), [301, 302, 303, 307, 308]);
        $this->assertFalse($this->getResponse()->getHeaders()->has('Location'));
    }

    /**
     * Test redirect config with valid internal path.
     */
    public function testInternalRedirectConfig(): void
    {
        $site = $this->createSite('security-test');
        $config = [
            '/s/security-test/old' => [
                'target' => '/s/security-test/item',
                'route' => 'site/resource',
                'params' => ['controller' => 'item', 'action' => 'browse'],
            ],
        ];
        $this->setRedirectorConfig($site->id(), $config);

        $this->dispatch('/s/security-test/old');

        $routeMatch = $this->getApplication()->getMvcEvent()->getRouteMatch();
        $this->assertEquals('site/resource', $routeMatch->getMatchedRouteName());
    }

    /**
     * Test that config without status defaults to internal forward.
     */
    public function testConfigWithoutStatusIsInternalForward(): void
    {
        $site = $this->createSite('forward-default-test');
        $config = [
            '/s/forward-default-test/source' => [
                'target' => '/s/forward-default-test/item',
                'route' => 'site/resource',
                'params' => ['controller' => 'item', 'action' => 'browse'],
                // No status = internal forward.
            ],
        ];
        $this->setRedirectorConfig($site->id(), $config);

        $this->dispatch('/s/forward-default-test/source');

        // Should be forwarded, not redirected.
        $routeMatch = $this->getApplication()->getMvcEvent()->getRouteMatch();
        $this->assertEquals('site/resource', $routeMatch->getMatchedRouteName());
        $this->assertEquals('item', $routeMatch->getParam('controller'));
    }

    /**
     * Test path traversal attempts are handled.
     */
    public function testPathTraversalInQuery(): void
    {
        $site = $this->createSite('traversal-test');
        $config = [
            '/s/traversal-test/redirect' => [
                'target' => '/s/traversal-test/item',
                'route' => 'site/resource',
                'params' => ['controller' => 'item', 'action' => 'browse'],
            ],
        ];
        $this->setRedirectorConfig($site->id(), $config);

        // Path traversal in query param should be preserved as-is (not executed).
        $this->dispatch('/s/traversal-test/redirect?path=' . urlencode('../../../etc/passwd'));

        $query = $this->getApplication()->getRequest()->getQuery();
        $this->assertEquals('../../../etc/passwd', $query->get('path'));
    }

    /**
     * Test HTML entities in query params are preserved.
     */
    public function testHtmlEntitiesInQueryParams(): void
    {
        $site = $this->createSite('html-entities-test');
        $config = [
            '/s/html-entities-test/redirect' => [
                'target' => '/s/html-entities-test/item',
                'route' => 'site/resource',
                'params' => ['controller' => 'item', 'action' => 'browse'],
            ],
        ];
        $this->setRedirectorConfig($site->id(), $config);

        $htmlContent = '<script>alert("xss")</script>';
        $this->dispatch('/s/html-entities-test/redirect?content=' . urlencode($htmlContent));

        $query = $this->getApplication()->getRequest()->getQuery();
        // Should be preserved as raw value (escaping happens at output).
        $this->assertEquals($htmlContent, $query->get('content'));
    }
}
