<?php

namespace Drupal\commerce_lunar\Plugin\Commerce\PaymentGateway;

/**
 * Provides the Off-site payment gateway.
 *
 * @CommercePaymentGateway(
 *   id = "lunar_mobilepay",
 *   label = "Lunar MobilePay",
 *   display_label = "MobilePay",
 *   forms = {
 *     "offsite-payment" = "\Drupal\commerce_lunar\PluginForm\LunarForm",
 *   },
 * )
 */
class LunarMobilePay extends LunarGatewayBase
{
  protected $paymentMethodCode = 'mobilePay';
}
