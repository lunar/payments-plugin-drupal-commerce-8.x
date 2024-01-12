(function ($, Drupal, drupalSettings) {

  Drupal.behaviors.commerceLunarForm = {
    attach: function (context) {
      // Attach the code only once
      $('.lunar-button', context).once('commerce_lunar').each(function() {
        if (!drupalSettings.commerceLunar || !drupalSettings.commerceLunar.publicKey || drupalSettings.commerceLunar.publicKey === '') {
          $('#edit-payment-information').prepend('<div class="messages messages--error">' + Drupal.t('Configure Lunar payment gateway settings please') + '</div>');
          return;
        }

        function handleResponse(error, response) {
          if (error) {
            return console.log(error);
          }
          console.log(response);
          $('.lunar-button').val(Drupal.t('Change credit card details'));
          $('#lunar_transaction_id').val(response.transaction.id);
        }

        $(this).click(function (event) {
          event.preventDefault();
          var lunar = Paylike({key: drupalSettings.commerceLunar.publicKey}),
            config = drupalSettings.commerceLunar.config;

          lunar.pay(config, handleResponse);
        });
      });
    }
  }

})(jQuery, Drupal, drupalSettings);
