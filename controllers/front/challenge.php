<?php
/**
 * Challenge front controller: serves the site-verification token as JSON.
 *
 * Reachable at (always, regardless of pretty URLs or language prefix):
 *   /index.php?fc=module&module=mx_cookieless_analytics&controller=challenge
 *
 * The MetriXs API fetches this URL during verification — only a party
 * controlling the shop's domain can serve the stored token, which proves
 * domain ownership (same mechanism as Google site verification).
 *
 * With no token stored the endpoint answers 404: it does not exist until
 * the merchant connects.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class mx_cookieless_analyticschallengeModuleFrontController extends ModuleFrontController
{
    /** @var bool */
    public $ssl = true;

    /**
     * Output the JSON challenge and stop. No theme, no Smarty.
     *
     * @return void
     */
    public function initContent()
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');

        $token = (string) Configuration::get('MXCOAN_CHALLENGE');
        if (!preg_match('/^[0-9a-f]{64}$/', $token)) {
            // No (valid) challenge stored — the endpoint does not exist yet.
            http_response_code(404);
            echo json_encode(array('error' => 'not_found'));
            exit;
        }

        echo json_encode(array('challenge' => $token));
        exit;
    }
}
