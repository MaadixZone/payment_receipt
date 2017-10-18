<?php

namespace Drupal\payment_receipt\EventSubscriber;

use Drupal\commerce_order\OrderTotalSummaryInterface;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Render\RenderContext;
use Drupal\Core\Render\Renderer;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\state_machine\Event\WorkflowTransitionEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Drupal\entity_print\PrintBuilderInterface;
use Drupal\entity_print\Plugin\EntityPrintPluginManager;
use Drupal\Component\Render\PlainTextOutput;
use Drupal\Core\Entity\Query\QueryFactory;

/**
 * Sends a receipt email when an order is placed.
 */
class PaymentReceiptSubscriber implements EventSubscriberInterface {

  use StringTranslationTrait;

  /**
   * The order type entity storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  protected $orderTypeStorage;

  /**
   * The order total summary.
   *
   * @var \Drupal\commerce_order\OrderTotalSummaryInterface
   */
  protected $orderTotalSummary;

  /**
   * The entity view builder for profiles.
   *
   * @var \Drupal\profile\ProfileViewBuilder
   */
  protected $profileViewBuilder;

  /**
   * The language manager.
   *
   * @var \Drupal\Core\Language\LanguageManagerInterface
   */
  protected $languageManager;

  /**
   * The mail manager.
   *
   * @var \Drupal\Core\Mail\MailManagerInterface
   */
  protected $mailManager;

  /**
   * The renderer.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  protected $renderer;


  /**
   * The print builder.
   *
   * @var \Drupal\entity_print\PrintBuilderInterface
   */
  protected $printBuilder;

  /**
   * The print Engine.
   *
   * @var \Drupal\entity_print\Plugin\PrintEngineInterface
   */
  protected $printEngine;

  /**
   * QueryFactory definition.
   *
   * @var Drupal\Core\Entity\Query\QueryFactory
   */
  protected $entityQuery;

  /**
   * Constructs a new OrderReceiptSubscriber object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Language\LanguageManagerInterface $language_manager
   *   The language manager.
   * @param \Drupal\Core\Mail\MailManagerInterface $mail_manager
   *   The mail manager.
   * @param \Drupal\commerce_order\OrderTotalSummaryInterface $order_total_summary
   *   The order total summary.
   * @param \Drupal\Core\Render\Renderer $renderer
   *   The renderer.
   * @param \Drupal\entity_print\PrintBuilderInterface $print_builder
   *   The print builder.
   * @param \Drupal\entity_print\Plugin\EntityPrintPluginManager $print_plugin
   *   The print plugin manager to obtain engine.
   * @param \Drupal\Core\Entity\Query\QueryFactory $entity_query
   *   The query factory.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, LanguageManagerInterface $language_manager, MailManagerInterface $mail_manager, OrderTotalSummaryInterface $order_total_summary, Renderer $renderer, PrintBuilderInterface $print_builder, EntityPrintPluginManager $print_plugin, QueryFactory $entity_query) {
    $this->orderTypeStorage = $entity_type_manager->getStorage('commerce_order_type');
    $this->orderTotalSummary = $order_total_summary;
    $this->profileViewBuilder = $entity_type_manager->getViewBuilder('profile');
    $this->languageManager = $language_manager;
    $this->mailManager = $mail_manager;
    $this->renderer = $renderer;
    $this->printBuilder = $print_builder;
    $this->printEngine = $print_plugin->createSelectedInstance('pdf');
    $this->entityQuery = $entity_query;
  }

  /**
   * Sets the invoice number if field type invoice number exists in entity.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order to check payments for and add invoice.
   *
   * @return string
   *   field name of the invoice number
   *   or empty string if no invoice number
   *   field or no sufficient amount.
   *
   * @todo This must be rearranged when https://www.drupal.org/node/2804227
   */
  protected function setOrderInvoiceNumber(OrderInterface $order) {
    $payments_done = $this->checkOrderPayments($order);
    if ($payments_done < 1) {
      foreach ($order->getFieldDefinitions() as $field_name => $field_definition) {
        if ($field_definition->getType() == 'invoice_number') {
          if ($order->{$field_name}->autofill == "0" && $order->{$field_name}->value != "") {
            $do_nothing = "";
          }
          else {
            $order->{$field_name}->autofill = 1;
            $order->{$field_name}->first()->generateValueSeries();
          }
          return $field_name;
        }
      }
    }
    return "";
  }

