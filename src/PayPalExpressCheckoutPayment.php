<?php

namespace Sunnysideup\PaymentPaypal;

use Override;
use GuzzleHttp\Client;
use SilverStripe\Control\Controller;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Manifest\ModuleResourceLoader;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\LiteralField;
use SilverStripe\ORM\DB;
use SilverStripe\SiteConfig\SiteConfig;
use Sunnysideup\Ecommerce\Config\EcommerceConfig;
use Sunnysideup\Ecommerce\Model\Money\EcommerceCurrency;
use Sunnysideup\Ecommerce\Model\Money\EcommercePayment;
use Sunnysideup\Ecommerce\Model\Order;
use Sunnysideup\Ecommerce\Money\Payment\PaymentResults\EcommercePaymentFailure;
use Sunnysideup\Ecommerce\Money\Payment\PaymentResults\EcommercePaymentProcessing;

/**
 * PayPal Express Checkout Payment
 *
 * @author Jeremy Shipman jeremy [at] burnbright.net
 * @author Nicolaas [at] sunnysideup.co.nz
 *
 * Developer documentation:
 * - Integration guide: https://cms.paypal.com/cms_content/US/en_US/files/developer/PP_ExpressCheckout_IntegrationGuide.pdf
 * - API reference: https://cms.paypal.com/us/cgi-bin/?cmd=_render-content&content_ID=developer/howto_api_reference
 * - Uses the Name-Value Pair API protocol
 */
class PayPalExpressCheckoutPayment extends EcommercePayment
{
    // Configuration
    private static $debug = true;

    private static $continue_button_text = 'Continue to PayPal';

    private static $table_name = 'PayPalExpressCheckoutPayment';

    private static $logo = 'sunnysideup/payment_paypal: client/dist/images/paypal.png';

    private static $payment_methods = [];

    private static $version = '64';

    // Database fields
    private static $db = [
        'Token' => 'Varchar(30)',
        'PayerID' => 'Varchar(30)',
        'TransactionID' => 'Varchar(30)',
        'AuthorisationCode' => 'Text',
        'Debug' => 'HTMLText',
    ];

    // PayPal URLs - Test Environment
    private static $test_API_Endpoint = 'https://api-3t.sandbox.paypal.com/nvp';

    private static $test_PAYPAL_URL = 'https://www.sandbox.paypal.com/webscr?cmd=_express-checkout&token=';

    // PayPal URLs - Live Environment
    private static $API_Endpoint = 'https://api-3t.paypal.com/nvp';

    private static $PAYPAL_URL = 'https://www.paypal.com/cgi-bin/webscr?cmd=_express-checkout&token=';

    private static $privacy_link = 'https://www.paypal.com/us/cgi-bin/webscr?cmd=p/gen/ua/policy_privacy-outside';

    // API Credentials
    private static $test_mode = false;

    private static $API_UserName;

    private static $API_Password;

    private static $API_Signature;

    private static $sBNCode; // BN Code is only applicable for partners

    // Custom PayPal checkout settings
    private static $custom_settings = [
        // Design options (uncomment and customize as needed):
        // 'HDRIMG' => "http://www.mysite.com/images/logo.jpg", // max size = 750px wide by 90px high, and good to be on secure server
        // 'HDRBORDERCOLOR' => 'CCCCCC', // header border
        // 'HDRBACKCOLOR' => '00FFFF', // header background
        // 'PAYFLOWCOLOR'=> 'AAAAAA', // payflow colour
        // 'PAGESTYLE' => // page style set in merchant account settings
        'SOLUTIONTYPE' => 'Sole', // require paypal account, or not. Can be 'Mark' (required) or 'Sole' (not required)
        // 'BRANDNAME' => 'my site name', // override business name in checkout
        // 'CUSTOMERSERVICENUMBER' => '0800 1234 5689', // number to call to resolve payment issues
        // 'NOSHIPPING' => 1 // disable showing shipping details
    ];


