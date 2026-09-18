<?php

namespace Controller;

use Core\Session;
use const DIRECTORY_SEPARATOR;

function response($response)
{
    header("Content-Type: application/json; charset=UTF-8;");
    $response = json_encode($response, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_QUOT);
    echo $response;
    exit(0);
}



class AjaxCtrl extends AnyCtrl
{
    public function __construct()
    {
        parent::__construct();
        $response = ["response" => ['error' => FALSE, 'errorMsg' => NULL, 'data' => [],],];
        if (isset($_GET['cmd'])) {
            $cmd = filter_var($_GET['cmd'], FILTER_SANITIZE_STRING);
            $response = ["response" => ['error' => FALSE, 'errorMsg' => NULL, 'data' => [],],];
            if (!in_array($cmd, ['news', 'configuration'])) {
                $this->checkAjaxToken($response);
            }
            // Only the checkout is off: it redirects to a payment host this fork does not
            // ship. paymentWizard stays reachable because its buyGold tab already falls
            // back to the "payment unavailable" view, and its other tabs are where players
            // spend the gold they already hold (Plus, gold club, production boosts).
            // Answer with markup rather than an error: the dialog drops errorMsg on the
            // floor and would render an empty box.
            if (in_array($cmd, ['paymentProviders', 'paymentRules'], true)) {
                $response['response']['data']['html'] = '<div class="buyGoldContent paymentWizardDirection'
                    . getDirection() . '"><div class="error">'
                    . T("PaymentWizard", "paymentUnAvailable") . '</div></div>';
                response($response);
            }
            if (!file_exists(__DIR__ . DIRECTORY_SEPARATOR . "Ajax" . DIRECTORY_SEPARATOR . $cmd . ".php")) {
                $response['response']['error'] = TRUE;
                $response['response']['errorMsg'] = "Parameter \"$cmd\" (ajax.php) is not valid in \"cmd\".";
                $response['response']['data'] = [];
                response($response);
            }
            $cmd = '\\Controller\\Ajax\\' . $cmd;
            $dispatcher = new $cmd($response['response']);
            if (method_exists($dispatcher, "dispatch")) {
                $dispatcher->dispatch();
            }
            response($response);
        } else {
            $response['response']['error'] = TRUE;
            $response['response']['errorMsg'] = "Parameter \"cmd\" can not be empty or null.";
            $response['response']['data'] = [];
            response($response);
        }
    }
    function checkAjaxToken(&$response)
    {
        $providedToken = $_POST['ajaxToken'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        $expectedToken = (string)$this->session->getAjaxToken();
        if ($providedToken === '' || !hash_equals($expectedToken, (string)$providedToken)) {
            $response['ajaxToken'] = NULL;
            $response['response']['error'] = TRUE;
            $response['response']['errorMsg'] = 'Invalid token.';
            response($response);
        }
        return TRUE;
    }
}
