<?php
/**
 * HiPay Enterprise SDK WooCommerce
 *
 * 2018 HiPay
 *
 * NOTICE OF LICENSE
 *
 * @author    HiPay <support.tpp@hipay.com>
 * @copyright 2018 HiPay
 * @license   https://github.com/hipay/hipay-enterprise-sdk-woocommerce/blob/master/LICENSE.md
 */

if (!defined('ABSPATH')) {
    exit;
    // Exit if accessed directly
}

/**
 *
 * @author      HiPay <support.tpp@hipay.com>
 * @copyright   Copyright (c) 2018 - HiPay
 * @license     https://github.com/hipay/hipay-enterprise-sdk-woocommerce/blob/master/LICENSE.md
 * @link    https://github.com/hipay/hipay-enterprise-sdk-woocommerce
 */
if (!class_exists('WC_Gateway_Hipay')) {
    class Gateway_Hipay extends Hipay_Gateway_Abstract
    {

        const CREDIT_CARD_PAYMENT_PRODUCT = "credit_card";

        const GATEWAY_CREDIT_CARD_ID = 'hipayenterprise_credit_card';

        /**
         * Gateway_Hipay constructor.
         */
        public function __construct()
        {
            $this->id = self::GATEWAY_CREDIT_CARD_ID;
            $this->paymentProduct = self::CREDIT_CARD_PAYMENT_PRODUCT;

            parent::__construct();

            $this->supports = array(
                'products',
                'refunds',
                'captures'
            );

            $this->has_fields = true;
            $this->icon = WC_HIPAYENTERPRISE_URL_ASSETS . '/images/credit_card.png';
            $this->method_title = __('HiPay Enterprise Credit Card', "hipayenterprise");

            $this->method_description = __(
                'Local and international payments using Hipay Enterprise.',
                "hipayenterprise"
            );

            if (!empty($this->confHelper->getPaymentGlobal()["ccDisplayName"][Hipay_Helper::getLanguage()])) {
                $this->title = $this->confHelper->getPaymentGlobal()["ccDisplayName"][Hipay_Helper::getLanguage()];
            } else {
                $this->title = $this->confHelper->getPaymentGlobal()["ccDisplayName"]['en'];
            }

            $this->init_form_fields();
            $this->init_settings();
            $this->confHelper->initConfigHiPay();
            $this->addActions();
            $this->logs = new Hipay_Log($this);
            $this->apiRequestHandler = new Hipay_Api_Request_Handler($this);
            $this->settingsHandler = new Hipay_Settings_Handler($this);
            $this->icon = WC_HIPAYENTERPRISE_URL_ASSETS . '/images/credit_card.png';

            if ($this->confHelper->getPaymentGlobal()["card_token"]) {
                $this->supports[] = 'tokenization';
            }

            add_filter('woocommerce_available_payment_gateways', array($this, 'removeFromMyAccount'));

            if ($this->isAvailable()
                && is_page()
                && (is_checkout() || is_add_payment_method_page())
                && !is_order_received_page()) {
                wp_enqueue_style(
                    'hipayenterprise-style',
                    plugins_url('/assets/css/frontend/hipay.css', WC_HIPAYENTERPRISE_BASE_FILE),
                    array(),
                    'all'
                );

            }
        }

        public function removeFromMyAccount($availableGateways)
        {
            if (is_add_payment_method_page()) {
                unset($availableGateways[self::GATEWAY_CREDIT_CARD_ID]);
            }

            return $availableGateways;
        }

        public function isAvailable()
        {
            return ('yes' === $this->enabled);
        }


        private function isDirectPostActivated()
        {
            return $this->confHelper->getPaymentGlobal()["operating_mode"] === OperatingMode::HOSTED_FIELDS;
        }

        /**
         * @see parent::addActions
         */
        public function addActions()
        {
            parent::addActions();
            add_action('woocommerce_api_wc_hipayenterprise', array($this, 'check_callback_response'));
            add_action('woocommerce_receipt_' . $this->id, array($this, 'receipt_page'));

            if (
                $this->isAvailable() &&
                is_page() &&
                (is_checkout() || is_add_payment_method_page()) &&
                !is_order_received_page()
            ) {
                add_action('wp_print_scripts', array($this, 'localize_scripts'), 5);
            }
            if (is_admin()) {
                add_action('wp_print_scripts', array($this, 'localize_scripts_admin'), 5);
            }
        }

        /**
         *  Action for HiPay Notification
         *  Check Signature and process Transaction
         */
        public function check_callback_response()
        {
            $transactionReference = Hipay_Helper::getPostData('$1', '');

            if (!Hipay_Helper::checkSignature($this)) {
                $this->logs->logErrors("Notify : Signature is wrong for Transaction $transactionReference.");
                header('HTTP/1.1 403 Forbidden');
                die('Bad Callback initiated - signature');
            }

            try {
                $notification = new Hipay_Notification($this, $_POST);
                $notification->processTransaction();
            } catch (Exception $e) {
                header("HTTP/1.0 500 Internal server error");
            }
        }

        /**
         * Save HiPay Admin Settings
         */
        public function save_settings()
        {
            $settings = array();
            $this->settingsHandler->saveAccountSettings($settings);
            $this->settingsHandler->saveFraudSettings($settings);
            $this->settingsHandler->saveCreditCardSettings($settings);
            $this->settingsHandler->savePaymentGlobal($settings);
            $settings["payment"]["local_payment"] = $this->confHelper->getLocalPayments();

            $this->confHelper->saveConfiguration($settings);
        }

        /**
         *  Get detail field for payment method
         */
        public function payment_fields()
        {
            if ($this->supports('tokenization') && is_checkout() && is_user_logged_in()) {
                $this->tokenization_script();
                $this->saved_payment_methods();
                $this->form();
                $this->save_payment_method_checkbox();
            } else {
                $this->form();
            }
        }

        private function form()
        {
            $paymentGlobal = $this->confHelper->getPaymentGlobal();
            if ($paymentGlobal['operating_mode'] == OperatingMode::HOSTED_PAGE) {

                $this->process_template(
                    'hosted-page.php',
                    'frontend',
                    array()
                );
            } elseif ($paymentGlobal['operating_mode'] == OperatingMode::HOSTED_FIELDS) {

                $activatedCreditCard = Hipay_Helper::getActivatedPaymentByCountryAndCurrency(
                    $this,
                    "credit_card",
                    WC()->customer->get_billing_country(),
                    get_woocommerce_currency(),
                    WC()->cart->get_totals()["total"],
                    false
                );

                $this->process_template(
                    'hosted-fields.php',
                    'frontend',
                    array(
                        'activatedCreditCard' => '"' . implode('","', $activatedCreditCard) . '"'
                    )
                );
            }
        }

        /**
         * Initialise Gateway Settings Admin
         */
        public function init_form_fields()
        {
            $this->account = include(plugin_dir_path(__FILE__) . '../admin/settings/settings-account.php');
            $this->fraud = include(plugin_dir_path(__FILE__) . '../admin/settings/settings-fraud.php');
            $this->methods = include(plugin_dir_path(__FILE__) . '../admin/settings/settings-methods.php');
            $this->faqs = include(plugin_dir_path(__FILE__) . '../admin/settings/settings-faq.php');
        }

        public function generate_account_details_html()
        {
            ob_start();
            $this->process_template(
                'admin-account-settings.php',
                'admin',
                array(
                    'account' => $this->confHelper->getAccount()
                )
            );

            return ob_get_clean();
        }

        /**
         * Get list of Gateway provided by Hipay
         *
         * @return array
         */
        private function getHipayGateways()
        {
            $hipayGateways = array();
            $availableGateways = WC()->payment_gateways->payment_gateways();
            foreach ($availableGateways as $gateway) {
                if ($gateway instanceof Hipay_Gateway_Local_Abstract) {
                    $hipayGateways[$gateway->id] = $gateway->method_title;
                }
            }
            return $hipayGateways;
        }

        public function generate_methods_global_local_payment_settings_html()
        {
            ob_start();
            $this->process_template(
                'admin-link-paymentlocal-settings.php',
                'admin',
                array(
                    'availableHipayGateways' => $this->getHipayGateways(),
                    'paymentCommon' => $this->confHelper->getPaymentGlobal()
                )
            );
            return ob_get_clean();
        }

        public function generate_methods_credit_card_settings_html()
        {
            ob_start();
            $this->process_template(
                'admin-creditcard-settings.php',
                'admin',
                array(
                    'paymentCommon' => $this->confHelper->getPaymentGlobal(),
                    'configurationPaymentMethod' => $this->confHelper->getPaymentCreditCard(),
                    'methods' => 'creditCard'
                )
            );

            return ob_get_clean();
        }

        public function generate_methods_local_payments_settings_html()
        {
            ob_start();
            $this->process_template(
                'admin-creditcard-settings.php',
                'admin',
                array(
                    'configurationPaymentMethod' => $this->confHelper->getLocalPayment(),
                    'methods' => 'local'
                )
            );

            return ob_get_clean();
        }

        public function generate_methods_global_settings_html()
        {
            ob_start();
            $this->process_template(
                'admin-global-settings.php',
                'admin',
                array(
                    'paymentCommon' => $this->confHelper->getPaymentGlobal()
                )
            );

            return ob_get_clean();
        }

        public function generate_faqs_details_html()
        {
            ob_start();
            $this->process_template(
                'admin-faq-settings.php',
                'admin'
            );

            return ob_get_clean();
        }

        public function generate_fraud_details_html()
        {
            ob_start();
            $this->process_template(
                'admin-fraud-settings.php',
                'admin',
                array(
                    'fraud' => $this->confHelper->getFraud()
                )
            );

            return ob_get_clean();
        }

        public function admin_options()
        {
            parent::admin_options();
            $this->confHelper->checkBasketRequirements($this->notifications);
            $this->process_template(
                'admin-general-settings.php',
                'admin',
                array(
                    'curl_active' => extension_loaded('curl'),
                    'simplexml_active' => extension_loaded('simplexml'),
                    'https_active' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] != 'off',
                    'notifications' => $this->notifications,
                    'currentPluginVersion' => get_option("hipay_enterprise_version")
                )
            );
        }

        /**
         * @param $order_id
         */
        public function thanks_page($order_id)
        {
            WC();
        }

        public function localize_scripts_admin()
        {
            wp_localize_script(
                'hipay-js-admin',
                'hipay_config_i18n',
                array(
                    "available_countries" => __("Available countries", "hipayenterprise"),
                    "authorized_countries" => __("Authorized countries", "hipayenterprise")
                )
            );
        }

        public function localize_scripts()
        {
            wp_localize_script(
                'hipay-js-front',
                'hipay_config_card',
                array(
                    "operating_mode" => $this->confHelper->getPaymentGlobal()["operating_mode"],
                    "oneClick" => $this->confHelper->getPaymentGlobal()["card_token"],
                )
            );

            if ($this->confHelper->getPaymentGlobal()["operating_mode"] == OperatingMode::HOSTED_FIELDS) {
                wp_localize_script(
                    'hipay-js-front',
                    'hipay_config_i18n',
                    array(
                        "activated_card_error" => __(
                            'This credit card type or the order currency is not supported. 
                    Please choose an other payment method.',
                            'hipayenterprise'
                        ),
                    )
                );
            }
        }

        /**
         * Process payment
         *
         * @param int $order_id
         * @return array
         * @throws Exception
         */
        public function process_payment($order_id)
        {
            try {
                $this->logs->logInfos(" # Process Payment for  " . $order_id);

                $params = array(
                    "order_id" => $order_id,
                    "paymentProduct" => Hipay_Helper::getPostData('card-payment_product'),
                    "cardtoken" => Hipay_Helper::getPostData('card-token'),
                    "card_holder" => Hipay_Helper::getPostData('card-holder'),
                    "deviceFingerprint" => Hipay_Helper::getPostData('card-device_fingerprint'),
                    "forceSalesMode" => false
                );

                if ($this->confHelper->getPaymentGlobal()["card_token"]) {
                    $token = Hipay_Helper::getPostData('wc-' . self::GATEWAY_CREDIT_CARD_ID . '-payment-token');

                    if ($token) {
                        Hipay_Token_Helper::handleTokenForm($token, $params);
                    }
                    $params["createOneClick"] = get_current_user_id() > 0 &&
                        Hipay_Helper::getPostData('wc-' . self::GATEWAY_CREDIT_CARD_ID . '-new-payment-method');
                }

                $redirect = $this->apiRequestHandler->handleCreditCard($params);

                return array(
                    'result' => 'success',
                    'redirect' => $redirect,
                );
            } catch (Hipay_Payment_Exception $e) {
                return $this->handlePaymentError($e);
            }
        }

//        public function add_payment_method()
//        {
//
//            if (!$this->confHelper->getPaymentGlobal()["card_token"]) {
//                return false;
//            }
//
//            try {
//                $values = array(
//                    "token" => Hipay_Helper::getPostData('card-token'),
//                    "pan" => Hipay_Helper::getPostData('card-pan'),
//                    "expiry_year" => Hipay_Helper::getPostData('card-expiry-year'),
//                    "expiry_month" => Hipay_Helper::getPostData('card-expiry-month'),
//                    "brand" => Hipay_Helper::getPostData('payment-product'),
//                    "card_holder" => Hipay_Helper::getPostData('card-holder'),
//                    "user_id" => get_current_user_id(),
//                    "gateway_id" => self::GATEWAY_CREDIT_CARD_ID
//                );
//
//                Hipay_Token_Helper::createToken($values);
//
//                return array(
//                    'result' => 'success',
//                    'redirect' => wc_get_endpoint_url('payment-methods'),
//                );
//            } catch (Exception $e) {
//                return array(
//                    'result' => 'failure',
//                    'redirect' => wc_get_endpoint_url('payment-methods'),
//                );
//            }
//        }

        /**
         * Process Hipay Receipt page
         *
         * @param $order_id
         */
        public function receipt_page($order_id)
        {
            try {
                $order = wc_get_order($order_id);
                $paymentUrl = $order->get_meta("_hipay_pay_url");

                if (empty($paymentUrl)) {
                    $this->logs->logInfos(" # No payment Url " . $order_id);
                    $this->generate_error_receipt();
                } else {
                    $this->logs->logInfos(" # Receipt_page " . $order_id);

                    switch ($this->confHelper->getPaymentGlobal()["operating_mode"]) {
                        case OperatingMode::HOSTED_FIELDS:
                            $this->generate_common_receipt();
                            break;
                        case  OperatingMode::HOSTED_PAGE:
                            if ($this->confHelper->getPaymentGlobal()["display_hosted_page"] = "iframe") {
                                $this->generate_iframe_page($paymentUrl);
                            }
                            break;
                    }
                }
            } catch (Exception $e) {
                $this->generate_error_receipt();
                $this->logs->logException($e);
            }
        }

        /**
         *  Generate HTML for error in iframe request
         */
        private function generate_error_receipt()
        {
            echo __(
                "Sorry, we cannot process your payment.. Please try again.",
                "hipayenterprise"
            );
        }

        /**
         *  Generate HTML for direct integration
         */
        private function generate_common_receipt()
        {
            echo __(
                "We have received your order payment. We will process the order as soon as we get the payment confirmation.",
                "hipayenterprise"
            );
        }

        /**
         *  Generate HTML for iframe integration
         *
         * @param $paymentUrl
         */
        private function generate_iframe_page($paymentUrl)
        {
            echo '<div id="wc_hipay_iframe_container">
                    <iframe id="wc_hipay_iframe" name="wc_hipay_iframe" width="100%" height="475" style="border: 0;" src="' .
                esc_html($paymentUrl) .
                '" allowfullscreen="" frameborder="0"></iframe>
                  </div>';
        }
    }
}
