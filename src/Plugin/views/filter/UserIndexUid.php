<?php

namespace Drupal\exposed_filter_entity_dropdown\Plugin\views\filter;

use Drupal\Core\Entity\Element\EntityAutocomplete;
use Drupal\user\RoleStorageInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\user\Entity\User;
use Drupal\user\UserStorageInterface;
use Drupal\views\Plugin\views\display\DisplayPluginBase;
use Drupal\views\Plugin\views\filter\ManyToOne;
use Drupal\views\ViewExecutable;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Filter by user id.
 *
 * @ingroup views_filter_handlers
 *
 * @ViewsFilter("user_index_uid")
 */
class UserIndexUid extends ManyToOne
{

    // Stores the exposed input for this filter.
    public $validated_exposed_input = null;

    /**
     * The user storage.
     *
     * @var \Drupal\user\UserStorageInterface
     */
    protected $userStorage;

    /**
     * The role storage.
     *
     * @var \Drupal\user\RoleStorageInterface
     */
    protected $roleStorage;

    /**
     * Constructs a UserIndexNid object.
     *
     * @param array $configuration
     *   A configuration array containing information about the plugin instance.
     * @param string $plugin_id
     *   The plugin_id for the plugin instance.
     * @param mixed $plugin_definition
     *   The plugin implementation definition.
     * @param \Drupal\user\RoleStorageInterface $role_storage
     *   The role storage.
     * @param \Drupal\user\UserStorageInterface $user_storage
     *   The user storage.
     */
    public function __construct(array $configuration, $plugin_id, $plugin_definition, RoleStorageInterface $role_storage, UserStorageInterface $user_storage)
    {
        parent::__construct($configuration, $plugin_id, $plugin_definition);
        $this->roleStorage = $role_storage;
        $this->userStorage = $user_storage;
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
            $container->get('entity.manager')->getStorage('user_role'),
            $container->get('entity.manager')->getStorage('user')
        );
    }

    /**
     * {@inheritdoc}
     */
    public function init(ViewExecutable $view, DisplayPluginBase $display, array &$options = null)
    {
        parent::init($view, $display, $options);
        if (!empty($this->definition['role'])) {
            $this->options['roleid'] = $this->definition['role'];
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
        $options['roleid'] = ['default' => ''];
        $options['error_message'] = ['default' => true];
        return $options;
    }

    public function buildExtraOptionsForm(&$form, FormStateInterface $form_state)
    {
        $roles = $this->roleStorage->loadMultiple();
        $options = [];
        foreach ($roles as $role) {
            $options[$role->id()] = $role->label();
        }

        if ($this->options['limit']) {
            // We only do this when the form is displayed.
            if (empty($this->options['roleid'])) {
                $first_role = reset($roles);
                $this->options['roleid'] = $first_role->id();
            }

            if (empty($this->definition['role'])) {
                $form['roleid'] = [
                    '#type' => 'radios',
                    '#title' => $this->t('Role'),
                    '#options' => $options,
                    '#description' => $this->t('Select which role to show users for in the regular options.'),
                    '#default_value' => $this->options['roleid'],
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

        $role = $this->roleStorage->load($this->options['roleid']);
        if (empty($role) && $this->options['limit']) {
            $form['markup'] = [
                '#markup' => '<div class="js-form-item form-item">' . $this->t('An invalid role is selected. Please change it in the options.') . '</div>',
            ];
            return;
        }

        if ($this->options['type'] == 'textfield') {
            $users = $this->value ? User::loadMultiple(($this->value)) : [];
            $form['value'] = [
                '#title' => $this->options['limit'] ? $this->t('Select users from role @role', ['@role' => $role->label()]) : $this->t('Select users'),
                '#type' => 'textfield',
                '#default_value' => EntityAutocomplete::getEntityLabels($users),
            ];

            if ($this->options['limit']) {
                $form['value']['#type'] = 'entity_autocomplete';
                $form['value']['#target_type'] = 'user';
                $form['value']['#selection_settings']['target_bundles'] = [$role->id()];
                $form['value']['#tags'] = true;
                $form['value']['#process_default_value'] = false;
            }
        } else {
            $options = [];
            $query = \Drupal::entityQuery('user')
                ->sort('name');
            if ($this->options['limit']) {
                $query->condition('roles', $role->id());
            }
            $users = User::loadMultiple($query->execute());
            foreach ($users as $user) {
                $options[$user->id()] = \Drupal::entityManager()->getTranslationFromContext($user)->label();
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
                '#title' => $this->options['limit'] ? $this->t('Select users from role @role', ['@role' => $role->label()]) : $this->t('Select users'),
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
            $form['value']['#description'] = t('Leave blank for all. Otherwise, the first selected user will be the default instead of "Any".');
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
            $users = User::loadMultiple($this->value);
            foreach ($users as $user) {
                $this->valueOptions[$user->id()] = \Drupal::entityManager()->getTranslationFromContext($user)->label();
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

        $role = $this->roleStorage->load($this->options['roleid']);
        $dependencies[$role->getConfigDependencyKey()][] = $role->getConfigDependencyName();

        foreach ($this->userStorage->loadMultiple($this->options['value']) as $user) {
            $dependencies[$user->getConfigDependencyKey()][] = $user->getConfigDependencyName();
        }

        return $dependencies;
    }

}