  /**
   * Sends an invoice depending on total amount of payments. React on order.
   *
   * @param \Drupal\state_machine\Event\WorkflowTransitionEvent $event
   *   The event we subscribed to.
   *
   * @see autovalidate_payed/src/EventSubscriber/AutovalidatePayed.php:setOrderState
   *
   * @todo when paid_total property in order is there we need to use this
   * instead of doing the query to get payments.
   */
  public function sendInvoiceReceipt(WorkflowTransitionEvent $event) {
    /** @var \Drupal\commerce_order\Entity\OrderInterface $order */
    $order = $event->getEntity();
    $this->processInvoice($order);
  }

  /**
   * Sends an invoice receipt email. Used when manual.
   *
   * @param \Drupal\state_machine\Event\WorkflowTransitionEvent $event
   *   The event we subscribed to.
   *
   * @see autovalidate_payed/src/EventSubscriber/AutovalidatePayed.php:setOrderStateViaPayment
   */
  public function sendInvoiceReceiptViaPayment(WorkflowTransitionEvent $event) {
    /** @var \Drupal\commerce_payment\Entity\PaymentInterface $payment */
    $payment = $event->getEntity();
    /** @var \Drupal\commerce_order\Entity\OrderInterface $order */
    $order = $payment->getOrder();
    // First distinct if we are in a checkout process or it is a manual payment.
    if ($order->getState()->value != 'completed') {
      // If it's not in validate state means that we are in checkout process
      // so do nothing.
      return;
    }
    $this->processInvoice($order);
  }

  /**
   * Processes invoice build, adding pdf to field and sending to recipients.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order to check payments for and add invoice.
   */
  protected function processInvoice($order) {
    // Set the order invoice number if field exists in entity and obtain
    // invoice number field name.
    $invoice_number_field = $this->setOrderInvoiceNumber($order);
    if (empty($invoice_number_field)) {
      return;
    }
    /** @var \Drupal\commerce_order\Entity\OrderTypeInterface $order_type */
    $order_type = $this->orderTypeStorage->load($order->bundle());
    // @todo by now we take the configuration from order ->shouldSendReceipt
    // also bcc.
    // but someday it must be moved to own pane copying this order pane.
    if (!$order_type->shouldSendReceipt()) {
      return;
    }
    $to = $order->getEmail();
    if (!$to) {
      // The email should not be empty, unless the order is malformed.
      return;
    }

    $params = [
      'headers' => [
        'Content-Type' => 'text/html',
      ],
      'from' => $order->getStore()->getEmail(),
      'subject' => $this->t('Invoice for order #@number', ['@number' => $order->getOrderNumber()]),
      'order' => $order,
    ];
    if ($receipt_bcc = $order_type->getReceiptBcc()) {
      // The bcc is the same as order receipt.
      $params['headers']['Bcc'] = $receipt_bcc;
    }

    $build = [
      '#theme' => 'commerce_payment_receipt',
      '#order_entity' => $order,
      '#totals' => $this->orderTotalSummary->buildTotals($order),
    ];
    if ($billing_profile = $order->getBillingProfile()) {
      $build['#billing_information'] = $this->profileViewBuilder->view($billing_profile);
    }
    // When invoice number set store pdf file to invoice_pdf field.
    if (isset($order->{$invoice_number_field})) {
      if (!empty($order->{$invoice_number_field}->value)) {
        $invoice_number = $order->{$invoice_number_field}->value . $order->{$invoice_number_field}->series_suffix;
        $invoice_number_filename = $invoice_number . '.' . $this->printEngine->getExportType()->getFileExtension();
        $invoice_pdf_settings = $order->invoice_pdf->getSettings();
        $upload_location = $this->doGetUploadLocation($invoice_pdf_settings);
        // @todo As this logic runs in admin context seven is used so
        // PaymentReceiptThemeNegotiator class is used in this module to switch
        // to default theme when operating with payments to allow pdf theming
        // and processing.
        $html = $this->printBuilder->printHtml($order);
        $this->printEngine->addPage($html);
        $blob = $this->printEngine->getBlob();
        $destination = $upload_location . '/' . $invoice_number_filename;
        if (!file_prepare_directory($upload_location, FILE_CREATE_DIRECTORY)) {
          \Drupal::logger('payment_receipt')->error("Could not create directory " . $upload_location . " to store payments receipts");
        }
        $file = file_save_data($blob, $destination, FILE_EXISTS_REPLACE);
        $order->invoice_pdf->target_id = $file->id();
        // $order->save();
        $build['#last_invoice_url'] = $file->url();

        /* Attach the file to order receipt email.
        // @todo Uncomment if we want to attach as it has to work immediately.
        $attachment = new \stdClass();
        $attachment->uri = $file->getFileUri();
        $attachment->filename = $file->getFilename();
        $attachment->filemime = finfo_file(
        finfo_open(FILEINFO_MIME_TYPE), $file->getFileUri());
        $params['files'][] = $attachment;*/
      }
    }
    $params['body'] = $this->renderer->executeInRenderContext(new RenderContext(), function () use ($build) {
      return $this->renderer->render($build);
    });
    // Replicated logic from EmailAction and contact's MailHandler.
    if ($customer = $order->getCustomer()) {
      $langcode = $customer->getPreferredLangcode();
    }
    else {
      $langcode = $this->languageManager->getDefaultLanguage()->getId();
    }
    $this->mailManager->mail('payment_receipt', 'receipt', $to, $langcode, $params);

    // @todo Add Tests- when payment is not sufficient, when payment is sufficient
    // email sent? Invoice created?
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    $events = [
      'commerce_payment.authorize.post_transition' => ['sendInvoiceReceiptViaPayment', -103],
      'commerce_payment.authorize_capture.post_transition' => ['sendInvoiceReceiptViaPayment', -103],
      'commerce_payment.receive.post_transition' => ['sendInvoiceReceiptViaPayment', -103],
      'commerce_order.validate.pre_transition' => ['sendInvoiceReceipt', -104],
    ];
    return $events;
  }

