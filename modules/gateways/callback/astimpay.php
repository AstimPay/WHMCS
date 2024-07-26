<?php

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';

use Symfony\Component\HttpFoundation\Request;
use WHMCS\Database\Capsule;
use WHMCS\Config\Setting;
use GuzzleHttp\Client;
use Carbon\Carbon;

class AstimPay
{
    private static $instance;
    private $gatewayModuleName;
    private $gatewayParams;
    public  $isActive;
    private $gatewayCurrency;
    private $customerCurrency;
    private $convoRate;
    private $clientDetails;
    private $invoice;
    private $due;
    private $fee;
    private $total;
    private $baseUrl;
    public  $request;
    private $credential;

    private function __construct()
    {
        $this->setRequest();
        $this->setGateway();
        $this->setInvoice();
    }

    public static function init()
    {
        if (self::$instance == null) {
            self::$instance = new AstimPay;
        }

        return self::$instance;
    }

    private function setGateway()
    {
        $this->gatewayModuleName = basename(__FILE__, '.php');
        $this->gatewayParams = getGatewayVariables($this->gatewayModuleName);
        $this->isActive = !empty($this->gatewayParams['type']);
        $this->baseUrl = $this->normalizeBaseURL($this->gatewayParams['api_url']);

        $this->credential = [
            'api_key' => $this->gatewayParams['api_key']
        ];
    }

    private function normalizeBaseURL($apiBaseURL)
    {
        $baseURL = rtrim($apiBaseURL, '/');
        $apiSegmentPosition = strpos($baseURL, '/api');

        if ($apiSegmentPosition !== false) {
            $baseURL = substr($baseURL, 0, $apiSegmentPosition + 4); // Include '/api'
        }

        return $baseURL;
    }

    private function buildURL($endpoint)
    {
        $endpoint = ltrim($endpoint, '/');
        return $this->baseUrl . '/' . $endpoint;
    }

    private function setRequest()
    {
        $this->request = Request::createFromGlobals();
    }

    private function setInvoice()
    {
        $this->invoice = localAPI('GetInvoice', [
            'invoiceid' => $this->request->get('id'),
        ]);

        $this->setCurrency();
        $this->setClient();
        $this->setDue();
        $this->setFee();
        $this->setTotal();
    }

    private function setCurrency()
    {
        $this->gatewayCurrency = (int) $this->gatewayParams['convertto'];
        $this->customerCurrency = (int) Capsule::table('tblclients')
            ->where('id', '=', $this->invoice['userid'])
            ->value('currency');

        if (!empty($this->gatewayCurrency) && ($this->customerCurrency !== $this->gatewayCurrency)) {
            $this->convoRate = $this->gatewayParams['exchange_rate'];
        } else {
            $this->convoRate = 1;
        }
    }

    private function setClient()
    {
        $this->clientDetails = Capsule::table('tblclients')
            ->where('id', '=', $this->invoice['userid'])
            ->first();
    }

    private function setDue()
    {
        $this->due = $this->invoice['balance'];
    }

    private function setFee()
    {
        $this->fee = 0;
    }

    private function setTotal()
    {
        $this->total = ceil(($this->due + $this->fee) * $this->convoRate);
    }

    private function checkTransaction($trxId)
    {
        return localAPI('GetTransactions', ['transid' => $trxId]);
    }

    private function logTransaction($payload)
    {
        return logTransaction(
            $this->gatewayParams['name'],
            [
                $this->gatewayModuleName => $payload,
                'request_data' => $this->request->request->all(),
            ],
            $payload['transactionStatus']
        );
    }

    private function addTransaction($trxId)
    {
        $fields = [
            'invoiceid' => $this->invoice['invoiceid'],
            'transid' => $trxId,
            'gateway' => $this->gatewayModuleName,
            'date' => Carbon::now()->toDateTimeString(),
            'amount' => $this->due,
            'fees' => $this->fee
        ];
        $add = localAPI('AddInvoicePayment', $fields);

        return array_merge($add, $fields);
    }

    private function initPayment($requestData, $apiType = 'checkout-v1')
    {
        $apiUrl = $this->buildURL($apiType);
        $response = $this->sendRequest('POST', $apiUrl, $requestData);
        $this->validateApiResponse($response, 'Payment request failed');
        return $response;
    }

    private function verifyPayment($invoiceId)
    {
        $verifyUrl = $this->buildURL('verify-payment');
        $requestData = ['invoice_id' => $invoiceId];
        return $this->sendRequest('POST', $verifyUrl, $requestData);
    }

    private function executePayment()
    {
        $headerApi = $_SERVER['API-KEY'] ?? null;
        $this->validateApiHeader($headerApi);

        $rawInput = trim(file_get_contents('php://input'));
        $this->validateIpnResponse($rawInput);

        $data = json_decode($rawInput, true);
        $invoiceId = $data['invoice_id'];

        return $this->verifyPayment($invoiceId);
    }

    private function sendRequest($method, $url, $data)
    {
        $client = new Client();
        $response = $client->request($method, $url, [
            'body' => json_encode($data),
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'API-KEY' => $this->credential['api_key']
            ],
            'verify' => false,
            'timeout' => 30
        ]);

