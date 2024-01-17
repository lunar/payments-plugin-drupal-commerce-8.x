<?php

namespace Drupal\commerce_lunar\PluginForm;

use Drupal\commerce_payment\PluginForm\PaymentOffsiteForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\commerce_payment\Entity\PaymentGatewayInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\HasPaymentInstructionsInterface;
use Drupal\Core\Url;
use Symfony\Component\Yaml\Yaml;
use Symfony\Component\HttpFoundation\RedirectResponse;

use Lunar\Lunar;
use Lunar\Exception\ApiException;
use Drupal\commerce_lunar\Plugin\Commerce\PaymentGateway\LunarGatewayBase;

/**
 * 
 */
class LunarForm extends PaymentOffsiteForm
{
  const REMOTE_URL = 'https://pay.lunar.money/?id=';
  const TEST_REMOTE_URL = 'https://hosted-checkout-git-develop-lunar-app.vercel.app/?id=';

  private $paymentMethod;

  private $lunarApiClient;
  private $order;
  private $testMode;
  private $configuration;
  private $currencyCode;
  private $totalAmount;
  private $args = [];
  private $paymentMethodCode = '';
  private $returnUrl = '';

  /**
   *
   */
  public function __construct()
  {
    $request = \Drupal::request();

    $this->order = $request->get('commerce_order');

    $totalPrice = $this->order->getTotalPrice();
    $this->totalAmount = (string) $totalPrice->getNumber();
    $this->currencyCode = $totalPrice->getCurrencyCode();

    /** @var PaymentGatewayInterface $payment_gateway */
    $payment_gateway = $this->order->get('payment_gateway')->entity;
    /** @var HasPaymentInstructionsInterface $payment_gateway_plugin */
    $this->paymentMethod = $payment_gateway->getPlugin();

    if (!$this->paymentMethod instanceof LunarGatewayBase) {
      return $this->redirectWithNotification('Something went wrong. Try again');
    }

    $this->paymentMethodCode = strstr($this->paymentMethod->getPluginId(), 'mobilepay') ? 'mobilePay' : 'card';

    $this->configuration = $this->paymentMethod->getConfiguration();

    $this->testMode = !!$request->cookies->get('lunar_testmode');

    if ($this->getConfig('app_key')) {
      $this->lunarApiClient = new Lunar($this->getConfig('app_key'), null, $this->testMode);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state)
  {
    $form = parent::buildConfigurationForm($form, $form_state);

    $this->returnUrl = $form['#return_url'];

    $this->setArgs();

    try {
      $paymentIntentId = $this->lunarApiClient->payments()->create($this->args);
      $this->savePaymentIntent($paymentIntentId);
    } catch (ApiException $e) {
      return $this->redirectWithNotification($e->getMessage());
    }

    if (!$paymentIntentId) {
      return $this->redirectWithNotification('An error occurred creating payment intent. 
        Please try again or contact system administrator.');
    }

    $redirect_url = ($this->testMode ? self::TEST_REMOTE_URL : self::REMOTE_URL) . $paymentIntentId;

    return $this->buildRedirectForm($form, $form_state, $redirect_url, [], PaymentOffsiteForm::REDIRECT_GET);
  }


  

  /**
   * @return void
   */
  private function setArgs()
  {
    $products = [];
    foreach ($this->order->getItems() as $product) {
      $products[] = [
        'ID' => $product->id(),
        'name' => $product->label(),
        'quantity' => $product->getQuantity(),
      ];
    }

    $address = $this->getAddressInfo();

    $this->args = [
      'integration' => [
        'key' => $this->configuration['public_key'],
        'name' => $this->getShopTitle(),
        'logo' => $this->configuration['logo_url'],
      ],
      'amount' => [
        'currency' => $this->currencyCode,
        'decimal' => $this->totalAmount,
      ],
      'custom' => [
        'orderId' => $this->order->id(),
        'products' => $products,
        'customer' => [
          'email' => $this->order->getEmail(),
          'IP' => \Drupal::request()->getClientIp(),
          'name' => $address['name'],
          'address' => $address['address'],
        ],
        'platform' => [
          'name' => 'Drupal',
          'version' => \DRUPAL::VERSION,
        ],
        'ecommerce' => [
          'name' => 'Drupal Commerce',
          'version' => \Drupal::service('extension.list.module')->getExtensionInfo('commerce')['version'],
        ],
        'lunarPluginVersion' => [
          'version' => Yaml::parseFile(dirname(__DIR__, 2) . '/commerce_lunar.info.yml')['version'],
        ],
      ],
      'redirectUrl' => $this->returnUrl,
      'preferredPaymentMethod' => $this->paymentMethodCode,
    ];

    if ($this->configuration['configuration_id']) {
      $this->args['mobilePayConfiguration'] = [
        'configurationID' => $this->configuration['configuration_id'],
        'logo' => $this->configuration['logo_url'],
      ];
    }

    if ($this->testMode) {
      $this->args['test'] = $this->getTestObject();
    }
  }

  
  /**
   * 
   */
  private function redirectWithNotification($message)
  {
    \Drupal::messenger()->addError($message);
    return new RedirectResponse(Url::fromRoute('commerce_checkout.form',
      [
        'commerce_order' => $this->order->id(),
        'step' => 'review'
      ]
    )->toString());
  }

  /**
   * @return void
   */
  private function savePaymentIntent($paymentIntentId)
  {
    $this->order->data->{LunarGatewayBase::INTENT_ID_KEY} = $paymentIntentId;
    $this->order->save();
  }

  /**
   * @return string
   */
  private function getShopTitle()
  {
    return $this->getConfig('shop_title') ?? \Drupal::config('system.site')->get('name');
  }

  /**
   * 
   */
  private function getConfig($key)
  {
    return !empty($this->configuration[$key]) ? $this->configuration[$key] : null;
  }

  /**
   *
   */
  private function getTestObject(): array
  {
    return [
      "card"        => [
        "scheme"  => "supported",
        "code"    => "valid",
        "status"  => "valid",
        "limit"   => [
          "decimal"  => "25000.99",
          "currency" => $this->currencyCode,

        ],
        "balance" => [
          "decimal"  => "25000.99",
          "currency" => $this->currencyCode,

        ]
      ],
      "fingerprint" => "success",
      "tds"         => array(
        "fingerprint" => "success",
        "challenge"   => true,
        "status"      => "authenticated"
      ),
    ];
  }

  
  /**
   * @return array
   */
  private function getAddressInfo()
  {
    $billingProfile = $this->order->getBillingProfile();

    if (!$billingProfile) {
      $customer = $this->order->getCustomer();
      $billingProfile = \Drupal::entityTypeManager()->getStorage('profile')
                          ->loadDefaultByUser($customer, 'customer');
    }

    $data = [
      'address' => '',
      'name' => '',
    ];

    if ($billingProfile) {
      $addressInfo = $billingProfile->get('address')->first()->toArray();

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
