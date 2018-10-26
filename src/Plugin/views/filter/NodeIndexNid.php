<?php

namespace Drupal\exposed_filter_entity_dropdown\Plugin\views\filter;

use Drupal\Core\Entity\Element\EntityAutocomplete;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\node\Entity\Node;
use Drupal\node\NodeStorageInterface;
use Drupal\views\Plugin\views\display\DisplayPluginBase;
use Drupal\views\Plugin\views\filter\ManyToOne;
use Drupal\views\ViewExecutable;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Filter by node id.
 *
 * @ingroup views_filter_handlers
 *
 * @ViewsFilter("node_index_nid")
 */
class NodeIndexNid extends ManyToOne
{

    // Stores the exposed input for this filter.
    public $validated_exposed_input = null;

    /**
     * The node storage.
     *
     * @var \Drupal\node\NodeStorageInterface
     */
    protected $nodeStorage;

    /**
     * The entity storage.
     *
     * @var \Drupal\node\EntityStorageInterface
     */
    protected $nodeTypeStorage;

    /**
     * Constructs a NodeIndexNid object.
     *
     * @param array $configuration
     *   A configuration array containing information about the plugin instance.
     * @param string $plugin_id
     *   The plugin_id for the plugin instance.
     * @param mixed $plugin_definition
     *   The plugin implementation definition.
     * @param \Drupal\Core\Entity\EntityStorageInterface $node_type_storage
     *   The node storage.
     * @param \Drupal\node\NodeStorageInterface $node_storage
     *   The node storage.
     */
    public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityStorageInterface $node_type_storage, NodeStorageInterface $node_storage)
    {
        parent::__construct($configuration, $plugin_id, $plugin_definition);
        $this->nodeTypeStorage = $node_type_storage;
        $this->nodeStorage = $node_storage;
    }

    /**
     * {@inheritdoc}
     */
    public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition)
    {
        return new static(
            $configuration,
            $plugin_id,
            $plugin_definition,
            $container->get('entity.manager')->getStorage('node_type'),
            $container->get('entity.manager')->getStorage('node')
        );
    }

    /**
     * {@inheritdoc}
     */
    public function init(ViewExecutable $view, DisplayPluginBase $display, array &$options = null)
    {
        parent::init($view, $display, $options);
        if (!empty($this->definition['content_type'])) {
            $this->options['ctypeid'] = $this->definition['content_type'];
        }
    }

    public function hasExtraOptions()
    {
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function getValueOptions()
    {
        return $this->valueOptions;
    }

    protected function defineOptions()
    {
        $options = parent::defineOptions();
        $options['type'] = ['default' => 'textfield'];
        $options['limit'] = ['default' => true];
        $options['ctypeid'] = ['default' => ''];
        $options['error_message'] = ['default' => true];

        return $options;
    }

    public function buildExtraOptionsForm(&$form, FormStateInterface $form_state)
    {
       $contentTypes = $this->nodeTypeStorage->loadMultiple();
        $options = [];
        foreach ($contentTypes as $ctype) {
            $options[$ctype->id()] = $ctype->label();
        }

        if ($this->options['limit']) {
            // We only do this when the form is displayed.
            if (empty($this->options['ctypeid'])) {
                $first_content_type = reset($contentTypes);
                $this->options['ctypeid'] = $first_content_type->id();
            }

            if (empty($this->definition['content_type'])) {
                $form['ctypeid'] = [
                    '#type' => 'radios',
                    '#title' => $this->t('Content type'),
                    '#options' => $options,
                    '#description' => $this->t('Select which content type to show nodes for in the regular options.'),
                    '#default_value' => $this->options['ctypeid'],
                ];
            }
        }

        $form['type'] = [
            '#type' => 'radios',
            '#title' => $this->t('Selection type'),
            '#options' => ['select' => $this->t('Dropdown'), 'textfield' => $this->t('Autocomplete')],
            '#default_value' => $this->options['type'],
        ];
    }

    protected function valueForm(&$form, FormStateInterface $form_state)
    {

        $contentType = $this->nodeTypeStorage->load($this->options['ctypeid']);
        if (empty($contentType) && $this->options['limit']) {
            $form['markup'] = [
                '#markup' => '<div class="js-form-item form-item">' . $this->t('An invalid content type is selected. Please change it in the options.') . '</div>',
            ];
            return;
        }

        if ($this->options['type'] == 'textfield') {
            $nodes = $this->value ? Node::loadMultiple(($this->value)) : [];
            $form['value'] = [
                '#title' => $this->options['limit'] ? $this->t('Select nodes from content type @ctype', ['@ctype' => $contentType->label()]) : $this->t('Select nodes'),
                '#type' => 'textfield',
                '#default_value' => EntityAutocomplete::getEntityLabels($nodes),
            ];

            if ($this->options['limit']) {
                $form['value']['#type'] = 'entity_autocomplete';
                $form['value']['#target_type'] = 'node';
                $form['value']['#selection_settings']['target_bundles'] = [$contentType->id()];
                $form['value']['#tags'] = true;
                $form['value']['#process_default_value'] = false;
            }
        } else {
            $options = [];
            $query = \Drupal::entityQuery('node')
            // @todo Sorting on content type properties -
            //   https://www.drupal.org/node/1821274.
                ->sort('title')
                ->addTag('node_access');
            if ($this->options['limit']) {
                $query->condition('type', $contentType->id());
            }
            $nodes = Node::loadMultiple($query->execute());
            foreach ($nodes as $node) {
                $options[$node->id()] = \Drupal::entityManager()->getTranslationFromContext($node)->label();
            }

            $default_value = (array) $this->value;

            if ($exposed = $form_state->get('exposed')) {
                $identifier = $this->options['expose']['identifier'];

                if (!empty($this->options['expose']['reduce'])) {
                    $options = $this->reduceValueOptions($options);

                    if (!empty($this->options['expose']['multiple']) && empty($this->options['expose']['required'])) {
                        $default_value = [];
                    }
                }

                if (empty($this->options['expose']['multiple'])) {
                    if (empty($this->options['expose']['required']) && (empty($default_value) || !empty($this->options['expose']['reduce']))) {
                        $default_value = 'All';
                    } elseif (empty($default_value)) {
                        $keys = array_keys($options);
                        $default_value = array_shift($keys);
                    }
                    // Due to #1464174 there is a chance that array('') was saved in the admin ui.
                    // Let's choose a safe default value.
                    elseif ($default_value == ['']) {
                        $default_value = 'All';
                    } else {
                        $copy = $default_value;
                        $default_value = array_shift($copy);
                    }
                }
            }
            $form['value'] = [
                '#type' => 'select',
                '#title' => $this->options['limit'] ? $this->t('Select nodes from content type @ctype', ['@ctype' => $contentType->label()]) : $this->t('Select nodes'),
                '#multiple' => true,
                '#options' => $options,
                '#size' => min(9, count($options)),
                '#default_value' => $default_value,
            ];

            $user_input = $form_state->getUserInput();
            if ($exposed && isset($identifier) && !isset($user_input[$identifier])) {
                $user_input[$identifier] = $default_value;
                $form_state->setUserInput($user_input);
            }
        }

        if (!$form_state->get('exposed')) {
            // Retain the helper option
            $this->helper->buildOptionsForm($form, $form_state);

            // Show help text if not exposed to end users.
            $form['value']['#description'] = t('Leave blank for all. Otherwise, the first selected node will be the default instead of "Any".');
        }
    }

    protected function valueValidate($form, FormStateInterface $form_state)
    {
        // We only validate if they've chosen the text field style.
        if ($this->options['type'] != 'textfield') {
            return;
        }

        $nids = [];
        if ($values = $form_state->getValue(['options', 'value'])) {
            foreach ($values as $value) {
                $nids[] = $value['target_id'];
            }
        }
        $form_state->setValue(['options', 'value'], $nids);
    }

    public function acceptExposedInput($input)
    {
        if (empty($this->options['exposed'])) {
            return true;
        }
        // We need to know the operator, which is normally set in
        // \Drupal\views\Plugin\views\filter\FilterPluginBase::acceptExposedInput(),
        // before we actually call the parent version of ourselves.
        if (!empty($this->options['expose']['use_operator']) && !empty($this->options['expose']['operator_id']) && isset($input[$this->options['expose']['operator_id']])) {
            $this->operator = $input[$this->options['expose']['operator_id']];
        }

        // If view is an attachment and is inheriting exposed filters, then assume
        // exposed input has already been validated
        if (!empty($this->view->is_attachment) && $this->view->display_handler->usesExposed()) {
            $this->validated_exposed_input = (array) $this->view->exposed_raw_input[$this->options['expose']['identifier']];
        }

        // If we're checking for EMPTY or NOT, we don't need any input, and we can
        // say that our input conditions are met by just having the right operator.
        if ($this->operator == 'empty' || $this->operator == 'not empty') {
            return true;
        }

        // If it's non-required and there's no value don't bother filtering.
        if (!$this->options['expose']['required'] && empty($this->validated_exposed_input)) {
            return false;
        }

        $rc = parent::acceptExposedInput($input);
        if ($rc) {
            // If we have previously validated input, override.
            if (isset($this->validated_exposed_input)) {
                $this->value = $this->validated_exposed_input;
            }
        }

        return $rc;
    }

    public function validateExposed(&$form, FormStateInterface $form_state)
    {
        if (empty($this->options['exposed'])) {
            return;
        }

        $identifier = $this->options['expose']['identifier'];

        // We only validate if they've chosen the text field style.
        if ($this->options['type'] != 'textfield') {
            if ($form_state->getValue($identifier) != 'All') {
                $this->validated_exposed_input = (array) $form_state->getValue($identifier);
            }
            return;
        }

        if (empty($this->options['expose']['identifier'])) {
            return;
        }

        if ($values = $form_state->getValue($identifier)) {
            foreach ($values as $value) {
                $this->validated_exposed_input[] = $value['target_id'];
            }
        }
    }

    protected function valueSubmit($form, FormStateInterface $form_state)
    {
        // prevent array_filter from messing up our arrays in parent submit.
    }

    public function buildExposeForm(&$form, FormStateInterface $form_state)
    {
        parent::buildExposeForm($form, $form_state);
        if ($this->options['type'] != 'select') {
            unset($form['expose']['reduce']);
        }
        $form['error_message'] = [
            '#type' => 'checkbox',
            '#title' => $this->t('Display error message'),
            '#default_value' => !empty($this->options['error_message']),
        ];
    }

    public function adminSummary()
    {
        // set up $this->valueOptions for the parent summary
        $this->valueOptions = [];

        if ($this->value) {
            $this->value = array_filter($this->value);
            $nodes = Node::loadMultiple($this->value);
            foreach ($nodes as $node) {
                $this->valueOptions[$node->id()] = \Drupal::entityManager()->getTranslationFromContext($node)->label();
            }
        }
        return parent::adminSummary();
    }

    /**
     * {@inheritdoc}
     */
    public function getCacheContexts()
    {
        $contexts = parent::getCacheContexts();
        // The result potentially depends on node access and so is just cacheable
        // per user.
        // @todo See https://www.drupal.org/node/2352175.
        $contexts[] = 'user';

        return $contexts;
    }

    /**
     * {@inheritdoc}
     */
    public function calculateDependencies()
    {
        $dependencies = parent::calculateDependencies();

        $contentType = $this->nodeTypeStorage->load($this->options['ctypeid']);
        $dependencies[$contentType->getConfigDependencyKey()][] = $contentType->getConfigDependencyName();

        foreach ($this->nodeStorage->loadMultiple($this->options['value']) as $node) {
            $dependencies[$node->getConfigDependencyKey()][] = $node->getConfigDependencyName();
        }

        return $dependencies;
    }

}
