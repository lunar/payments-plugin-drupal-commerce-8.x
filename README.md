# Drupal Commerce plugin for Lunar

This plugin is *not* developed or maintained by Lunar but kindly made
available by a user.

Released under the GPL V3 license: https://opensource.org/licenses/GPL-3.0

## Supported Drupal Commerce versions

[![Last succesfull test](https://log.derikon.ro/api/v1/log/read?tag=drupalcommerce8&view=svg&label=DrupalCommerce&key=ecommerce&background=00b4ff)](https://log.derikon.ro/api/v1/log/read?tag=drupalcommerce8&view=html)

*The plugin has been tested with most versions of Drupal Commerce at every iteration. We recommend using the latest version of Drupal Commerce, but if that is not possible for some reason, test the plugin with your Drupal Commerce version and it would probably function properly.*


## Installation

Once you have installed Drupal Commerce on your Drupal setup, follow these simple steps:
   1. Signup at [lunar.app](https://lunar.app) (it’s free)
   2. Create an account
   3. Create an app key for your Drupal website
   4. Upload the ```lunar.zip``` contents to the modules directory and enable it on the `admin/modules page` OR run `composer require drupal/commerce_lunar`
   5. Add the payment gateway from `admin/commerce/config/payment-gateways` and select "Lunar" under the Plugin section
   6. Set transaction mode to either Auth+Capture or Auth only under "Payment process" on `admin/commerce/config/checkout-flows/manage/default
`


## Updating settings

Under the Lunar payment method settings, you can:
 * Update the payment method text in the payment gateways list
 * Update the payment method description in the payment gateways list
 * Update the title that shows up in the payment popup
 * Add public & app keys
 * Change the capture type (Instant/Delayed)


 ## How to

 1. Capture
   * In Authorization+Capture mode, the orders are captured automatically
   * In Authorization only mode you can capture an order by using the Payments tab from an order. If available the capture operation will show up. (admin/commerce/orders/ORDER_ID/payments)
 2. Refund
   * You can refund an order by using the Payment tab from an order. If available the refund operation will show up. (admin/commerce/orders/ORDER_ID/payments)
 3. Void
   * You can void an order by using the Payment operations from an order. If available the void operation will show up. (admin/commerce/orders/ORDER_ID/payments)

   ## Available features

1. Capture
   * Drupal admin panel: full/partial capture
   * Lunar admin panel: full/partial capture
2. Refund
   * Drupal admin panel: full/partial refund
   * Lunar admin panel: full/partial refund
3. Void
   * Drupal admin panel: full void
   * Lunar admin panel: full/partial void