    /**
     * Configure CMS fields for this payment method
     */
    #[Override]
    public function getCMSFields()
    {
        $fields = parent::getCMSFields();

        foreach (array_keys($this->config()->get('db')) as $field) {
            /** @TODO SSU RECTOR UPGRADE TASK - FieldList::removeFieldFromTab: Changed type of parameter $fieldName in FieldList::removeFieldFromTab() from dynamic to string
             * @TODO SSU RECTOR UPGRADE TASK - FieldList::removeFieldFromTab: Changed type of parameter $tabName in FieldList::removeFieldFromTab() from dynamic to string
             * @TODO SSU RECTOR UPGRADE TASK - FieldList::removeFieldFromTab: Changed return type for method FieldList::removeFieldFromTab() from dynamic to FieldList
             */
            $fields->removeFieldFromTab('Root.Main', $field);
            /** @TODO SSU RECTOR UPGRADE TASK - FieldList::addFieldToTab: Changed type of parameter $field in FieldList::addFieldToTab() from dynamic to FormField
             * @TODO SSU RECTOR UPGRADE TASK - FieldList::addFieldToTab: Changed type of parameter $insertBefore in FieldList::addFieldToTab() from dynamic to string|null
             * @TODO SSU RECTOR UPGRADE TASK - FieldList::addFieldToTab: Changed type of parameter $tabName in FieldList::addFieldToTab() from dynamic to string
             * @TODO SSU RECTOR UPGRADE TASK - FieldList::addFieldToTab: Changed return type for method FieldList::addFieldToTab() from dynamic to FieldList
             */
            $fields->addFieldToTab(
                'Root.Advanced',
                LiteralField::create(
                    $field . '_debug',
                    '<h2>' . $field . '</h2><pre>' . $this->$field . '</pre>'
                )
            );
        }

        return $fields;
    }

    /**
     * Get payment form fields for checkout
     */
    #[Override]
    public function getPaymentFormFields($amount = 0, ?Order $order = null): FieldList
    {
        $logo = $this->config()->get('logo');
        $src = ModuleResourceLoader::singleton()->resolveURL($logo);
        $logoHtml = '<img src="' . $src . '" alt="Credit card payments powered by PayPal"/>';
        $privacyLink = '<a href="' . $this->Config()->get('privacy_link') . '" target="_blank" title="Read PayPal\'s privacy policy">' . $logoHtml . '</a><br/>';

        return FieldList::create(LiteralField::create('PayPalInfo', $privacyLink), LiteralField::create('PayPalPaymentsList', $this->RenderWith('Sunnysideup/PaymentPaypal/Includes/PaymentMethods')));
    }

    /**
     * Get payment form requirements
     */
    #[Override]
    public function getPaymentFormRequirements(): array
    {
        return [];
    }

    /**
     * Main payment processing function
     */
    #[Override]
    public function processPayment($data, $form)
    {
        // Validate API credentials
        if (
            !$this->Config()->get('API_UserName') ||
            !$this->Config()->get('API_Password') ||
            !$this->Config()->get('API_Signature')
        ) {
            user_error('You are attempting to make a payment without the necessary credentials set', E_USER_ERROR);
        }

        // Prepare payment data
        $data = $this->Order()->BillingAddress()->toMap();
        $amount = $this->Amount->Amount;
        $currency = $this->Amount->Currency;

        if (!$currency) {
            $currency = EcommerceConfig::get(EcommerceCurrency::class, 'default_currency');
        }

        // Get PayPal checkout URL
        $paymentUrl = $this->getTokenURL($amount, $currency, $data);
        $this->Status = 'Incomplete';
        $this->write();

        if ($paymentUrl) {
            Controller::curr()->redirect($paymentUrl);
            return EcommercePaymentProcessing::create();
        }

        // Handle failure
        $this->Message = _t('PayPalExpressCheckoutPayment.COULDNOTBECONTACTED', 'PayPal could not be contacted');
        $this->Status = 'Failure';
        $this->write();

        return EcommercePaymentFailure::create($this->Message);
    }

    /**
     * @deprecated This form is no longer used
     */
    public function PayPalForm()
    {
        user_error('This form is no longer used.');
    }

    /**
     * Set default values on object creation
     */
    #[Override]
    public function populateDefaults()
    {
        parent::populateDefaults();
        $this->AuthorisationCode = md5(uniqid(random_int(0, mt_getrandmax()), true));
    }

