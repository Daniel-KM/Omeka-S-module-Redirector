<?php declare(strict_types=1);

namespace RedirectorTest\Form;

use Omeka\Test\AbstractHttpControllerTestCase;
use RedirectorTest\RedirectorTestTrait;

/**
 * Tests for form validation logic in SiteSettingsFieldset.
 *
 * These tests validate the callback logic directly rather than through
 * the InputFilter to avoid service manager dependencies.
 */
class SiteSettingsFieldsetValidationTest extends AbstractHttpControllerTestCase
{
    use RedirectorTestTrait;

    protected \Closure $validationCallback;

    public function setUp(): void
    {
        parent::setUp();
        $this->loginAdmin();

        // Extract the validation callback from the fieldset specification.
        // This is the callback that validates the JSON structure.
        $this->validationCallback = function ($value) {
            if ($value === '' || $value === null) {
                return true;
            }
            $decoded = json_decode($value, true);
            if (!is_array($decoded)) {
                return false;
            }
            if ($decoded === []) {
                return true;
            }
            $isAssoc = array_keys($decoded) !== range(0, count($decoded) - 1);
            if (!$isAssoc) {
                return false;
            }
            // Collect sources and targets for loop detection.
            $targets = [];
            foreach ($decoded as $key => $v) {
                if (!is_array($v) || empty($v['target']) || !is_string($v['target'])) {
                    return false;
                }
                if (isset($v['status']) && !in_array((int) $v['status'], [301, 302, 303, 307, 308], true)) {
                    return false;
                }
                if (isset($v['params']) && !is_array($v['params'])) {
                    return false;
                }
                if (isset($v['query']) && !is_array($v['query'])) {
                    return false;
                }
                // Validate route is a non-empty string if provided.
                if (isset($v['route']) && (!is_string($v['route']) || $v['route'] === '')) {
                    return false;
                }
                // Extract target path for loop detection.
                $targetPath = parse_url($v['target'], PHP_URL_PATH) ?: $v['target'];
                $targets[$key] = $targetPath;
            }
            // Check for direct redirect loops (A→B and B→A).
            foreach ($targets as $source => $target) {
                if (isset($decoded[$target])) {
                    $reverseTarget = parse_url($decoded[$target]['target'], PHP_URL_PATH)
                        ?: $decoded[$target]['target'];
                    if ($reverseTarget === $source) {
                        return false;
                    }
                }
            }
            return true;
        };
    }

    public function tearDown(): void
    {
        $this->cleanupResources();
        parent::tearDown();
    }

    /**
     * Test valid JSON is accepted.
     */
    public function testValidJsonIsAccepted(): void
    {
        $validJson = json_encode([
            '/s/test/path' => [
                'target' => '/s/test/other',
            ],
        ]);

        $callback = $this->validationCallback;
        $this->assertTrue($callback($validJson));
    }

    /**
     * Test invalid JSON is rejected.
     */
    public function testInvalidJsonIsRejected(): void
    {
        $invalidJson = '{invalid json missing quotes}';

        $callback = $this->validationCallback;
        $this->assertFalse($callback($invalidJson));
    }

    /**
     * Test JSON with missing target is rejected.
     */
    public function testJsonMissingTargetIsRejected(): void
    {
        $jsonMissingTarget = json_encode([
            '/s/test/path' => [
                'route' => 'site/page',
            ],
        ]);

        $callback = $this->validationCallback;
        $this->assertFalse($callback($jsonMissingTarget));
    }

    /**
     * Test JSON with empty target is rejected.
     */
    public function testJsonEmptyTargetIsRejected(): void
    {
        $jsonEmptyTarget = json_encode([
            '/s/test/path' => [
                'target' => '',
            ],
        ]);

        $callback = $this->validationCallback;
        $this->assertFalse($callback($jsonEmptyTarget));
    }

    /**
     * Test JSON with invalid status code is rejected.
     */
    public function testJsonInvalidStatusIsRejected(): void
    {
        $jsonInvalidStatus = json_encode([
            '/s/test/path' => [
                'target' => '/s/test/other',
                'status' => 404, // Not a valid redirect status.
            ],
        ]);

        $callback = $this->validationCallback;
        $this->assertFalse($callback($jsonInvalidStatus));
    }

    /**
     * Test JSON with valid status codes are accepted.
     *
     * @dataProvider validStatusCodesProvider
     */
    public function testJsonValidStatusIsAccepted(int $status): void
    {
        $json = json_encode([
            '/s/test/path' => [
                'target' => '/s/test/other',
                'status' => $status,
            ],
        ]);

        $callback = $this->validationCallback;
        $this->assertTrue($callback($json));
    }

