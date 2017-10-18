This module adds a basic payment receipt messaging whenever the payment of total amount is done, it will send a mail to the same destination as order receipt does(also the bcc setted in order type). The method to do this is subscribing to these payment transition events:
  - Authorized.
  - Received.
  - Authorized and Captured.
  - Order complete pre_transition occurs.
When it occurs it will check if payment is the same as total order amount (in the future when totalpaid property is implemented in commerce_order module, it can check this) and then will try to fill the order's invoice_number field (install invoice_number module to get this field in order entity). If field exists then it can contiune and generate the pdf. You'll notice that payments operation switches to default theme instead of admin theme, this is to create the correct theme negotiation (through src/Theme/PaymentReceiptThemeNegotiatior.php) and be able to theme the pdf invoice. When pdf is generated t will be stored in this module's provided commerce_order field invoice_pdf. And a Mail will be sent to order coustomer and to the same bcc specified in order_receipt configuration in order type settings.
To create the invoice programatically the order needs to provide an invoice_number field and the invoice_pdf field. When adding a payment with the conditions above it will create this.

## Theming
This module does a theme negotiation (through src/Theme/PaymentReceiptThemeNegotiatior.php) when route is any payments operations, setting in these situations the defaut theme. With this way we get done pdfs allowing the theming with the templates of default theme (instead of admin theme) ( more bg in https://www.drupal.org/node/2860122#comment-11988019).
To theme the pdf one can preprocess the order (and theme in commerce_order--pdf.twig.html), more info about pdf theming in: https://www.drupal.org/node/2706755.
The email notification whenever a payment is done can also be themed with commerce-payment-receipt.html.twig independent of pdf existance. 
Note that subtheming admin theme is also possible to place there templates.

## Testing
While  UnitTests are being done one can test with:

use Drupal\commerce_payment\Entity\Payment;

/**$payment = \Drupal\commerce_payment\Entity\Payment::create([
'number' => 22.00,
'currency_code' => 'EUR',
'order_id' => 38,
'payment_gateway' => 'bank_transfer',
]);*/
//$payment_old = Payment::load(73);
//$payment = $payment_old->createDuplicate();
//$payment->set('state', 'pending');
//$payment->save();
// $payment->set('state','received');
// $payment->save();

or:

      /**
       * TESTS
       */
      $order = Order::load(113);
      ksm($order);
      kint($order->getState()->value);
      $transition = $order->getState()->getWorkflow()->getTransition('validate');
      kint($transition);
      $order->getState()->applyTransition($transition);
      $order->state->first()->applyTransition($transition);
      $order->state->postSave();
      kint($order->getState());
      $order->save();
      kint($order);
