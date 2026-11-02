<?php declare(strict_types=1);

namespace RedirectorTest\Mvc;

use Omeka\Test\AbstractHttpControllerTestCase;
use RedirectorTest\RedirectorTestTrait;

/**
 * Tests for the redirection of a resource to another one ("123 = 456").
 */
class ResourceToResourceTest extends AbstractHttpControllerTestCase
{
    use RedirectorTestTrait;

    /**
     * @var int[]
     */
    protected $itemIds = [];

    public function setUp(): void
    {
        parent::setUp();
        $this->loginAdmin();
    }

    public function tearDown(): void
    {
        $this->loginAdmin();
        foreach ($this->itemIds as $id) {
            try {
                $this->api()->delete('items', $id);
            } catch (\Throwable $e) {
            }
        }
        $this->cleanupResources();
        parent::tearDown();
    }

    public function testItemIsForwardedToAnotherItem(): void
    {
        [$source, $target] = $this->prepare();
        $this->dispatch('/s/resource-test/item/' . $source);
        $routeMatch = $this->getApplication()->getMvcEvent()->getRouteMatch();
        $this->assertSame('site/resource-id', $routeMatch->getMatchedRouteName());
        $this->assertSame((string) $target, (string) $routeMatch->getParam('id'));
    }

    public function testItemIsRedirectedToAnotherItemWithStatus(): void
    {
        $_SERVER['HTTP_HOST'] = 'example.org';
        try {
            [$source, $target] = $this->prepare(['status' => 301]);
            $this->dispatch('/s/resource-test/item/' . $source);
        } finally {
            unset($_SERVER['HTTP_HOST']);
        }
        $this->assertResponseStatusCode(301);
        $this->assertSame(
            'http://example.org/s/resource-test/item/' . $target,
            $this->getResponse()->getHeaders()->get('Location')->getUri()
        );
    }

    public function testPrivateTargetIsNotRedirected(): void
    {
        [$source, $target] = $this->prepare([], false);
        $this->logout();
        $this->dispatch('/s/resource-test/item/' . $source);
        $routeMatch = $this->getApplication()->getMvcEvent()->getRouteMatch();
        $this->assertSame((string) $source, (string) $routeMatch->getParam('id'));
    }

    /**
     * Create the site and two items, the first redirected to the second.
     *
     * @return int[] The source and the target ids.
     */
    protected function prepare(array $options = [], bool $isTargetPublic = true): array
    {
        $site = $this->createSite('resource-test');
        $source = $this->api()->create('items', ['o:is_public' => true, 'o:site' => [['o:id' => $site->id()]]])->getContent()->id();
        $target = $this->api()->create('items', ['o:is_public' => $isTargetPublic, 'o:site' => [['o:id' => $site->id()]]])->getContent()->id();
        $this->itemIds = [$source, $target];

        $siteSettings = $this->getServiceLocator()->get('Omeka\Settings\Site');
        $siteSettings->setTargetId($site->id());
        if ($options) {
            $siteSettings->set('redirector_redirections_advanced', json_encode([(string) $source => ['target' => (string) $target] + $options]));
        } else {
            $siteSettings->set('redirector_redirections', [$source => (string) $target]);
        }
        return [$source, $target];
    }
}
