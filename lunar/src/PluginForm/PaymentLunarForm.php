<?php

namespace Drupal\commerce_lunar\PluginForm;

use Drupal\commerce_payment\PluginForm\PaymentOffsiteForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

class PaymentLunarForm extends PaymentOffsiteForm
{
  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    /** @var \Drupal\commerce_payment\Entity\PaymentInterface $payment */
    $payment = $this->entity;
    // /** @var \Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayInterface $payment_gateway_plugin */
    // $payment_gateway_plugin = $payment->getPaymentGateway()->getPlugin();

    // $redirect_url = Url::fromRoute('commerce_lunar.redirect')->toString();

    $data = [];
    // $data = [
    //   'return' => $form['#return_url'],
    //   'cancel' => $form['#cancel_url'],
    //   'commerce_order' => $payment->getOrder()->id(),
    // ];

    $form['#action'] = Url::fromRoute('commerce_lunar.redirect', 
            [
                'commerce_order' => $payment->getOrder()->id()
            ],
            [
                'absolute' => true
            ]
        )->toString();

    return $this->buildRedirectForm($form, $form_state, $form['#action'], $data);
  }

}
