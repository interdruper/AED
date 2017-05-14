<?php
namespace Drupal\paypal_sdk\Controller;

use Drupal;
use Drupal\Core\Controller\ControllerBase;
use Drupal\paypal_sdk\Entity\PayPalBillingAgreement;
use Drupal\paypal_sdk\Services\BillingAgreement;
use Drupal\user\Entity\User;

class PaypalSDKController extends ControllerBase {

  public function processResponse() {
    $request = Drupal::request();
    $token = $request->get('token');

    /** @var BillingAgreement $pba */
    $pba = Drupal::service('paypal.billing.agreement');

    /** @var \PayPal\Api\Agreement $agreement */
    $agreement = $pba->processAgreementResponse($token);

    /** @var \PayPal\Api\Payer $payer */
    $payer = $agreement->getPayer();

    /*
     * If the current user is anonymous and the email does not exist on any user, create a new account.
     */
    if (Drupal::currentUser()->isAnonymous()) {

      // The email (user) already exist?
      $existingUser = user_load_by_mail($payer->getPayerInfo()->getEmail());

      if (!$existingUser) {
        // Si el usuario no existe lo creamos.
        $user = User::create();
        $user->set("init", 'mail');
        $user->enforceIsNew();
        $user->setEmail($payer->getPayerInfo()->getEmail());
        $user->setUsername($payer->getPayerInfo()->getEmail());
        //$user->addRole('socio'); ?
        $user->activate();
        $user->save();

        _user_mail_notify('register_no_approval_required', $user);
        // user_login_finalize($user);
        drupal_set_message(t('Subscription successful. We sent you an activation email.'));
      }
      else {
        $user = $existingUser;
      }

    }
    else {
      $user = Drupal::currentUser();
    }

    $utcTimezone = new \DateTimeZone('UTC');

    // Create a drupal entity with the agreement.
    $entityBillingAgreement = PayPalBillingAgreement::create([
      'name' => $agreement->getDescription(),
      'user_id' => $user->id(),
      'field_paypal_agreement_id' => $agreement->getId(),
      'field_agreement_final_payment' => (new \DateTime($agreement->getAgreementDetails()->getFinalPaymentDate(), $utcTimezone))->format('Y-m-d'),
      'field_agreement_next_billing' => (new \DateTime($agreement->getAgreementDetails()->getNextBillingDate(), $utcTimezone))->format('Y-m-d'),
      'field_agreement_completed_cycles' => $agreement->getAgreementDetails()->getCyclesCompleted(),
      'field_agreement_remaining_cycles' => $agreement->getAgreementDetails()->getCyclesRemaining(),
    ]);

    $entityBillingAgreement->save();
    return $this->redirect('<front>');

// Debug
//    return array(
//      '#markup' => '<pre>' . $agreement->toJSON(JSON_PRETTY_PRINT) . '</pre>',
//    );


  }

  public function cancelledResponse() {
    drupal_set_message(t('Your subscription has been cancelled.'));
    return $this->redirect('<front>');
  }

}