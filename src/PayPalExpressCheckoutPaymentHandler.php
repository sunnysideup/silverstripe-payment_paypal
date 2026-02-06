<?php

namespace Sunnysideup\PaymentPaypal;




use SilverStripe\Control\Director;
use SilverStripe\Control\Controller;
use SilverStripe\Core\Config\Config;



/**
 * Handler for responses from the PayPal site
 */
class PayPalExpressCheckoutPaymentHandler extends Controller
{
    private static $url_segment = 'paypalexpresscheckoutpaymenthandler';

    protected $payment; //only need to get this once

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
    }

    protected function doRedirect()
    {
        $payment = $this->payment();
        if ($payment && $obj = $payment->PaidObject()) {
            $this->redirect($obj->Link());
            return;
        }
        $this->redirect(Director::absoluteURL('home', true));
    }

    public static function return_link()
    {
        return self::make_link("confirm");
    }

    public static function cancel_link()
    {
        return self::make_link("cancel");
    }
    public static function complete_link()
    {

        return self::make_link("complete");
    }

    protected static function make_link($action)
    {
        Controller::join_links(
            Director::baseURL(),
            Config::inst()->get(PayPalExpressCheckoutPaymentHandler::class, "url_segment"),
            $action
        );
    }
}
