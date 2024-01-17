<?php

namespace Drupal\commerce_lunar\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\SupportsAuthorizationsInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\SupportsRefundsInterface;

/**
 * Provides the interface for the lunar payment gateway.
 *
 * The OffsitePaymentGatewayInterface is the base interface which all off-site
 * gateways implement. The other interfaces signal which additional capabilities
 * the gateway has. The gateway plugin is free to expose additional methods,
 * which would be defined below.
 */
interface LunarInterface extends OffsitePaymentGatewayInterface, SupportsAuthorizationsInterface, SupportsRefundsInterface
{
}
