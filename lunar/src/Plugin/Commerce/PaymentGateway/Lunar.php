<?php

namespace Drupal\commerce_lunar\Plugin\Commerce\PaymentGateway;

use DateTime;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Entity\PaymentMethodInterface;
use Drupal\commerce_payment\Exception\InvalidRequestException;
use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\commerce_payment\PaymentMethodTypeManager;
use Drupal\commerce_payment\PaymentTypeManager;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OnsitePaymentGatewayBase;
use Drupal\commerce_price\Price;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use http\Exception\InvalidArgumentException;

/**
 * Provides the On-site payment gateway.
 *
 * @CommercePaymentGateway(
 *   id = "lunar",
 *   label = "Lunar",
 *   display_label = "Lunar",
 *   forms = {
 *     "add-payment-method" = "Drupal\commerce_lunar\PluginForm\Lunar\PaymentMethodAddForm",
 *   },
 *   payment_method_types = {"credit_card"},
 *   credit_card_types = {
 *     "amex", "dinersclub", "discover", "jcb", "maestro", "mastercard", "visa",
 *   },
 *   requires_billing_information = FALSE,
 * )
 */
class Lunar extends OnsitePaymentGatewayBase implements LunarInterface {

  /**
   * @var \Paylike\Paylike
   */
  protected $apiClient;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_type_manager, PaymentTypeManager $payment_type_manager, PaymentMethodTypeManager $payment_method_type_manager, TimeInterface $time) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $entity_type_manager, $payment_type_manager, $payment_method_type_manager, $time);
    $this->apiClient = new \Paylike\Paylike($this->getPrivateKey());
  }

  /**
   * {@inheritdoc}
   */
  public function getPublicKey() {
    return $this->configuration['public_key'];
  }

  /**
   * {@inheritdoc}
   */
  public function getPrivateKey() {
    return $this->configuration['private_key'];
  }

  /**
   * Returns payment method title.
   * @return string
   */
  public function getDisplayLabel() {
    return !empty($this->configuration['payment_method_title']) ? $this->configuration['payment_method_title'] : $this->configuration['display_label'];
  }

  /**
   * Returns payment method description.
   * @return string
   */
  public function getDescription() {
    return $this->configuration['payment_method_description'];
  }

  /**
   * Returns popup title.
   * @return string
   */
  public function getPopupTitle() {
    return !empty($this->configuration['popup_title']) ? $this->configuration['popup_title'] : \Drupal::config('system.site')->get('name');
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'public_key' => '',
      'private_key' => '',
      'payment_method_title' => '',
      'payment_method_description' => '',
      'popup_title' => '',
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    $form['public_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Public API key'),
      '#default_value' => $this->configuration['public_key'],
      '#required' => TRUE,
    ];
    $form['private_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Private API key'),
      '#default_value' => $this->configuration['private_key'],
      '#required' => TRUE,
    ];
    $form['payment_method_title'] = array(
      '#type' => 'textfield',
      '#title' => $this->t('Payment method title'),
      '#default_value' => $this->configuration['payment_method_title'],
      '#description' => $this->t('The title will appear on checkout page in payment methods list. Leave blank for payment method title.'),
    );
    $form['payment_method_description'] = array(
      '#type' => 'textarea',
      '#title' => $this->t('Payment method description'),
      '#default_value' => $this->configuration['payment_method_description'],
      '#description' => $this->t('The description will appear on checkout page.'),
    );
    $form['popup_title'] = array(
      '#type' => 'textfield',
      '#title' => $this->t('Payment popup title'),
      '#default_value' => $this->configuration['popup_title'],
      '#description' => $this->t('The title will appear on the Lunar payment popup window. Leave blank to show the site name.'),
    );

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);

    if (!$form_state->getErrors()) {
      $values = $form_state->getValue($form['#parents']);
      $this->configuration['public_key'] = $values['public_key'];
      $this->configuration['private_key'] = $values['private_key'];
      $this->configuration['payment_method_title'] = $values['payment_method_title'];
      $this->configuration['payment_method_description'] = $values['payment_method_description'];
      $this->configuration['popup_title'] = $values['popup_title'];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function createPayment(PaymentInterface $payment, $capture = TRUE) {
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
  public function capturePayment(PaymentInterface $payment, Price $amount = NULL) {
    $this->assertPaymentState($payment, ['authorization']);
    // If not specified, capture the entire amount.
    $amount = $amount ?: $payment->getAmount();

    $remote_id = $payment->getRemoteId();
    try {
      $transactions = $this->apiClient->transactions();
      $transaction = $transactions->capture($remote_id, array('amount' => $this->toMinorUnits($amount)));

      if ($transaction['successful']) {
        $payment->setState('completed');
        $payment->setAmount($amount);
        $payment->save();
      }
    } catch (\Paylike\Exception\ApiException $e) {
      \Drupal::logger('commerce_lunar')->warning($e->getMessage());
      throw new PaymentGatewayException($this->t('Capture failed. Transaction @id. @message', ['@id' => $remote_id, '@message' => $e->getMessage()]));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function voidPayment(PaymentInterface $payment) {
    $this->assertPaymentState($payment, ['authorization']);
    $remote_id = $payment->getRemoteId();
    $amount = $payment->getAmount();
    try {
      $transactions = $this->apiClient->transactions();
      $transaction = $transactions->void($remote_id, array('amount' => $this->toMinorUnits($amount)));

      if ($transaction['successful']) {
        $payment->setState('authorization_voided');
        $payment->save();
      }
    } catch (\Paylike\Exception\ApiException $e) {
      \Drupal::logger('commerce_lunar')->warning($e->getMessage());
      throw new PaymentGatewayException($this->t('Capture failed. Transaction @id. @message', ['@id' => $remote_id, '@message' => $e->getMessage()]));
    }

  }

  /**
   * {@inheritdoc}
   */
  public function refundPayment(PaymentInterface $payment, Price $amount = NULL) {
    $this->assertPaymentState($payment, ['completed', 'partially_refunded']);
    // If not specified, refund the entire amount.
    $amount = $amount ?: $payment->getAmount();
    $this->assertRefundAmount($payment, $amount);

    $remote_id = $payment->getRemoteId();
    try {
      $transactions = $this->apiClient->transactions();
      $transaction = $transactions->refund($remote_id, array('amount' => $this->toMinorUnits($amount)));

      if ($transaction['successful']) {
        $old_refunded_amount = $payment->getRefundedAmount();
        $new_refunded_amount = $old_refunded_amount->add($amount);
        if ($new_refunded_amount->lessThan($payment->getAmount())) {
          $payment->setState('partially_refunded');
        } else {
          $payment->setState('refunded');
        }
        $payment->setRefundedAmount($new_refunded_amount);
        $payment->save();
      }
    } catch (\Paylike\Exception\ApiException $e) {
      \Drupal::logger('commerce_lunar')->warning($e->getMessage());
      throw new PaymentGatewayException($this->t('Refund failed. Transaction @id. @message', ['@id' => $remote_id, '@message' => $e->getMessage()]));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function createPaymentMethod(PaymentMethodInterface $payment_method, array $payment_details) {
    $required_keys = [
      'lunar_transaction_id'
    ];
    foreach ($required_keys as $required_key) {
      if (empty($payment_details[$required_key])) {
        throw new \InvalidArgumentException(sprintf('$payment_details must contain the %s key.', $required_key));
      }
    }

    $transaction = $this->getTransaction($payment_details['lunar_transaction_id']);
    if ($transaction['successful']) {
      $card = $transaction['card'];
      $expiry = new DateTime($card['expiry']);
      $payment_method->card_type = $card['scheme'];
      $payment_method->card_number = $card['last4'];
      $payment_method->card_exp_month = $expiry->format('m');
      $payment_method->card_exp_year = $expiry->format('Y');
      $payment_method->setExpiresTime($expiry->getTimestamp());
      $payment_method->setRemoteId($transaction['id']);
      $payment_method->setReusable(false); // TODO: add a reusable card
      $payment_method->save();
    } else {
      throw new PaymentGatewayException($this->t('Transaction failed. Transaction @id.', ['@id' => $transaction['id']]));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function deletePaymentMethod(PaymentMethodInterface $payment_method) {
    $payment_method->delete();
  }

  /**
   * Returns a transaction.
   * @param $id
   * @return array
   */
  protected function getTransaction($id) {
    try {
      $transactions = $this->apiClient->transactions();
      $transaction = $transactions->fetch($id);
      return $transaction;
    } catch (\Paylike\Exception\ApiException $e) {
      \Drupal::logger('commerce_lunar')->warning($e->getMessage());
      throw new InvalidRequestException($this->t('Transaction @id not found. @message', ['@id' => $id, '@message' => $e->getMessage()]));
    }
  }

  protected function saveCard($transaction, $notes = '') {
    if (!is_array($transaction) || empty($transaction)) {
      throw new InvalidArgumentException($this->t('Wrong transaction data.'));
    }
    $transactionId = $transaction['id'];
    $merchantId = $transaction['merchantId'];
    try {
      $cards = $this->apiClient->cards();
      $cardId = $cards->create($merchantId, ['transactionId' => $transactionId, 'notes' => $notes]);
      return $cardId;
    } catch (\Paylike\Exception\ApiException $e) {
      \Drupal::logger('commerce_lunar')->warning($e->getMessage());
      throw new InvalidRequestException($this->t('Card not found. Transaction @id. @message', ['@id' => $transactionId, '@message' => $e->getMessage()]));
    }
  }
}
