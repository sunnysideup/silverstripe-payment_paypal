<?php

namespace Sunnysideup\PaymentPayPal;

use Controller;
use Director;
use Config;


/**
 * Handler for responses from the PayPal site
 */
class PayPalExpressCheckoutPayment_Handler extends Controller
{
    private static $url_segment = 'paypalexpresscheckoutpayment_handler';

    protected $payment = null; //only need to get this once

    private static $allowed_actions = array(
        'confirm',
        'cancel'
    );

    public function Link($action = null)
    {
        return Controller::join_links(
            Director::baseURL(),
            $this->Config()->get("url_segment"),
            $action
        );
    }

    public function payment()
    {
        if ($this->payment) {
            return $this->payment;
        } elseif ($token = Controller::getRequest()->getVar('token')) {
            $payment =  PayPalExpressCheckoutPayment::get()
                ->filter(
                    array(
                        "Token" => $token,
                        "Status" => "Incomplete"
                    )
                )
                ->first();
            $this->payment = $payment;
            $this->payment->init();
            return $this->payment;
        }
        return null;
    }

    public function confirm($request)
    {
        //TODO: pretend the user confirmed, and skip straight to results. (check that this is allowed)
        //TODO: get updated shipping details from paypal??
        if ($payment = $this->payment()) {
            if ($pid = Controller::getRequest()->getVar('PayerID')) {
                $payment->PayerID = $pid;
                $payment->write();
                $payment->confirmPayment();
            }
        } else {
            //something went wrong?	..perhaps trying to pay for a payment that has already been processed
        }
        $this->doRedirect();
        return;
    }

    public function cancel($request)
    {
        if ($payment = $this->payment()) {
            //TODO: do API call to gather further information
            $payment->Status = "Failure";
            $payment->Message = _t('PayPalExpressCheckoutPayment.USERCANCELLED', "User cancelled");
            $payment->write();
        }
        $this->doRedirect();
        return;
    }

    protected function doRedirect()
    {
        $payment = $this->payment();
        if ($payment && $obj = $payment->PaidObject()) {
            $this->redirect($obj->Link());
            return;
        }
        $this->redirect(Director::absoluteURL('home', true)); //TODO: make this customisable in Payment_Controllers
        return;
    }

    public static function return_link()
    {

/**
  * ### @@@@ START REPLACEMENT @@@@ ###
  * WHY: automated upgrade
  * OLD: Config::inst()->get("
  * NEW: Config::inst()->get(" ...  (COMPLEX)
  * EXP: Check if you should be using Name::class here instead of hard-coded class.
  * ### @@@@ STOP REPLACEMENT @@@@ ###
  */
        return Director::absoluteURL(Config::inst()->get("PayPalExpressCheckoutPayment_Handler", "url_segment"), true)."/confirm/";
    }

    public static function cancel_link()
    {

/**
  * ### @@@@ START REPLACEMENT @@@@ ###
  * WHY: automated upgrade
  * OLD: Config::inst()->get("
  * NEW: Config::inst()->get(" ...  (COMPLEX)
  * EXP: Check if you should be using Name::class here instead of hard-coded class.
  * ### @@@@ STOP REPLACEMENT @@@@ ###
  */
        return Director::absoluteURL(Config::inst()->get("PayPalExpressCheckoutPayment_Handler", "url_segment"), true)."/cancel/";
    }
}

