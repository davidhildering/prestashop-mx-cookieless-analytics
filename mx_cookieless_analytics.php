<?php
/**
 * MX Cookieless Analytics for PrestaShop.
 *
 * Cookieless, privacy-friendly web analytics. EU-hosted, GDPR-friendly, no
 * consent banner needed. Tracking is strictly opt-in: OFF until the merchant
 * pastes a site API key and enables it in the module settings.
 *
 * Connect flow (same contract as the WordPress/Grav plugins):
 *   1. module  → POST /api/integrations/prestashop/challenge (Bearer api_key)
 *                → stores the token in Configuration, served at
 *                  GET /index.php?fc=module&module=mx_cookieless_analytics&controller=challenge
 *   2. module  → POST /api/integrations/prestashop/verify (Bearer api_key)
 *                → the API fetches the challenge URL on the shop's public
 *                  domain and compares → site.verified = true
 *
 * Commerce (order_completed) is sent server-side via hookActionValidateOrder
 * — see docs/prestashop-plugin-plan.md.
 *
 * @license MIT License
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class Mx_Cookieless_Analytics extends Module
{
    /**
     * Tracker script version (cache-bust key on /tracker.js?v=).
     * MUST match TRACKER_VERSION in @metrixs/types — bump together with a
     * tracker release and publish a new module release so shops fetch the
     * fresh script.
     */
    const TRACKER_VERSION = '1.2.0';

    public function __construct()
    {
        $this->name = 'mx_cookieless_analytics';
        $this->tab = 'analytics_stats';
        $this->version = '1.1.1';
        $this->author = 'MetriXs Analytics';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = array('min' => '8.0.0', 'max' => _PS_VERSION_);
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->trans('MX Cookieless Analytics', array(), 'Modules.Mxcookielessanalytics.Admin');
        $this->description = $this->trans(
            'Cookieless, privacy-friendly web analytics. Opt-in tracking (off until you connect), GDPR-friendly, EU-hosted. Automatic shop verification, no DNS records needed.',
            array(),
            'Modules.Mxcookielessanalytics.Admin'
        );
        $this->confirmUninstall = $this->trans(
            'Disconnect this shop from MetriXs? Tracking stops immediately.',
            array(),
            'Modules.Mxcookielessanalytics.Admin'
        );
    }

    public function install()
    {
        return parent::install()
            && $this->registerHook('displayHeader')
            && $this->registerHook('actionValidateOrder')
            && Configuration::updateValue('MXCOAN_ENABLED', 0)
            && Configuration::updateValue('MXCOAN_API_KEY', '')
            && Configuration::updateValue('MXCOAN_API_BASE', 'https://app.metrixs.eu')
            && Configuration::updateValue('MXCOAN_DOMAIN', '')
            && Configuration::updateValue('MXCOAN_EXCLUDE_LOGGED_IN', 1)
            && Configuration::updateValue('MXCOAN_CONNECTED', 0);
    }

    public function uninstall()
    {
        // Best-effort: tell MetriXs the shop disconnected (marks the site
        // unverified so ingestion stops). The API key may already be revoked;
        // either way it must not block uninstallation.
        $apiKey = (string) Configuration::get('MXCOAN_API_KEY');
        if ($apiKey !== '') {
            $this->apiPost('/api/integrations/prestashop/uninstall', array(), $apiKey);
        }

        Configuration::deleteByName('MXCOAN_ENABLED');
        Configuration::deleteByName('MXCOAN_API_KEY');
        Configuration::deleteByName('MXCOAN_API_BASE');
        Configuration::deleteByName('MXCOAN_DOMAIN');
        Configuration::deleteByName('MXCOAN_EXCLUDE_LOGGED_IN');
        Configuration::deleteByName('MXCOAN_CONNECTED');
        Configuration::deleteByName('MXCOAN_CHALLENGE');

        return parent::uninstall();
    }

    // ─── Admin configuration ───────────────────────────────────────────────

    /**
     * Settings page. Saves the form, then runs the connect flow (challenge →
     * verify) when an API key is set but the shop is not verified yet.
     *
     * @return string
     */
    public function getContent()
    {
        $output = '';

        // One-click connect (Phase 2, docs/mobile-onboarding-plan.md), all
        // within the admin-only module config page (AdminModules requires a
        // back-office session, so both the start and the dashboard callback
        // are employee-gated; the one-time `state` is the CSRF token).
        //   ?mxcoan_oauth=start    → store a one-time state, redirect to the
        //                            dashboard's /connect/prestashop page
        //   ?mxcoan_oauth=callback ← dashboard redirect with ?code=…&state=…:
        //                            exchange the pair for a site-scoped API
        //                            key (single-use, returned once), then
        //                            run the normal challenge → verify
        //                            handshake.
        $oauth = (string) Tools::getValue('mxcoan_oauth');
        if ($oauth === 'start') {
            $this->oauthStart();
        }
        if ($oauth === 'callback') {
            $this->oauthCallback();
        }

        if (Tools::isSubmit('submit' . $this->name)) {
            Configuration::updateValue('MXCOAN_ENABLED', (int) Tools::getValue('MXCOAN_ENABLED'));
            Configuration::updateValue('MXCOAN_API_KEY', $this->sanitizeApiKey(Tools::getValue('MXCOAN_API_KEY')));
            Configuration::updateValue('MXCOAN_API_BASE', $this->sanitizeApiBase(Tools::getValue('MXCOAN_API_BASE')));
            Configuration::updateValue('MXCOAN_DOMAIN', $this->sanitizeDomain(Tools::getValue('MXCOAN_DOMAIN')));
            Configuration::updateValue('MXCOAN_EXCLUDE_LOGGED_IN', (int) Tools::getValue('MXCOAN_EXCLUDE_LOGGED_IN'));

            // Immediate connect chance right after saving (attemptConnect is
            // throttled by MXCOAN_LAST_CONNECT so a failing verification
            // cannot slow down every settings visit).
            $this->attemptConnect(true);
        }

        $output .= $this->statusBanner();
        $output .= $this->renderForm();

        return $output;
    }

    /**
     * Connection status banner above the form.
     *
     * @return string
     */
    private function statusBanner()
    {
        if (!Configuration::get('MXCOAN_CONNECTED')) {
            // One-click connect CTA (Phase 2) — the recommended path.
            $oauthUrl = $this->configPageUrl() . '&mxcoan_oauth=start';
            $html = Module::displayConfirmation(
                $this->trans(
                    'Recommended: one-click connect. Creates (or opens) your MetriXs account and connects + verifies this shop automatically. No API key copying.',
                    array(),
                    'Modules.Mxcookielessanalytics.Admin'
                )
                . ' <a class="button" href="' . $oauthUrl . '">Connect with MetriXs</a>'
            );
            if ((string) Configuration::get('MXCOAN_API_KEY') !== '') {
                $html .= Module::displayError($this->trans(
                    'Not verified yet — saving the form retries the automatic verification. Check your API key and that this shop is publicly reachable over HTTPS.',
                    array(),
                    'Modules.Mxcookielessanalytics.Admin'
                ));
            }
            return $html;
        }
        if (!Configuration::get('MXCOAN_ENABLED')) {
            return Module::displayConfirmation($this->trans(
                'Shop verified. Tracking starts as soon as you enable tracking below.',
                array(),
                'Modules.Mxcookielessanalytics.Admin'
            ));
        }
        return Module::displayConfirmation($this->trans(
            'Shop verified — tracking is live. Your dashboard: app.metrixs.eu',
            array(),
            'Modules.Mxcookielessanalytics.Admin'
        ));
    }

    /**
     * @return string
     */
    private function renderForm()
    {
        $fields_form = array(
            'form' => array(
                'legend' => array(
                    'title' => $this->trans('Settings', array(), 'Admin.Global'),
                    'icon' => 'icon-cogs',
                ),
                'input' => array(
                    array(
                        'type' => 'switch',
                        'label' => $this->trans('Enable tracking', array(), 'Modules.Mxcookielessanalytics.Admin'),
                        'desc' => $this->trans('Tracking is opt-in. Nothing is loaded or sent until the shop is connected and this is on.', array(), 'Modules.Mxcookielessanalytics.Admin'),
                        'name' => 'MXCOAN_ENABLED',
                        'is_bool' => true,
                        'values' => array(
                            array('id' => 'on', 'value' => 1, 'label' => $this->trans('Yes', array(), 'Admin.Global')),
                            array('id' => 'off', 'value' => 0, 'label' => $this->trans('No', array(), 'Admin.Global')),
                        ),
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->trans('Site API key', array(), 'Modules.Mxcookielessanalytics.Admin'),
                        'desc' => $this->trans('Create one in the MetriXs dashboard under Settings → Sites → API keys. Verification runs automatically on save.', array(), 'Modules.Mxcookielessanalytics.Admin'),
                        'name' => 'MXCOAN_API_KEY',
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->trans('MetriXs URL', array(), 'Modules.Mxcookielessanalytics.Admin'),
                        'desc' => $this->trans('Only change this for a self-hosted MetriXs endpoint.', array(), 'Modules.Mxcookielessanalytics.Admin'),
                        'name' => 'MXCOAN_API_BASE',
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->trans('Shop domain', array(), 'Modules.Mxcookielessanalytics.Admin'),
                        'desc' => $this->trans('Leave empty to auto-detect from this shop.', array(), 'Modules.Mxcookielessanalytics.Admin'),
                        'name' => 'MXCOAN_DOMAIN',
                    ),
                    array(
                        'type' => 'switch',
                        'label' => $this->trans('Exclude logged-in customers', array(), 'Modules.Mxcookielessanalytics.Admin'),
                        'desc' => $this->trans('Never count visits of logged-in customer accounts (your own test orders included).', array(), 'Modules.Mxcookielessanalytics.Admin'),
                        'name' => 'MXCOAN_EXCLUDE_LOGGED_IN',
                        'is_bool' => true,
                        'values' => array(
                            array('id' => 'on', 'value' => 1, 'label' => $this->trans('Yes', array(), 'Admin.Global')),
                            array('id' => 'off', 'value' => 0, 'label' => $this->trans('No', array(), 'Admin.Global')),
                        ),
                    ),
                ),
                'submit' => array(
                    'title' => $this->trans('Save', array(), 'Admin.Global'),
                ),
            ),
        );

        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->module = $this;
        $helper->default_form_language = (int) Configuration::get('PS_LANG_DEFAULT');
        $helper->allow_employee_form_lang = (int) Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG');
        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submit' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->tpl_vars = array(
            'fields_value' => array(
                'MXCOAN_ENABLED' => (bool) Configuration::get('MXCOAN_ENABLED'),
                'MXCOAN_API_KEY' => (string) Configuration::get('MXCOAN_API_KEY'),
                'MXCOAN_API_BASE' => (string) Configuration::get('MXCOAN_API_BASE'),
                'MXCOAN_DOMAIN' => (string) Configuration::get('MXCOAN_DOMAIN'),
                'MXCOAN_EXCLUDE_LOGGED_IN' => (bool) Configuration::get('MXCOAN_EXCLUDE_LOGGED_IN'),
            ),
            'languages' => $this->context->controller->getLanguagesUsedAndSorted(),
        );

        return $helper->generateForm(array($fields_form));
    }

    // ─── Tracker injection ─────────────────────────────────────────────────

    /**
     * Inject the tracker into every page head.
     *
     * Strictly opt-in: nothing is returned until the shop is connected AND
     * tracking is enabled. Activation alone loads nothing.
     *
     * @return string
     */
    public function hookDisplayHeader()
    {
        if (!Configuration::get('MXCOAN_ENABLED') || !Configuration::get('MXCOAN_CONNECTED')) {
            return '';
        }

        if (
            Configuration::get('MXCOAN_EXCLUDE_LOGGED_IN')
            && isset($this->context->customer)
            && $this->context->customer->isLogged(true)
        ) {
            return '';
        }

        $domain = $this->siteDomain();
        $apiBase = rtrim((string) Configuration::get('MXCOAN_API_BASE'), '/');
        if ($apiBase === '') {
            $apiBase = 'https://app.metrixs.eu';
        }
        $src = $apiBase . '/tracker.js?v=' . self::TRACKER_VERSION;

        return '<script defer data-domain="' . htmlspecialchars($domain, ENT_QUOTES, 'UTF-8')
            . '" src="' . htmlspecialchars($src, ENT_QUOTES, 'UTF-8') . '"></script>';
    }

    // ─── Commerce (server-side, captures redirect payments) ────────────────
    // hookActionValidateOrder is wired in phase 3; the hook is registered at
    // install so enabling commerce later needs no reinstall.

    /**
     * Order created (server-side, exactly once, payment-flow independent).
     * Sends order_completed with the same payload shape as the Shopify pixel
     * and the WooCommerce plugin: total, currency, items, discount_code.
     *
     * @param array $params
     * @return void
     */
    public function hookActionValidateOrder(array $params)
    {
        if (!Configuration::get('MXCOAN_ENABLED') || !Configuration::get('MXCOAN_CONNECTED')) {
            return;
        }
        $apiKey = (string) Configuration::get('MXCOAN_API_KEY');
        if ($apiKey === '' || !isset($params['order'], $params['currency'])) {
            return;
        }

        $order = $params['order'];
        $items = array();
        $discountCode = '';
        foreach ((array) $order->getProducts() as $line) {
            $items[] = array(
                'title' => (string) $line['product_name'],
                'price' => (float) $line['unit_price_tax_incl'],
                'quantity' => (int) $line['product_quantity'],
            );
        }
        if (isset($params['cart']) && is_object($params['cart'])) {
            $cartRules = (array) $params['cart']->getOrderTotal(true, Cart::ONLY_DISCOUNTS);
            if (!empty($cartRules)) {
                // First cart rule with a code, if any (best-effort; the shop
                // may use rules without codes, e.g. free shipping).
                foreach ((array) $params['cart']->getCartRules() as $rule) {
                    if (!empty($rule['code'])) {
                        $discountCode = (string) $rule['code'];
                        break;
                    }
                }
            }
        }

        // Body shape mirrors the tracker payload (n, u, d, p): the /api/event
        // zod schema (trackSchema) requires n + u for BOTH auth paths, and
        // the commerce fields are props under p (normalizeCommerceProps
        // converts total + item prices to EUR at ingest from p.currency).
        $itemsJson = json_encode($items);
        // Prop values are capped at 2000 chars server-side; a truncated JSON
        // would be invalid, so on overflow retry with the first item only,
        // else drop the prop (revenue stays correct, top-products partial).
        if ($itemsJson !== false && strlen($itemsJson) > 2000 && isset($items[0])) {
            $single = json_encode(array($items[0]));
            $itemsJson = ($single !== false && strlen($single) <= 2000) ? $single : '';
        }

        $props = array(
            'total' => (float) $order->total_paid_tax_incl,
            'currency' => (string) $params['currency']->iso_code,
            'order_id' => (string) $order->id,
            'items' => $itemsJson === false || $itemsJson === '' ? null : $itemsJson,
        );
        if ($discountCode !== '') {
            $props['discount_code'] = $discountCode;
        }

        $payload = array(
            'n' => 'order_completed',
            'u' => 'https://' . $this->siteDomain() . '/order-confirmation/' . (int) $order->id,
            'd' => $this->siteDomain(),
            'p' => $props,
        );

        $this->apiPost('/api/event', $payload, $apiKey, false);
    }

    // ─── Connect flow ──────────────────────────────────────────────────────

    /**
     * Run the challenge → verify handshake if an API key is set but the shop
     * is not verified yet. Throttled (one attempt per 5 minutes) unless
     * $force — a failing verification must never slow down every settings
     * visit.
     *
     * @param bool $force
     * @return void
     */
    /**
     * One-click connect, step 1: generate a one-time state and redirect the
     * employee to the MetriXs dashboard's /connect/prestashop page.
     *
     * @return void
     */
    private function oauthStart()
    {
        $state = bin2hex(random_bytes(16));
        Configuration::updateValue('MXCOAN_OAUTH_STATE', $state);
        Configuration::updateValue('MXCOAN_OAUTH_EXPIRES', time() + 900);

        $domain = (string) Configuration::get('MXCOAN_DOMAIN');
        if ($domain === '') {
            $domain = preg_replace('#^www\.#', '', (string) Tools::getShopDomainSsl(false));
        }

        $url = $this->apiBase() . '/connect/prestashop?' . http_build_query(array(
            'state' => $state,
            'site' => $domain,
            'back' => $this->configPageUrl() . '&mxcoan_oauth=callback',
        ));

        Tools::redirect($url);
    }

    /**
     * One-click connect, step 2 (dashboard callback): validate the single-use
     * state (hash_equals, cleared before any use), exchange { code, state }
     * for the API key and run the challenge → verify handshake.
     *
     * @return void
     */
    private function oauthCallback()
    {
        $code = preg_replace('/[^0-9a-f]/', '', (string) Tools::getValue('code'));
        $state = preg_replace('/[^A-Za-z0-9]/', '', (string) Tools::getValue('state'));

        $stored = (string) Configuration::get('MXCOAN_OAUTH_STATE');
        $expires = (int) Configuration::get('MXCOAN_OAUTH_EXPIRES');
        // Clear BEFORE any use — the state is single-use.
        Configuration::updateValue('MXCOAN_OAUTH_STATE', '');
        Configuration::updateValue('MXCOAN_OAUTH_EXPIRES', 0);

        if ($code === '' || $stored === '' || !hash_equals($stored, $state) || time() > $expires) {
            Tools::redirectAdmin($this->configPageUrl() . '&mxcoan_oauth_msg=failed');
        }

        $exchange = $this->apiPost('/api/integrations/prestashop/exchange', array(
            'code' => $code,
            'state' => $state,
        ), '', true);
        if (empty($exchange['ok']) || empty($exchange['data']['apiKey'])) {
            Tools::redirectAdmin($this->configPageUrl() . '&mxcoan_oauth_msg=failed');
        }

        Configuration::updateValue('MXCOAN_API_KEY', $this->sanitizeApiKey((string) $exchange['data']['apiKey']));

        // Normal challenge → verify handshake with the fresh key.
        $this->attemptConnect(true);
        Tools::redirectAdmin($this->configPageUrl());
    }

    /**
     * The back-office URL of this module's config page (token included, so
     * it is a valid redirect target for the dashboard's callback).
     *
     * @return string
     */
    private function configPageUrl()
    {
        return $this->context->link->getAdminLink('AdminModules', true)
            . '&configure=' . $this->name;
    }

    /**
     * The MetriXs API base (HTTPS only, default fallback).
     *
     * @return string
     */
    private function apiBase()
    {
        $apiBase = rtrim((string) Configuration::get('MXCOAN_API_BASE'), '/');
        if ($apiBase === '' || stripos($apiBase, 'https://') !== 0) {
            $apiBase = 'https://app.metrixs.eu';
        }

        return $apiBase;
    }

    private function attemptConnect($force = false)
    {
        $apiKey = (string) Configuration::get('MXCOAN_API_KEY');
        if ($apiKey === '' || Configuration::get('MXCOAN_CONNECTED')) {
            return;
        }

        $last = (int) Configuration::get('MXCOAN_LAST_CONNECT');
        if (!$force && (time() - $last) < 300) {
            return;
        }
        Configuration::updateValue('MXCOAN_LAST_CONNECT', time());

        // Step 1: request a challenge and store it so the challenge
        // controller can serve it on the public domain.
        $challenge = $this->apiPost('/api/integrations/prestashop/challenge', array(), $apiKey);
        if (empty($challenge['ok']) || empty($challenge['data']['challenge'])) {
            $this->context->controller->errors[] = $this->trans(
                'MX Cookieless Analytics: could not reach MetriXs to start verification. Check your site API key.',
                array(),
                'Modules.Mxcookielessanalytics.Admin'
            );
            return;
        }
        Configuration::updateValue('MXCOAN_CHALLENGE', (string) $challenge['data']['challenge']);

        // Step 2: ask the API to verify. It fetches the challenge back from
        // the shop's public domain — only a party controlling this domain
        // can serve the token.
        $verify = $this->apiPost('/api/integrations/prestashop/verify', array(), $apiKey);
        if (!empty($verify['ok']) && !empty($verify['data']['ok'])) {
            Configuration::updateValue('MXCOAN_CONNECTED', 1);
            $this->context->controller->confirmations[] = $this->trans(
                'MX Cookieless Analytics: shop verified. Tracking starts once "Enable tracking" is on.',
                array(),
                'Modules.Mxcookielessanalytics.Admin'
            );
        } else {
            $this->context->controller->errors[] = $this->trans(
                'MX Cookieless Analytics: verification failed — the module could not serve the challenge token on your public domain (check HTTPS and hosting security modules). Saving again retries.',
                array(),
                'Modules.Mxcookielessanalytics.Admin'
            );
        }
    }

    /**
     * POST (or GET-style JSON) to a MetriXs API endpoint.
     *
     * @param string $path   API path, e.g. '/api/integrations/prestashop/verify'.
     * @param mixed  $body   JSON-encodable body.
     * @param string $apiKey Bearer key; defaults to the stored one.
     * @param bool   $assoc  Decode JSON responses as arrays (default).
     * @return array{ok: bool, status: int, data: array|null}
     */
    private function apiPost($path, $body = array(), $apiKey = null, $assoc = true)
    {
        if ($apiKey === null) {
            $apiKey = (string) Configuration::get('MXCOAN_API_KEY');
        }
        $apiBase = rtrim((string) Configuration::get('MXCOAN_API_BASE'), '/');

        $ch = curl_init($apiBase . $path);
        curl_setopt_array($ch, array(
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER => array(
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
            ),
        ));
        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);

        if ($response === false || $error !== '') {
            return array('ok' => false, 'status' => 0, 'data' => null);
        }

        $data = json_decode((string) $response, $assoc);

        return array(
            'ok' => $status >= 200 && $status < 300,
            'status' => $status,
            'data' => is_array($data) ? $data : null,
        );
    }

    // ─── helpers ───────────────────────────────────────────────────────────

    /**
     * The shop domain for the data-domain attribute: the configured domain,
     * or the current shop's canonical domain.
     *
     * @return string
     */
    private function siteDomain()
    {
        $configured = strtolower(trim((string) Configuration::get('MXCOAN_DOMAIN')));
        if ($configured !== '') {
            return $configured;
        }
        $host = '';
        if (isset($this->context->shop) && $this->context->shop->domain) {
            $host = (string) $this->context->shop->domain;
        } else {
            $host = (string) Tools::getHttpHost(false, false);
        }
        // Strip a port suffix (dev/staging shops on non-standard ports): the
        // MetriXs site domain never carries one.
        $host = preg_replace('/:[0-9]+$/', '', $host);

        return $host;
    }

    /**
     * @param string $raw
     * @return string
     */
    private function sanitizeApiKey($raw)
    {
        return preg_replace('/[^A-Za-z0-9_\-]/', '', trim((string) $raw));
    }

    /**
     * @param string $raw
     * @return string
     */
    private function sanitizeApiBase($raw)
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return 'https://app.metrixs.eu';
        }
        $raw = preg_replace('#/+$#', '', $raw);
        if (!preg_match('#^https?://#i', $raw)) {
            return 'https://app.metrixs.eu';
        }

        return $raw;
    }

    /**
     * @param string $raw
     * @return string
     */
    private function sanitizeDomain($raw)
    {
        $raw = strtolower(trim((string) $raw));
        $raw = preg_replace('#^https?://#i', '', $raw);
        $raw = preg_replace('#^www\.#i', '', $raw);
        $raw = preg_replace('#/.*$#', '', $raw);

        return preg_replace('/[^a-z0-9.\-]/', '', $raw);
    }
}