  /**
   * Determines the URI for a file field. Copy from FileItem.php.
   *
   * @param array $settings
   *   The array of field settings.
   * @param array $data
   *   An array of token objects to pass to token_replace().
   *
   * @return string
   *   An unsanitized file directory URI with tokens replaced. The result of
   *   the token replacement is then converted to plain text and returned.
   */
  protected function doGetUploadLocation(array $settings, $data = []) {
    $destination = trim($settings['file_directory'], '/');

    // Replace tokens. As the tokens might contain HTML we convert it to plain
    // text.
    $destination = PlainTextOutput::renderFromHtml(\Drupal::token()->replace($destination, $data));
    return $settings['uri_scheme'] . '://' . $destination;
  }

  /**
   * Check the payments done in the Order.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order to check payments.
   *
   * @return int
   *   The priceInterface comparision @see \Drupal\commerce_price\Price
   *   compareTo method. 0 if both prices are equal, 1 if the first one is
   *   greater, -1 otherwise, 2 if no payments are done.
   *
   * @todo move to service checkOrderPayments. Also in
   * autovalidate_payed/src/EventSubscriber/AutovalidatePayed.php
   */
  protected function checkOrderPayments(OrderInterface $order) {
    $query = $this->entityQuery->get('commerce_payment');
    $query->condition('order_id', $order->id());
    $query->orConditionGroup()
      ->condition('state', 'completed')
      ->condition('state', 'authorization');
    $payment_ids = $query->execute();
    if (!empty($payment_ids)) {
      $payments = entity_load_multiple('commerce_payment', $payment_ids);
      // Check the amount of payments adding to popped last element of array.
      // And after all comparing to order. Using Price data type.
      $base_payment = array_pop($payments);
      $total_paid = $base_payment->getBalance();
      foreach ($payments as $id => $payment) {
        $total_paid->add($payment->getBalance());
      }
      return $order->getTotalPrice()->compareTo($total_paid);
    }
    return 2;
  }

}
