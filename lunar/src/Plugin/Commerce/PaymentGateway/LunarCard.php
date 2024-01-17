<?php

namespace Drupal\commerce_lunar\Plugin\Commerce\PaymentGateway;

/**
 * Provides the Off-site payment gateway.
 *
 * @CommercePaymentGateway(
 *   id = "lunar_card",
 *   label = "Lunar Card",
 *   display_label = "Card",
 *   forms = {
 *     "offsite-payment" = "\Drupal\commerce_lunar\PluginForm\LunarForm",
 *   },
 * )
 */
class LunarCard extends LunarGatewayBase
{
  protected $paymentMethodCode = 'card';
}
