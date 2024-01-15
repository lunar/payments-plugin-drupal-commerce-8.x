<?php

namespace Drupal\commerce_lunar\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Entity\PaymentMethodInterface;
use Drupal\commerce_payment\Exception\InvalidRequestException;
use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\commerce_payment\PaymentMethodTypeManager;
use Drupal\commerce_payment\PaymentTypeManager;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayBase;
use Drupal\commerce_price\Price;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;

/**
 * Provides the Off-site payment gateway.
 *
 * @CommercePaymentGateway(
 *   id = "lunar",
 *   label = "Lunar Card",
 *   display_label = "Lunar Card",
 *   forms = {
 *     "offsite-payment" = "\Drupal\commerce_lunar\PluginForm\PaymentLunarForm",
 *   },
 * )
 */
class Lunar extends OffsitePaymentGatewayBase implements LunarInterface
{
  protected $paymentMethodCode = 'card';
  private $apiClient;

  private $testMode = false;
  private $isMobilePay = false;

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
      'mode' => 'live',
      'transaction_type' => 'delayed',
      'description' => 'Secure payment with '.($this->isMobilePay ? 'MobilePay' : 'card'). ' via © Lunar',
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
      'logo_url' => [
        '#type' => 'textfield',
        '#title' => $this->t('Logo URL'),
        '#default_value' => $this->configuration['logo_url'],
        '#required' => TRUE,
      ]
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
      'transaction_type' => [
        '#type' => 'radios',
        '#title' => $this->t('Transaction type'),
        '#options' => [
          'delayed' => $this->t('Delayed'),
          'instant' => $this->t('Instant'),
        ],
        '#default_value' => $this->configuration['transaction_type'],
        '#description' => $this->t('For electronic products you can choose Instant. The funds are captured right after order completion. Choose delayed otherwise.'),
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
      $this->configuration['transaction_type'] = $values['transaction_type'];
      $this->configuration['description'] = $values['description'];
      $this->configuration['shop_title'] = $values['shop_title'];

      $this->configuration['mode'] = $this->defaultConfiguration()['mode'];
      
      if ($this->isMobilePay) {
        $this->configuration['configuration_id'] = $values['configuration_id'];
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function createPayment(PaymentInterface $payment, $capture = TRUE)
  {
    $this->assertPaymentState($payment, ['new']);
    $payment_method = $payment->getPaymentMethod();
    assert($payment_method instanceof PaymentMethodInterface);
    $this->assertPaymentMethod($payment_method);
    $order = $payment->getOrder();
    assert($order instanceof OrderInterface);

    $amount = $payment->getAmount();
    $remote_id = $payment_method->getRemoteId();
    $payment->setState('authorization');
    $payment->setRemoteId($remote_id);
    $payment->save();

    if ($capture) {
      $this->capturePayment($payment, $amount);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function capturePayment(PaymentInterface $payment, Price $amount = NULL)
  {
    $this->assertPaymentState($payment, ['authorization']);
    // If not specified, capture the entire amount.
    $amount = (string) $amount ?: $payment->getAmount();

    $remote_id = $payment->getRemoteId();
    try {
      $apiResponse = $this->apiClient->payments()->capture($remote_id, [
        'amount' => [
          'currency' => $payment->getOrder()->getTotalPrice()->getCurrencyCode(),
          'decimal' => $amount,
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
      throw new PaymentGatewayException($this->t('Capture failed. Transaction @id. @message', ['@id' => $remote_id, '@message' => $e->getMessage()]));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function voidPayment(PaymentInterface $payment)
  {
    $this->assertPaymentState($payment, ['authorization']);
    $remote_id = $payment->getRemoteId();
    $amount = (string) $payment->getAmount();
    try {
      $apiResponse = $this->apiClient->payments()->cancel($remote_id,  [
        'amount' => [
          'currency' => $payment->getOrder()->getTotalPrice()->getCurrencyCode(),
          'decimal' => $amount,
        ]
      ]);

      if ('completed' != $apiResponse['cancelState']) {
        throw new PaymentGatewayException($apiResponse['declinedReason']['error'] ?? 'Cancel failed');
      }

      $payment->setState('authorization_voided');
      $payment->save();

    } catch (\Lunar\Exception\ApiException $e) {
      \Drupal::logger('commerce_lunar')->warning($e->getMessage());
      throw new PaymentGatewayException($this->t('Capture failed. Transaction @id. @message', ['@id' => $remote_id, '@message' => $e->getMessage()]));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function refundPayment(PaymentInterface $payment, Price $amount = NULL)
  {
    $this->assertPaymentState($payment, ['completed', 'partially_refunded']);
    // If not specified, refund the entire amount.
    $amount = (string) $amount ?: $payment->getAmount();
    $this->assertRefundAmount($payment, $amount);

    $remote_id = $payment->getRemoteId();
    try {
      $apiResponse = $this->apiClient->payments()->refund($remote_id,  [
        'amount' => [
          'currency' => $payment->getOrder()->getTotalPrice()->getCurrencyCode(),
          'decimal' => $amount,
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
      throw new PaymentGatewayException($this->t('Refund failed. Transaction @id. @message', ['@id' => $remote_id, '@message' => $e->getMessage()]));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function createPaymentMethod(PaymentMethodInterface $payment_method, array $payment_details)
  {
    // create payment intent ?

    $transaction = $this->getTransaction($payment_details['lunar_transaction_id']);
    if (isset($transaction['payment'])) {
      $payment_method->setRemoteId($transaction['id']);
      $payment_method->save();
    } else {
      throw new PaymentGatewayException($this->t('Transaction failed. Transaction @id.', ['@id' => $transaction['id']]));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function deletePaymentMethod(PaymentMethodInterface $payment_method)
  {
    $payment_method->delete();
  }

  /**
   * @return array
   */
  protected function getTransaction($transactionId)
  {
    try {
      return $this->apiClient->payments()->fetch($transactionId);
    } catch (\Lunar\Exception\ApiException $e) {
      \Drupal::logger('commerce_lunar')->warning($e->getMessage());
      throw new InvalidRequestException($this->t('Transaction @id not found. @message', ['@id' => $transactionId, '@message' => $e->getMessage()]));
    }
  }
  
  /**
   * @return array
   */
  protected function getAddressInfo(Order $order) {
    $entity_manager = \Drupal::entityTypeManager();
    $billingProfile = $order->getBillingProfile();

    if (!$billingProfile) {
      $customer = $order->getCustomer();
      $billingProfile = $entity_manager->getStorage('profile')->loadDefaultByUser($customer, 'customer');
    }

    $data = [
      'address' => '',
      'name' => '',
    ];

    if ($billingProfile) {
      $addressInfo = array($billingProfile->get('address')->first());
      $data['address'] = implode(', ', array_filter([
        $addressInfo['postal_code'],
        $addressInfo['country_code'],
        $addressInfo['administrative_area'],
        $addressInfo['locality'],
        $addressInfo['address_line1'],
        $addressInfo['address_line2'],
      ]));
      $data['name'] = implode(' ', array_filter([
        $addressInfo['family_name'],
        $addressInfo['given_name'],
      ]));
    }

    return $data;
  }
}
