<?php

namespace Drupal\commerce_lunar\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayBase;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Entity\PaymentMethodInterface;
use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\commerce_payment\PaymentMethodTypeManager;
use Drupal\commerce_payment\PaymentTypeManager;
use Drupal\commerce_price\Price;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * 
 */
abstract class LunarGatewayBase extends OffsitePaymentGatewayBase implements LunarInterface
{
  const INTENT_ID_KEY = '_lunar_intent_id';

  protected $paymentMethodCode = '';
  protected $apiClient;

  protected $testMode = false;
  protected $isMobilePay = false;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    EntityTypeManagerInterface $entity_type_manager,
    PaymentTypeManager $payment_type_manager,
    PaymentMethodTypeManager $payment_method_type_manager,
    TimeInterface $time
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $entity_type_manager, $payment_type_manager, $payment_method_type_manager, $time);

    $this->testMode = !!$_COOKIE['lunar_testmode'];
    $this->apiClient = new \Lunar\Lunar($this->configuration['app_key'], null, $this->testMode);
    $this->isMobilePay = strstr($this->paymentMethodCode, 'mobilePay');
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration()
  {
    return [
      'collect_billing_information' => true,
      'capture_mode' => 'delayed',
      'description' => 'Secure payment with ' . ($this->isMobilePay ? 'MobilePay' : 'card') . ' via © Lunar',
      'shop_title' => \Drupal::config('system.site')->get('name'),
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state)
  {
    $form = parent::buildConfigurationForm($form, $form_state);

    $formFields = [
      'mode' => null, // remove this setting
      'collect_billing_information' => null, // remove this setting
      'app_key' => [
        '#type' => 'textfield',
        '#title' => $this->t('App key'),
        '#default_value' => $this->configuration['app_key'],
        '#required' => TRUE,
      ],
      'public_key' => [
        '#type' => 'textfield',
        '#title' => $this->t('Public key'),
        '#default_value' => $this->configuration['public_key'],
        '#required' => TRUE,
      ],
    ];

    if ($this->isMobilePay) {
      $formFields = array_merge($formFields, [
        'configuration_id' => [
          '#type' => 'textfield',
          '#title' => $this->t('Configuration ID'),
          '#default_value' => $this->configuration['configuration_id'],
          '#required' => TRUE,
        ],
      ]);
    }

    $formFields = array_merge($formFields, [
      'logo_url' => [
        '#type' => 'textfield',
        '#title' => $this->t('Logo URL'),
        '#default_value' => $this->configuration['logo_url'],
        '#required' => TRUE,
      ],
      'capture_mode' => [
        '#type' => 'radios',
        '#title' => $this->t('Capture mode'),
        '#options' => [
          'delayed' => $this->t('Delayed'),
          'instant' => $this->t('Instant'),
        ],
        '#default_value' => $this->configuration['capture_mode'],
        '#description' => $this->t('If you deliver your product instantly (e.g. a digital product), choose Instant mode. If not, use Delayed.'),
      ],
      'description' => [
        '#type' => 'textarea',
        '#title' => $this->t('Payment method description'),
        '#default_value' => $this->configuration['description'],
        '#description' => $this->t('The description will appear on checkout page.'),
      ],
      'shop_title' => [
        '#type' => 'textfield',
        '#title' => $this->t('Shop title'),
        '#default_value' => $this->configuration['shop_title'],
        '#description' => $this->t('The title will appear on the hosted checkout page where the user is redirected. Leave blank to show the site name.'),
      ]
    ]);

    return array_merge($form, $formFields);
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state)
  {
    parent::submitConfigurationForm($form, $form_state);

    if (!$form_state->getErrors()) {
      $values = $form_state->getValue($form['#parents']);

      $this->configuration['app_key'] = $values['app_key'];
      $this->configuration['public_key'] = $values['public_key'];
      $this->configuration['logo_url'] = $values['logo_url'];
      $this->configuration['capture_mode'] = $values['capture_mode'];
      $this->configuration['description'] = $values['description'];
      $this->configuration['shop_title'] = $values['shop_title'];

      $this->configuration['collect_billing_information'] = $this->defaultConfiguration()['collect_billing_information'];

      if ($this->isMobilePay) {
        $this->configuration['configuration_id'] = $values['configuration_id'];
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function onReturn(OrderInterface $order, Request $request)
  {
    $logger = \Drupal::logger('commerce_lunar');

    $payment_intent_id = $order->data->{self::INTENT_ID_KEY};

    try {
      $this->apiClient->payments()->fetch($payment_intent_id);
    } catch (\Lunar\Exception\ApiException $e) {
      \Drupal::logger('commerce_lunar')->warning($e->getMessage());
      throw new PaymentGatewayException($this->t('Transaction @id not found. @message', [
        '@id' => $payment_intent_id, 
        '@message' => $e->getMessage()]
      ));
    }

    $payment_storage = $this->entityTypeManager->getStorage('commerce_payment');
    /** @var PaymentInterface $payment */
    $payment = $payment_storage->create([
      'state' => 'authorization',
      'amount' => $order->getBalance(),
      'payment_gateway' => $this->parentEntity->id(),
      'payment_gateway_mode' => $this->testMode ? 'test' : null,
      'order_id' => $order->id(),
      'remote_id' => $payment_intent_id,
      'remote_state' => $request->query->get('payment_status'),
    ]);

    $logger->info('Saving Payment information. Transaction reference: ' . $payment_intent_id);

    $payment->save();

    drupal_set_message('Payment was processed');

    if ('instant' === $this->configuration['capture_mode']) {
      $this->capturePayment($payment);
    }

    $logger->info('Payment information saved successfully. Transaction reference: ' . $payment_intent_id);
  }

  /**
   * {@inheritdoc}
   */
  public function capturePayment(PaymentInterface $payment, Price $amount = NULL)
  {
    $this->assertPaymentState($payment, ['authorization']);
    // If not specified, capture the entire amount.
    $amount = $amount ?: $payment->getAmount();

    $payment_intent_id = $payment->getRemoteId();
    try {
      $apiResponse = $this->apiClient->payments()->capture($payment_intent_id, [
        'amount' => [
          'currency' => $amount->getCurrencyCode(),
          'decimal' => (string) $amount->getNumber(),
        ]
      ]);

      if ('completed' != $apiResponse['captureState']) {
        throw new PaymentGatewayException($apiResponse['declinedReason']['error'] ?? 'Capture failed');
      }

      $payment->setState('completed');
      $payment->setAmount($amount);
      $payment->save();
    } catch (\Lunar\Exception\ApiException $e) {
      \Drupal::logger('commerce_lunar')->warning($e->getMessage());
      throw new PaymentGatewayException($this->t('Capture failed. Transaction @id. @message', ['@id' => $payment_intent_id, '@message' => $e->getMessage()]));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function voidPayment(PaymentInterface $payment)
  {
    $this->assertPaymentState($payment, ['authorization']);
    $payment_intent_id = $payment->getRemoteId();
    $amount = $payment->getAmount();
    try {
      $apiResponse = $this->apiClient->payments()->cancel($payment_intent_id,  [
        'amount' => [
          'currency' => $amount->getCurrencyCode(),
          'decimal' => (string) $amount->getNumber(),
        ]
      ]);

      if ('completed' != $apiResponse['cancelState']) {
        throw new PaymentGatewayException($apiResponse['declinedReason']['error'] ?? 'Cancel failed');
      }

      $payment->setState('authorization_voided');
      $payment->save();
    } catch (\Lunar\Exception\ApiException $e) {
      \Drupal::logger('commerce_lunar')->warning($e->getMessage());
      throw new PaymentGatewayException($this->t('Capture failed. Transaction @id. @message', ['@id' => $payment_intent_id, '@message' => $e->getMessage()]));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function refundPayment(PaymentInterface $payment, Price $amount = NULL)
  {
    $this->assertPaymentState($payment, ['completed', 'partially_refunded']);
    // If not specified, refund the entire amount.
    $amount = $amount ?: $payment->getAmount();
    $this->assertRefundAmount($payment, $amount);

    $payment_intent_id = $payment->getRemoteId();
    try {
      $apiResponse = $this->apiClient->payments()->refund($payment_intent_id,  [
        'amount' => [
          'currency' => $amount->getCurrencyCode(),
          'decimal' => (string) $amount->getNumber(),
        ]
      ]);

      if ('completed' != $apiResponse['refundState']) {
        throw new PaymentGatewayException($apiResponse['declinedReason']['error'] ?? 'Refund failed');
      }

      $old_refunded_amount = $payment->getRefundedAmount();
      $new_refunded_amount = $old_refunded_amount->add($amount);
      if ($new_refunded_amount->lessThan($payment->getAmount())) {
        $payment->setState('partially_refunded');
      } else {
        $payment->setState('refunded');
      }
      $payment->setRefundedAmount($new_refunded_amount);
      $payment->save();
    } catch (\Lunar\Exception\ApiException $e) {
      \Drupal::logger('commerce_lunar')->warning($e->getMessage());
      throw new PaymentGatewayException($this->t('Refund failed. Transaction @id. @message', ['@id' => $payment_intent_id, '@message' => $e->getMessage()]));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function deletePaymentMethod(PaymentMethodInterface $payment_method)
  {
    $payment_method->delete();
  }
}
