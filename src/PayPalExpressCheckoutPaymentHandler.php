<?php

declare(strict_types=1);

namespace Sunnysideup\PaymentPaypal;

use Override;
use Psr\Log\LoggerInterface;
use SilverStripe\Control\Controller;
use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injector;

/**
 * Handler for responses from the PayPal site.
 */
class PayPalExpressCheckoutPaymentHandler extends Controller
{
    private static string $url_segment = 'paypalexpresscheckoutpaymenthandler';

    private static array $allowed_actions = [
        'confirm',
        'cancel',
    ];

    protected ?PayPalExpressCheckoutPayment $payment = null;

    #[Override]
    public function Link($action = null): string
    {
        return self::makeLink($action);
    }

    public static function return_link(): string
    {
        return self::makeLink('confirm');
    }

    public static function cancel_link(): string
    {
        return self::makeLink('cancel');
    }

    /**
     * Note: "complete" is not an allowed action in this controller.
     * Keep only if something external relies on this URL.
     */
    public static function complete_link(): string
    {
        return self::makeLink('complete');
    }

    public function confirm(HTTPRequest $request): void
    {
        $this->logRequest($request);

        $payment = $this->getPayment($request);
        if (!$payment instanceof PayPalExpressCheckoutPayment) {
            user_error('No payment found for token: ' . (string)$request->getVar('token'), E_USER_WARNING);
            $this->doRedirect();
            return;
        }

        $payerId = (string)$request->getVar('PayerID');
        if ($payerId !== '') {
            $payment->PayerID = $payerId;
            $payment->write();
            $payment->confirmPayment();
        }

        $this->doRedirect();
    }

    public function cancel(HTTPRequest $request): void
    {
        $this->logRequest($request);

        $payment = $this->getPayment($request);
        if ($payment instanceof PayPalExpressCheckoutPayment) {
            $payment->Status = 'Failure';
            $payment->Message = _t('PayPalExpressCheckoutPayment.USERCANCELLED', 'User cancelled');
            $payment->write();
        }

        $this->doRedirect();
    }

    protected function doRedirect()
    {
        $payment = $this->getPayment($this->getRequest());

        $paidObject = $payment?->PaidObject();
        if ($paidObject) {
            $this->redirect($paidObject->Link());
            return;
        }

        return $this->httpError(404, _t('PayPalExpressCheckoutPayment.NOTFOUND', 'Payment not found'));
    }

    protected static function makeLink(?string $action): string
    {
        return Controller::join_links(
            Director::absoluteBaseURL(),
            (string)Config::inst()->get(self::class, 'url_segment'),
            $action
        );
    }

    protected function getPayment(?HTTPRequest $request): ?PayPalExpressCheckoutPayment
    {
        if ($this->payment instanceof PayPalExpressCheckoutPayment) {
            return $this->payment;
        }

        if (!$request instanceof HTTPRequest) {
            return null;
        }

        $token = (string)$request->getVar('token');
        if ($token === '') {
            return null;
        }

        $payment = PayPalExpressCheckoutPayment::get()
            ->filter([
                'Token' => $token,
                'Status' => 'Incomplete',
            ])
            ->first();

        if (!$payment) {
            user_error('No payment found for token: ' . $token, E_USER_WARNING);
            return null;
        }

        $this->payment = $payment;
        $this->payment->init();

        return $this->payment;
    }

    protected function getLogger(): LoggerInterface
    {
        return Injector::inst()->get(LoggerInterface::class);
    }

    protected function logRequest(HTTPRequest $request): void
    {
        if (Config::inst()->get(PayPalExpressCheckoutPayment::class, 'debug')) {
            $logger = $this->getLogger();
            $logger->debug('PayPal handler request', [
                'action' => $request->param('Action'),
                'get' => $request->getVars(),
                'post' => $request->postVars(),
            ]);
        }
    }
}
