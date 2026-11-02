<?php declare(strict_types=1);

namespace RedirectorTest\Mvc;

use Omeka\Test\AbstractHttpControllerTestCase;
use RedirectorTest\RedirectorTestTrait;

/**
 * Tests for the redirections of the main settings.
 */
class MainSettingsTest extends AbstractHttpControllerTestCase
{
    use RedirectorTestTrait;

    public function setUp(): void
    {
        parent::setUp();
        $this->loginAdmin();
    }

    public function tearDown(): void
    {
        $settings = $this->getServiceLocator()->get('Omeka\Settings');
        $settings->delete('redirector_redirections');
        $settings->delete('redirector_redirections_advanced');
        $this->cleanupResources();
        parent::tearDown();
    }

    public function testMainRedirectionAppliesToSites(): void
    {
        $this->createSite('main-test');
        $this->setMain(['/s/main-test/page/old' => ['target' => 'https://omeka.org/s']]);
        $this->dispatch('/s/main-test/page/old');
        $this->assertRedirectedTo('https://omeka.org/s');
    }

    public function testSiteRedirectionOverridesMainOne(): void
    {
        $site = $this->createSite('main-test');
        $this->setMain(['/s/main-test/page/old' => ['target' => 'https://omeka.org/s']]);
        $this->setRedirectorConfig($site->id(), ['/s/main-test/page/old' => ['target' => 'https://example.org/site']]);
        $this->dispatch('/s/main-test/page/old');
        $this->assertRedirectedTo('https://example.org/site');
    }

    public function testRouteOutsideSitesIsRedirected(): void
    {
        $this->setMain(['top' => ['target' => 'https://omeka.org/s']]);
        $this->dispatch('/');
        $this->assertRedirectedTo('https://omeka.org/s');
    }

    /**
     * @dataProvider excludedRoutesProvider
     */
    public function testExcludedRoutesAreNotRedirected(string $key, string $url): void
    {
        $this->logout();
        $this->setMain([$key => ['target' => 'https://omeka.org/s', 'status' => 302]]);
        $this->dispatch($url);
        $this->assertFalse($this->getResponse()->getHeaders()->has('Location')
            && $this->getResponse()->getHeaders()->get('Location')->getUri() === 'https://omeka.org/s');
    }

    public function excludedRoutesProvider(): array
    {
        return [
            'admin' => ['admin', '/admin'],
            'admin path' => ['/admin/item', '/admin/item'],
            'api' => ['/api/items', '/api/items'],
            'login' => ['login', '/login'],
            'forgot password' => ['/forgot-password', '/forgot-password'],
        ];
    }

    /**
     * A page slug or a resource id requires a site.
     */
    public function testTargetRequiringSiteIsSkippedOutsideSites(): void
    {
        $this->setMain(['top' => ['target' => 'events']]);
        $this->dispatch('/');
        $this->assertFalse($this->getResponse()->getHeaders()->has('Location'));
        $this->assertSame('top', $this->getApplication()->getMvcEvent()->getRouteMatch()->getMatchedRouteName());
    }

    public function testMainSettingsUsePairsEditor(): void
    {
        $this->dispatch('/admin/setting');
        $this->assertResponseStatusCode(200);
        $this->assertQuery('textarea#redirector_redirections.common-pairs-textarea');
    }

    protected function setMain(array $redirections): void
    {
        $this->getServiceLocator()->get('Omeka\Settings')
            ->set('redirector_redirections_advanced', json_encode($redirections));
    }

    protected function assertRedirectedTo(string $url): void
    {
        $this->assertResponseStatusCode(302);
        $this->assertSame($url, $this->getResponse()->getHeaders()->get('Location')->getUri());
    }
}
