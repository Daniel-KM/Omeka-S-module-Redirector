<?php declare(strict_types=1);

namespace Redirector\Form;

use Common\Form\Element as CommonElement;
use Laminas\Form\Element;
use Laminas\Form\Fieldset;
use Laminas\InputFilter\InputFilterProviderInterface;
use Omeka\Form\Element as OmekaElement;

class SiteSettingsFieldset extends Fieldset implements InputFilterProviderInterface
{
    /**
     * @var string
     */
    protected $label = 'Redirector'; // @translate

    /**
     * @var array
     */
    protected $elementGroups = [
        'redirector' => 'Redirector', // @translate
    ];

    public function init(): void
    {
        $this
            ->setAttribute('id', 'redirector')
            ->setOption('element_groups', $this->elementGroups)

            ->add([
                'name' => 'redirector_redirections',
                'type' => OmekaElement\ArrayTextarea::class,
                'options' => [
                    'element_group' => 'redirector',
                    'label' => 'Simple redirections from any resource (id = page slug or url)', // @translate
                    'info' => 'Format: left part is a resource id; right part is a page slug or absolute/relative url.', // @translate
                    'as_key_value' => true,
                ],
                'attributes' => [
                    'id' => 'redirector_redirections',
                    'rows' => 6,
                    'placeholder' => <<<'TXT'
                        151 = events
                        2024 = /s/my-site/page/my-page
                        40101 = https://omeka.org/s
                        TXT,
                ],
            ])

            ->add([
                'name' => 'redirector_redirections_advanced',
                'type' => Element\Textarea::class,
                'options' => [
                    'element_group' => 'redirector',
                    'label' => 'Advanced redirections (JSON)', // @translate
                    'info' => 'JSON: route or key => { "target": "...", "route": "site/page", "params": {...}, "query": {...}, "status": 302 }. Redirect is internal when status is not set.', // @translate
                ],
                'attributes' => [
                    'id' => 'redirector_redirections_advanced',
                    'rows' => 12,
                    'placeholder' => <<<'JSON'
                        {
                            "/s/fr/old-path": {
                                "target": "/s/fr/new-path?ref=old",
                                "status": 302
                            },
                            "/s/my-site/legacy": {
                                "target": "/s/my-site/search?fulltext=abc"
                            },
                            "/s/fr/guest/contributions": {
                                "target": "/s/fr/guest/comment?group=a-identifier",
                                "route": "site/guest/comment",
                                 "params": {
                                       "__NAMESPACE__": "Comment\\Controller\\Site",
                                       "controller": "Comment\\Controller\\Site\\CommentController",
                                       "action": "browse"
                                  }
                            },
                            "site/resource-id": {
                                "target": "/s/{site-slug}/item/{id}",
                                "query": { "lang": "fr" },
                                "status": 302
                            },
                            "site/item-set": {
                                "target": "events",
                                "route": "site/page",
                                "params": { "page-slug": "events" }
                            }
                        }
                        JSON,
                ],
            ])

            ->add([
                'name' => 'redirector_check_rights',
                'type' => CommonElement\OptionalCheckbox::class,
                'options' => [
                    'element_group' => 'redirector',
                    'label' => 'Check rights to view resource before redirection', // @translate
                ],
                'attributes' => [
                    'id' => 'redirector_check_rights',
                ],
            ])
        ;
    }

    public function getInputFilterSpecification(): array
    {
        return [
            'redirector_redirections' => [
                'required' => false,
                'filters' => [
                    ['name' => 'StripTags'],
                    ['name' => 'StringTrim'],
                ],
            ],
            'redirector_redirections_advanced' => [
                'required' => false,
                'filters' => [
                    ['name' => 'StringTrim'],
                ],
                'validators' => [
                    [
                        'name' => 'Laminas\Validator\Json',
                        'options' => [
                            'messages' => [
                                'INVALID' => 'Value is not JSON.', // @translate
                                'INVALID_JSON' => 'Malformed JSON string.', // @translate
                            ],
                        ],
                    ],
                    [
                        'name' => 'Callback',
                        'options' => [
                            'callback' => function ($value) {
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
                                $sources = array_keys($decoded);
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
                            },
                            'messages' => [
                                \Laminas\Validator\Callback::INVALID_VALUE => 'JSON must map keys to objects with at least a non-empty "target". Routes must be non-empty strings. No redirect loops allowed.', // @translate
                            ],
                        ],
                    ],
                ],
            ],
            'redirector_check_rights' => [
                'required' => false,
            ],
        ];
    }
}