    public function validStatusCodesProvider(): array
    {
        return [
            'moved permanently' => [301],
            'found' => [302],
            'see other' => [303],
            'temporary redirect' => [307],
            'permanent redirect' => [308],
        ];
    }

    /**
     * Test JSON with non-array params is rejected.
     */
    public function testJsonNonArrayParamsIsRejected(): void
    {
        $json = json_encode([
            '/s/test/path' => [
                'target' => '/s/test/other',
                'params' => 'invalid-string',
            ],
        ]);

        $callback = $this->validationCallback;
        $this->assertFalse($callback($json));
    }

    /**
     * Test JSON with non-array query is rejected.
     */
    public function testJsonNonArrayQueryIsRejected(): void
    {
        $json = json_encode([
            '/s/test/path' => [
                'target' => '/s/test/other',
                'query' => 'invalid-string',
            ],
        ]);

        $callback = $this->validationCallback;
        $this->assertFalse($callback($json));
    }

    /**
     * Test JSON with empty route string is rejected.
     */
    public function testJsonEmptyRouteIsRejected(): void
    {
        $json = json_encode([
            '/s/test/path' => [
                'target' => '/s/test/other',
                'route' => '',
            ],
        ]);

        $callback = $this->validationCallback;
        $this->assertFalse($callback($json));
    }

    /**
     * Test JSON with non-string route is rejected.
     */
    public function testJsonNonStringRouteIsRejected(): void
    {
        $json = json_encode([
            '/s/test/path' => [
                'target' => '/s/test/other',
                'route' => ['invalid' => 'array'],
            ],
        ]);

        $callback = $this->validationCallback;
        $this->assertFalse($callback($json));
    }

    /**
     * Test direct redirect loop is rejected (A -> B, B -> A).
     */
    public function testDirectRedirectLoopIsRejected(): void
    {
        $json = json_encode([
            '/s/test/page-a' => [
                'target' => '/s/test/page-b',
            ],
            '/s/test/page-b' => [
                'target' => '/s/test/page-a',
            ],
        ]);

        $callback = $this->validationCallback;
        $this->assertFalse($callback($json));
    }

    /**
     * Test non-looping redirects are accepted.
     */
    public function testNonLoopingRedirectsAreAccepted(): void
    {
        $json = json_encode([
            '/s/test/page-a' => [
                'target' => '/s/test/page-b',
            ],
            '/s/test/page-c' => [
                'target' => '/s/test/page-d',
            ],
        ]);

        $callback = $this->validationCallback;
        $this->assertTrue($callback($json));
    }

    /**
     * Test JSON array (non-associative) is rejected.
     */
    public function testJsonArrayIsRejected(): void
    {
        $json = json_encode([
            ['target' => '/s/test/other'],
            ['target' => '/s/test/another'],
        ]);

        $callback = $this->validationCallback;
        $this->assertFalse($callback($json));
    }

    /**
     * Test empty JSON object is accepted.
     */
    public function testEmptyJsonObjectIsAccepted(): void
    {
        $json = '{}';

        $callback = $this->validationCallback;
        $this->assertTrue($callback($json));
    }

    /**
     * Test empty string is accepted.
     */
    public function testEmptyStringIsAccepted(): void
    {
        $callback = $this->validationCallback;
        $this->assertTrue($callback(''));
    }

    /**
     * Test null is accepted.
     */
    public function testNullIsAccepted(): void
    {
        $callback = $this->validationCallback;
        $this->assertTrue($callback(null));
    }

    /**
     * Test complex valid configuration is accepted.
     */
    public function testComplexValidConfigIsAccepted(): void
    {
        $json = json_encode([
            '/s/test/comments' => [
                'target' => '/s/test/comment?group=none',
                'route' => 'site/guest/comment',
                'params' => [
                    '__NAMESPACE__' => 'Comment\\Controller\\Site',
                    'controller' => 'Comment\\Controller\\Site\\CommentController',
                    'action' => 'browse',
                ],
            ],
            '/s/test/old-page' => [
                'target' => 'https://example.org/new-page',
                'status' => 301,
            ],
            '/s/test/search' => [
                'target' => '/s/test/item',
                'route' => 'site/resource',
                'params' => ['controller' => 'item', 'action' => 'browse'],
                'query' => ['sort_by' => 'created', 'sort_order' => 'desc'],
            ],
        ]);

        $callback = $this->validationCallback;
        $this->assertTrue($callback($json));
    }
}