    /**
     * Request a Token URL for PayPal Express Checkout
     *
     * Requests a Token url, based on the provided Name-Value-Pair fields
     * See docs for more detail on these fields:
     * https://cms.paypal.com/us/cgi-bin/?cmd=_render-content&content_ID=developer/e_howto_api_nvp_r_SetExpressCheckout
     *
     * @param float $paymentAmount
     * @param string $currencyCodeType
     * @param array $extradata
     * @return string|null
     */
    protected function getTokenURL($paymentAmount, $currencyCodeType, $extradata = [])
    {
        $data = [
            // Payment information
            'PAYMENTREQUEST_0_AMT' => $paymentAmount,
            'PAYMENTREQUEST_0_CURRENCYCODE' => $currencyCodeType,

            // Return URLs
            'RETURNURL' => PayPalExpressCheckoutPaymentHandler::return_link(),
            'CANCELURL' => PayPalExpressCheckoutPaymentHandler::cancel_link(),

            // Shipping settings
            'NOSHIPPING' => 1, // don't show shipping fields
            'ADDROVERRIDE' => 1, // override the address stored in paypal

            // Other settings
            'LANDINGPAGE' => 'Billing', // can be 'Billing' or 'Login'
        ];

        // Process customer name
        if (!isset($extradata['Name'])) {
            $nameComponents = [];
            if (isset($extradata['FirstName'])) {
                $nameComponents[] = $extradata['FirstName'];
            }

            if (isset($extradata['MiddleName'])) {
                $nameComponents[] = $extradata['MiddleName'];
            }

            if (isset($extradata['Surname'])) {
                $nameComponents[] = $extradata['Surname'];
            }

            $extradata['Name'] = implode(' ', $nameComponents);
        }

        $extradata['OrderID'] = SiteConfig::current_site_config()->Title . ' ' . $this->Order()->getTitle();

        // Map customer data to PayPal fields
        $fieldMapping = [
            'Email' => 'EMAIL',
            'Name' => 'PAYMENTREQUEST_0_SHIPTONAME',
            'Address' => 'PAYMENTREQUEST_0_SHIPTOSTREET',
            'Address2' => 'PAYMENTREQUEST_0_SHIPTOSTREET2',
            'City' => 'PAYMENTREQUEST_0_SHIPTOCITY',
            'PostalCode' => 'PAYMENTREQUEST_0_SHIPTOZIP',
            'Region' => 'PAYMENTREQUEST_0_SHIPTOPHONENUM',
            'Phone' => 'PAYMENTREQUEST_0_SHIPTOPHONENUM',
            'Country' => 'PAYMENTREQUEST_0_SHIPTOCOUNTRYCODE',
            'OrderID' => 'PAYMENTREQUEST_0_DESC',
        ];

        foreach ($fieldMapping as $field => $paypalField) {
            if (isset($extradata[$field])) {
                $data[$paypalField] = $extradata[$field];
            } elseif ($this->$field) {
                $data[$paypalField] = $this->$field;
            }
        }

        // Apply custom settings
        $data = array_merge($this->Config()->get('custom_settings'), $data);

        // Make API call
        $response = $this->apiCall('SetExpressCheckout', $data);
        $mode = ($this->Config()->get('test_mode') === true) ? 'test' : 'live';

        // Debug logging
        if (Config::inst()->get(PayPalExpressCheckoutPayment::class, 'debug')) {
            $this->addDebugInfo('RESPONSE: ' . print_r($response, 1));

            $debugMessage = 'PayPal Debug:' .
                ('
Mode: ' . $mode) .
                "\nAPI url: " . $this->getApiEndpoint() .
                "\nRedirect url: " . $this->getPayPalURL($response['TOKEN']) .
                "\nUsername: " . $this->Config()->get('API_UserName') .
                "\nPassword: ***" . strlen((string) $this->Config()->get('API_Password')) . ' characters' .
                "\nSignature: ***" . strlen((string) $this->Config()->get('API_Signature')) . ' characters' .
                "\nRequest Data: " . print_r($data, true) .
                "\nResponse: " . print_r($response, true);

            $this->addDebugInfo('DEBUG MESSAGE: ' . $debugMessage);
        }

        // Check response status
        if (
            !isset($response['ACK']) ||
            (strtoupper($response['ACK']) !== 'SUCCESS' && strtoupper($response['ACK']) !== 'SUCCESSWITHWARNING')
        ) {
            return null;
        }

        // Save token and return PayPal URL
        $token = $response['TOKEN'];
        $this->Token = $token;
        $this->write();

        return $this->getPayPalURL($token);
    }

    /**
     * Confirm payment with PayPal after user returns from checkout
     *
     * @see https://cms.paypal.com/us/cgi-bin/?cmd=_render-content&content_ID=developer/e_howto_api_nvp_r_DoExpressCheckoutPayment
     */
    public function confirmPayment()
    {
        $amount = $this->Amount->Amount;
        $currency = $this->Amount->Currency;

        if (!$currency) {
            $currency = EcommerceConfig::get(EcommerceCurrency::class, 'default_currency');
        }

        $data = [
            'PAYERID' => $this->PayerID,
            'TOKEN' => $this->Token,
            'PAYMENTREQUEST_0_PAYMENTACTION' => 'Sale',
            'PAYMENTREQUEST_0_AMT' => $amount,
            'PAYMENTREQUEST_0_CURRENCYCODE' => $currency,
            'IPADDRESS' => urlencode((string) $_SERVER['SERVER_NAME']),
        ];

        $response = $this->apiCall('DoExpressCheckoutPayment', $data);

        if (
            !isset($response['ACK']) ||
            (strtoupper($response['ACK']) !== 'SUCCESS' && strtoupper($response['ACK']) !== 'SUCCESSWITHWARNING')
        ) {
            return null;
        }

        // Save transaction ID
        if (isset($response['PAYMENTINFO_0_TRANSACTIONID'])) {
            $this->TransactionID = $response['PAYMENTINFO_0_TRANSACTIONID'];
        }

        // Process payment status
        if (isset($response['PAYMENTINFO_0_PAYMENTSTATUS'])) {
            $this->processPaymentStatus($response['PAYMENTINFO_0_PAYMENTSTATUS'], $response);
        }

        $this->write();
        return null;
    }

    /**
     * Process the payment status from PayPal response
     */
    protected function processPaymentStatus($status, $response)
    {
        switch (strtoupper($status)) {
            case 'PROCESSED':
            case 'COMPLETED':
                $this->Status = 'Success';
                $this->Message = _t('PayPalExpressCheckoutPayment.SUCCESS', 'The payment has been completed, and the funds have been successfully transferred');
                break;

            case 'EXPIRED':
                $this->Message = _t('PayPalExpressCheckoutPayment.AUTHORISATION', 'The authorization period for this payment has been reached');
                $this->Status = 'Failure';
                break;

            case 'DENIED':
                $this->Message = _t('PayPalExpressCheckoutPayment.FAILURE', 'Payment was denied');
                $this->Status = 'Failure';
                break;

            case 'REVERSED':
            case 'FAILED':
                $this->Status = 'Failure';
                break;

            case 'VOIDED':
                $this->Message = _t('PayPalExpressCheckoutPayment.VOIDED', 'An authorization for this transaction has been voided.');
                $this->Status = 'Failure';
                break;

            case 'CANCEL-REVERSAL':
                // A reversal has been canceled; for example, when you win a dispute and the funds for the reversal have been returned to you.
                break;

            case 'IN-PROGRESS':
                $this->Message = _t('PayPalExpressCheckoutPayment.INPROGRESS', 'The transaction has not terminated');
                break;

            case 'PARTIALLY-REFUNDED':
                $this->Message = _t('PayPalExpressCheckoutPayment.PARTIALLYREFUNDED', 'The payment has been partially refunded.');
                break;

            case 'PENDING':
                $this->Message = _t('PayPalExpressCheckoutPayment.PENDING', 'The payment is pending.');
                if (isset($response['PAYMENTINFO_0_PENDINGREASON'])) {
                    $this->Message .= ' ' . $this->getPendingReason($response['PAYMENTINFO_0_PENDINGREASON']);
                }

                break;

            case 'REFUNDED':
                $this->Message = _t('PayPalExpressCheckoutPayment.REFUNDED', 'Payment refunded.');
                break;
        }
    }

    /**
     * Get human-readable pending reason
     */
    protected function getPendingReason($reason)
    {
        return match ($reason) {
            'address' => _t('PayPalExpressCheckoutPayment.PENDING.ADDRESS', 'A confirmed shipping address was not provided.'),
            'authorization' => _t('PayPalExpressCheckoutPayment.PENDING.AUTHORISATION', 'Payment has been authorised, but not settled.'),
            'echeck' => _t('PayPalExpressCheckoutPayment.PENDING.ECHECK', 'eCheck has not cleared.'),
            'intl' => _t('PayPalExpressCheckoutPayment.PENDING.INTERNATIONAL', 'International: payment must be accepted or denied manually.'),
            'multicurrency' => _t('PayPalExpressCheckoutPayment.PENDING.MULTICURRENCY', 'Multi-currency: payment must be accepted or denied manually.'),
            default => '',
        };
    }

    /**
     * Handle communication with PayPal API
     */
    protected function apiCall($method, $data = [])
    {
        $this->addDebugInfo('---------------------------------------');
        $this->addDebugInfo('API Call: ' . $method);
        $this->addDebugInfo('---------------------------------------');

        $postfields = [
            'METHOD' => $method,
            'VERSION' => $this->Config()->get('version'),
            'USER' => $this->Config()->get('API_UserName'),
            'PWD' => $this->Config()->get('API_Password'),
            'SIGNATURE' => $this->Config()->get('API_Signature'),
            'BUTTONSOURCE' => $this->Config()->get('sBNCode'),
        ];

        if (Config::inst()->get(PayPalExpressCheckoutPayment::class, 'debug')) {
            $this->addDebugInfo('STANDARD POSTING FIELDS: ' . print_r($postfields, 1));
            $this->addDebugInfo('ADDITIONAL POSTING FIELDS: ' . print_r($data, 1));
            $this->addDebugInfo('SENDING TO: ' . $this->getApiEndpoint());
        }

        $postfields = array_merge($postfields, $data);

        // Make POST request to PayPal via RESTful service
        $client = new Client([
            'base_uri' => $this->getApiEndpoint(),
            'headers' => [
                'Accept' => 'application/xml',
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            'timeout' => 10,
        ]);

        $response = $client->post('', [
            'form_params' => $postfields,
        ]);

        $status = $response->getStatusCode();
        $body = (string) $response->getBody();

        $formattedResponse = [
            'status' => $status,
            'body' => $body,
        ];
        $formattedResponse += $this->deformatNVP($body);

        $this->addDebugInfo('RESPONSE: ' . print_r($formattedResponse, 1));

        return $formattedResponse;
    }

    /**
     * Convert PayPal's Name-Value Pair response to associative array
     */
    protected function deformatNVP($nvpString)
    {
        $initial = 0;
        $nvpArray = [];

        while (strlen((string) $nvpString)) {
            // Position of key
            $keyPos = strpos((string) $nvpString, '=');
            // Position of value
            $valuePos = strpos((string) $nvpString, '&') ?: strlen((string) $nvpString);

            // Extract key and value
            $key = substr((string) $nvpString, $initial, $keyPos);
            $value = substr((string) $nvpString, $keyPos + 1, $valuePos - $keyPos - 1);

            // Store in array with URL decoding
            $nvpArray[urldecode($key)] = urldecode($value);
            $nvpString = substr((string) $nvpString, $valuePos + 1, strlen((string) $nvpString));
        }

        return $nvpArray;
    }

    /**
     * Get the appropriate API endpoint based on test mode
     */
    protected function getApiEndpoint()
    {
        return ($this->Config()->get('test_mode') === true)
            ? $this->Config()->get('test_API_Endpoint')
            : $this->Config()->get('API_Endpoint');
    }

    /**
     * Get the PayPal checkout URL with token
     */
    protected function getPayPalURL($token)
    {
        $url = ($this->Config()->get('test_mode') === true)
            ? $this->Config()->get('test_PAYPAL_URL')
            : $this->Config()->get('PAYPAL_URL');

        return $url . $token . '&useraction=commit'; // useraction=commit ensures payment is confirmed on PayPal
    }

    /**
     * Add debug information to the Debug field
     */
    protected function addDebugInfo($msg)
    {

        if (Config::inst()->get(PayPalExpressCheckoutPayment::class, 'debug')) {
            $this->Debug .= "---------//------------\n\n" . $msg;
            $this->write();
        } elseif (random_int(0, 10) === 0) {
            DB::query('UPDATE "PayPalExpressCheckoutPayment" SET "Debug" = null WHERE "Debug" IS NOT NULL');
        }
    }
}
