<?php

namespace Drupal\acquia_connector\Form;

use Drupal\acquia_connector\Client;
use Drupal\acquia_connector\ConnectorException;
use Drupal\acquia_connector\Helper\Storage;
use Drupal\acquia_connector\Subscription;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Class SetupForm.
 *
 * @package Drupal\acquia_connector\Form
 */
class SetupForm extends ConfigFormBase {

  /**
   * The Acquia client.
   *
   * @var \Drupal\acquia_connector\Client
   */
  protected $client;

  /**
   * Constructs a \Drupal\system\ConfigFormBase object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The factory for configuration objects.
   * @param \Drupal\acquia_connector\Client $client
   *   The Acquia client.
   */
  public function __construct(ConfigFactoryInterface $config_factory, Client $client) {
    $this->configFactory = $config_factory;
    $this->client = $client;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('acquia_connector.client')
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['acquia_connector.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'acquia_connector_automatic_setup_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $storage = $form_state->getStorage();
    if (empty($storage['choose'])) {
      return $this->buildSetupForm($form_state);
    }
    else {
      return $this->buildChooseForm($form_state);
    }
  }

  /**
   * Build setup form.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state.
   *
   * @return array
   *   Form.
   */
  protected function buildSetupForm(FormStateInterface &$form_state) {
    $form = [
      '#prefix' => $this->t('Log in or <a href=":url">configure manually</a> to connect your site to the Acquia Subscription.', [':url' => Url::fromRoute('acquia_connector.credentials')->toString()]),
      'email' => [
        '#type' => 'textfield',
        '#title' => $this->t('Enter the email address you use to login to the Acquia Subscription:'),
        '#required' => TRUE,
      ],
      'pass' => [
        '#type' => 'password',
        '#title' => $this->t('Enter your Acquia Subscription password:'),
        '#description' => $this->t('Your password will not be stored locally and will be sent securely to Acquia.com. <a href=":url" target="_blank">Forgot password?</a>', [
          ':url' => Url::fromUri('https://accounts.acquia.com/user/password')->getUri(),
        ]),
        '#size' => 32,
        '#required' => TRUE,
      ],
      'actions' => [
        '#type' => 'actions',
        'continue' => [
          '#type' => 'submit',
          '#value' => $this->t('Next'),
        ],
        'signup' => [
          '#markup' => $this->t('Need a subscription? <a href=":url">Get one</a>.', [
            ':url' => Url::fromUri('https://www.acquia.com/acquia-cloud-free')->getUri(),
          ]),
        ],
      ],
    ];
    return $form;
  }

  /**
   * Build choose form.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state.
   *
   * @return array
   *   Form.
   */
  protected function buildChooseForm(FormStateInterface &$form_state) {
    $options = [];
    $storage = $form_state->getStorage();
    foreach ($storage['response']['subscription'] as $credentials) {
      $options[] = $credentials['name'];
    }
    asort($options);

    $form = [
      '#prefix' => $this->t('You have multiple subscriptions available.'),
      'subscription' => [
        '#type' => 'select',
        '#title' => $this->t('Available subscriptions'),
        '#options' => $options,
        '#description' => $this->t('Choose from your available subscriptions.'),
        '#required' => TRUE,
      ],
      'continue' => [
        '#type' => 'submit',
        '#value' => $this->t('Submit'),
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $storage = $form_state->getStorage();
    if (!isset($storage['choose'])) {
      try {
        $response = $this->client->getSubscriptionCredentials($form_state->getValue('email'), $form_state->getValue('pass'));
      }
      catch (ConnectorException $e) {
        // Set form error to prevent switching to the next page.
        if ($e->isCustomized()) {
          $form_state->setErrorByName('', $e->getCustomMessage());
        }
        else {
          $this->getLogger('acquia connector')->error($e->getMessage());
          $form_state->setErrorByName('', $this->t("Can't connect to the Acquia Subscription."));
        }
      }
      if (!empty($response)) {
        $storage['response'] = $response;
      }
    }

    $form_state->setStorage($storage);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $form_data = $form_state->getStorage();
    if (isset($form_data['choose']) && isset($form_data['response']['subscription'][$form_state->getValue('subscription')])) {
      $config = $this->config('acquia_connector.settings');
      $sub = $form_data['response']['subscription'][$form_state->getValue('subscription')];
      $config->set('subscription_name', $sub['name'])->save();

      $storage = new Storage();
      $storage->setKey($sub['key']);
      $storage->setIdentifier($sub['identifier']);
    }
    else {
      $this->automaticStartSubmit($form_state);
    }

    // Don't set message or redirect if multistep.
    if (!$form_state->getErrors() && $form_state->isRebuilding() === FALSE) {
      // Check subscription and send a heartbeat to Acquia via XML-RPC.
      // Our status gets updated locally via the return data.
      $subscription = new Subscription();
      $subscription_data = $subscription->update();

      // Redirect to the path without the suffix.
      if ($subscription_data) {
        $form_state->setRedirect('acquia_connector.settings');
      }

      if ($subscription_data['active']) {
        $this->messenger()->addStatus($this->t('<h3>Connection successful!</h3>You are now connected to Acquia Cloud. Please enter a name for your site to begin sending profile data.'));
        drupal_flush_all_caches();
      }
    }
  }

  /**
   * Submit automatically if one subscription found.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state.
   */
  protected function automaticStartSubmit(FormStateInterface &$form_state) {
    $config = $this->config('acquia_connector.settings');
    $storage = $form_state->getStorage();
    if (empty($storage['response']['subscription'])) {
      $this->messenger()->addError($this->t('No subscriptions were found for your account.'));
    }
    elseif (count($storage['response']['subscription']) > 1) {
      // Multistep form for choosing from available subscriptions.
      $storage['choose'] = TRUE;
      // Force rebuild with next step.
      $form_state->setRebuild(TRUE);
      $form_state->setStorage($storage);
    }
    else {
      // One subscription so set id/key pair.
      $sub = $storage['response']['subscription'][0];
      $config->set('subscription_name', $sub['name'])->save();

      $storage = new Storage();
      $storage->setKey($sub['key']);
      $storage->setIdentifier($sub['identifier']);
    }
  }

}
