<?php declare(strict_types=1);

namespace Redirector\Form;

use Common\Form\Element as CommonElement;
use Laminas\Form\Fieldset;
use Omeka\Form\Element as OmekaElement;

class SiteSettingsFieldset extends Fieldset
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
            ->setAttribute('id', 'redierctor')
            ->setOption('element_groups', $this->elementGroups)
            ->add([
                'name' => 'redirector_redirections',
                'type' => OmekaElement\ArrayTextarea::class,
                'options' => [
                    'element_group' => 'redirector',
                    'label' => 'Redirections from any resource to any page or url', // @translate
                    'info' => 'Set the resource id, then the sign "=", then a page slug or a url, relative or absolute.', // @translate
                    'as_key_value' => true,
                ],
                'attributes' => [
                    'id' => 'redirector_redirections',
                    'placeholder' => <<<'TXT'
                        151 = events
                        411 = https://omeka.org/s
                        TXT,
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
}
