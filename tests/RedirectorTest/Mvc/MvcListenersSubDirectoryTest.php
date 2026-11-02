<?php declare(strict_types=1);

namespace RedirectorTest\Mvc;

use Omeka\Test\AbstractHttpControllerTestCase;
use RedirectorTest\RedirectorTestTrait;

/**
 * Tests for Omeka installed in a sub-directory.
 *
 * The keys and the targets are paths relative to the root of Omeka.
 */
class MvcListenersSubDirectoryTest extends AbstractHttpControllerTestCase
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

    public function testInternalForward(): void
    {
        $this->dispatchInSubDirectory(['target' => '/s/sub-test/item']);
        $routeMatch = $this->getApplication()->getMvcEvent()->getRouteMatch();
        $this->assertSame('site/resource', $routeMatch->getMatchedRouteName());
        $this->assertSame('/omeka/s/sub-test/item', $this->getRequest()->getUri()->getPath());
    }

    public function testRedirectToRelativePath(): void
    {
        $_SERVER['HTTP_HOST'] = 'example.org';
        try {
            $this->dispatchInSubDirectory(['target' => '/s/sub-test/page/new', 'status' => 301]);
        } finally {
            unset($_SERVER['HTTP_HOST']);
        }
        $this->assertResponseStatusCode(301);
        $this->assertSame(
            'http://example.org/omeka/s/sub-test/page/new',
            $this->getResponse()->getHeaders()->get('Location')->getUri()
        );
    }

    /**
     * A target without route keeps the request unchanged.
     */
    public function testUnmatchedTargetKeepsRequest(): void
    {
        $this->dispatchInSubDirectory(['target' => '/unknown/path?a=b']);
        $this->assertSame('/omeka/s/sub-test/page/old', $this->getRequest()->getUri()->getPath());
        $this->assertSame([], $this->getRequest()->getQuery()->toArray());
    }

    protected function dispatchInSubDirectory(array $config): void
    {
        $site = $this->createSite('sub-test');
        $this->setRedirectorConfig($site->id(), ['/s/sub-test/page/old' => $config]);
        $this->getRequest()->setBaseUrl('/omeka');
        $this->getApplication()->getServiceManager()->get('Router')->setBaseUrl('/omeka');
        $this->dispatch('/omeka/s/sub-test/page/old');
    }
}
