# Lunar Online Payments for Drupal 8.x

## Supported Drupal Commerce versions
*The plugin has been tested with most versions of Drupal Commerce at every iteration. We recommend using the latest version of Drupal Commerce, but if that is not possible for some reason, test the plugin with your Drupal Commerce version and it would probably function properly.*


## Installation

Once you have installed Drupal Commerce on your Drupal setup, follow these simple steps:
   1. Signup at [lunar.app](https://lunar.app) (it’s free)
   1. Create an account
   1. Create an keys for your Drupal website
   1. Upload the contents of the `zip` file from latest release (https://github.com/lunar/payments-plugin-drupal-commerce-8.x/releases) to the modules directory and enable it on the `admin/modules` page OR run `composer require drupal/commerce_lunar`
   1. If the zip file is used, it is required to run `compose require lunar/payments-api-sdk` before installing the plugin
   1. Add the payment gateway from `admin/commerce/config/payment-gateways` and select one of the Lunar methods displayed under the `Plugin` section
   1. Set capture mode to either Delayed or Instant under `Capture mode` section.
`


## Updating settings

Under the Lunar payment method settings, you can:
 * Update the payment method text in the payment gateways list
 * Update the payment method description in the payment gateways list
 * Update the title that shows up in the checkout flow
 * Add app & public keys and other required data
 * Change the capture mode (Instant/Delayed)


 ## How to

 1. Capture
   * In "Instant" mode, the orders are captured automatically
   * In "Delayed" mode you can capture an order by using the Payments tab from an order. If available the capture operation will show up.
 2. Refund
   * You can refund an order by using the Payment tab from an order. If available the refund operation will show up.
 3. Void
   * You can void an order by using the Payment operations from an order. If available the void operation will show up.

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