        $data = json_decode($response->getBody(), true);
        if (is_array($data)) {
            return $data;
        }
        throw new \Exception("Invalid response from AstimPay API.");
    }

    private function validateApiHeader($headerApi)
    {
        if ($headerApi === null) {
            throw new \Exception("Invalid API Key");
        }

        $apiKey = trim($this->credential['api_key']);

        if ($headerApi !== $apiKey) {
            throw new \Exception("Unauthorized Action.");
        }
    }

    private function validateApiResponse($response, $errorMessage)
    {
        if (!isset($response['payment_url'])) {
            $message = isset($response['message']) ? $response['message'] : $errorMessage;
            throw new \Exception($message);
        }
    }

    private function validateIpnResponse($rawInput)
    {
        if (empty($rawInput)) {
            throw new \Exception("Invalid response from AstimPay API.");
        }
    }

    public function createPayment()
    {
        $systemUrl = Setting::getValue('SystemURL');
        $callbackURL = $systemUrl . '/modules/gateways/callback/' . $this->gatewayModuleName . '.php?id=' . $this->invoice['invoiceid'] . '&action=ipn';
        $successURL = $systemUrl . '/modules/gateways/callback/' . $this->gatewayModuleName . '.php?id=' . $this->invoice['invoiceid'] . '&action=verify';
        $cancelURL = $systemUrl . '/viewinvoice.php?error=cancelled&id=' . $this->invoice['invoiceid'];
        $firstName = $this->clientDetails->firstname;
        $lastName = $this->clientDetails->lastname;
        $customerName = $firstName . ' ' . $lastName;
        $email = $this->clientDetails->email;

        $fields = [
            "full_name" => $customerName,
            "email" => $email,
            "amount" => $this->total,
            "metadata" => [
                'invoice_id' => $this->invoice['invoiceid']
            ],
            "redirect_url" => $successURL,
            "return_type" => "GET",
            "cancel_url" => $cancelURL,
            "webhook_url" => $callbackURL
        ];

        try {
            $response = $this->initPayment($fields);

            if (is_array($response) && isset($response['payment_url'])) {
                return [
                    'status' => 'success',
                    'payment_url' => $response['payment_url']
                ];
            }
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage(),
                'errorCode' => 'irs'
            ];
        }
    }

    public function makeTransaction($invoiceId, $ipn = false)
    {
        try {
            if ($ipn) {
                $executePayment = $this->executePayment();
            } else {
                $executePayment = $this->verifyPayment($invoiceId);
            }

            if (isset($executePayment['status']) && $executePayment['status'] === 'COMPLETED') {
                $existing = $this->checkTransaction($executePayment['transaction_id']);

                if ($existing['totalresults'] > 0) {
                    return [
                        'status' => 'error',
                        'message' => 'The transaction has already been used.',
                        'errorCode' => 'tau'
                    ];
                }

                if ($executePayment['amount'] < $this->total) {
                    return [
                        'status' => 'error',
                        'message' => 'You\'ve paid less than the required amount.',
                        'errorCode' => 'lpa'
                    ];
                }

                $this->logTransaction($executePayment);

                $trxAddResult = $this->addTransaction($executePayment['transaction_id']);

                if ($trxAddResult['result'] === 'success') {
                    return [
                        'status' => 'success',
                        'message' => 'The payment has been successfully verified.',
                    ];
                }
            } elseif (isset($executePayment['status']) && $executePayment['status'] === 'PENDING') {
                $invoiceId = $this->invoice['invoiceid'];
                $_SESSION["up_pending_invoice_id_{$invoiceId}"] = 'Your payment is pending for verification.';
                return [
                    'status' => 'error',
                    'message' => 'Your payment is pending for verification.',
                    'errorCode' => 'pfv'
                ];
            }
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage(),
                'errorCode' => 'irs'
            ];
        }
    }
}

$AstimPay = AstimPay::init();
if (!$AstimPay->isActive) {
    die("The gateway is unavailable.");
}

$action = $AstimPay->request->get('action');
$invid = $AstimPay->request->get('id');

if ($action === 'init') {
    $response = $AstimPay->createPayment();
    if ($response['status'] === 'success') {
        header('Location: ' . $response['payment_url']);
        exit;
    } else {
        redirSystemURL("id={$invid}&error={$response['message']}", "viewinvoice.php");
        exit;
    }
}
if ($action === 'verify') {
    $invoiceId = $AstimPay->request->get('invoice_id');
    $response = $AstimPay->makeTransaction($invoiceId);
    if ($response['status'] === 'success') {
        redirSystemURL("id={$invid}", "viewinvoice.php");
        exit;
    } else {
        redirSystemURL("id={$invid}&error={$response['errorCode']}", "viewinvoice.php");
        exit;
    }
}

if ($action === 'ipn') {
    $invoiceId = $AstimPay->request->get('invoice_id');
    $response = $AstimPay->makeTransaction($invoiceId, true);
    exit;
}

redirSystemURL("id={$invid}&error=sww", "viewinvoice.php");