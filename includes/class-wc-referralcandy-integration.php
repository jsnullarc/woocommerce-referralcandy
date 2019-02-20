<?php
/**
 * WooCommerce ReferralCandy Integration.
 *
 * @package  WC_Referralcandy_Integration
 * @category Integration
 * @author   ReferralCandy
 */

if (!defined('ABSPATH')) {
    die('Direct access is prohibited.');
}

if (!class_exists('WC_Referralcandy_Integration')) {
    class WC_Referralcandy_Integration extends WC_Integration {
        public function __construct() {
            global $woocommerce;

            $this->id                 = 'referralcandy';
            $this->method_title       = __('ReferralCandy', 'woocommerce-referralcandy');
            $this->method_description = __('Paste <a target="_blank" href="https://my.referralcandy.com/integration">your ReferralCandy plugin tokens</a> below:', 'woocommerce-referralcandy');

            // Load the settings.
            $this->init_form_fields();

            // Define user set variables.
            $this->api_id              = $this->get_option('api_id');
            $this->app_id              = $this->get_option('app_id');
            $this->secret_key          = $this->get_option('secret_key');

            // Actions.
            add_action('woocommerce_update_options_integration_' . $this->id,   [$this, 'process_admin_options']);
            add_action('admin_notices',                                         [$this, 'check_plugin_keys']);
            add_action('woocommerce_thankyou',                                  [$this, 'render_post_purchase_script']);
            add_action('woocommerce_order_status_changed',                      [$this, 'process_order_status_change'], 10, 1);

            // Filters.
            add_filter('woocommerce_settings_api_sanitized_fields_' . $this->id, [$this, 'sanitize_settings']);
        }

        public function init_form_fields() {
            $this->form_fields = [
                'api_id' => [
                    'title'             => __('API Access ID', 'woocommerce-referralcandy'),
                    'type'              => 'text',
                    'desc'              => __('You can find your API Access ID on https://my.referralcandy.com/settings'),
                    'desc_tip'          => true,
                    'default'           => ''
                ],
                'app_id' => [
                    'title'             => __('App ID', 'woocommerce-referralcandy'),
                    'type'              => 'text',
                    'desc'              => __('You can find your App ID on https://my.referralcandy.com/settings'),
                    'desc_tip'          => true,
                    'default'           => ''
                ],
                'secret_key' => [
                    'title'             => __('Secret key', 'woocommerce-referralcandy'),
                    'type'              => 'text',
                    'desc'              => __('You can find your API Secret Key on https://my.referralcandy.com/settings'),
                    'desc_tip'          => true,
                    'default'           => ''
                ],
                'popup' => [
                    'title'             => __('Post-purchase Popup', 'woocommerce-referralcandy'),
                    'label'             => __('Enable post-purchase Popup', 'woocommerce-referralcandy'),
                    'type'              => 'checkbox',
                    'desc_tip'          => false,
                    'default'           => ''
                ],
                'popup_quickfix' => [
                    'title'             => __('Post-purchase Popup Quickfix', 'woocommerce-referralcandy'),
                    'label'             => __('Popup is breaking the checkout page?'.'
                                                Try enabling this option to apply the quickfix!',
                                                'woocommerce-referralcandy'),
                    'type'              => 'checkbox',
                    'desc_tip'          => false,
                    'default'           => ''
                ],
                'remove_referral_for_cancelled' => [
                    'title'             => __('Remove referrals for cancelled orders', 'woocommerce-referralcandy'),
                    'label'             => __('Enabling this will send an API request to ReferralCandy'.'
                                                to remove referrals from cancelled orders',
                                                'woocommerce-referralcandy'),
                    'type'              => 'checkbox',
                    'desc_tip'          => false,
                    'default'           => ''
                ],
                'remove_referral_for_refunded' => [
                    'title'             => __('Remove referrals for refunded orders', 'woocommerce-referralcandy'),
                    'label'             => __('Enabling this will send an API request to ReferralCandy'.'
                                                to remove referrals from refunded orders',
                                                'woocommerce-referralcandy'),
                    'type'              => 'checkbox',
                    'desc_tip'          => false,
                    'default'           => ''
                ]
            ];
        }

        public function sanitize_settings($settings) {
            return $settings;
        }

        private function is_option_enabled($option_name) {
            return $this->get_option($option_name) == 'yes'? true : false;
        }

        public function check_plugin_keys() {
            $message = "<strong>ReferralCandy</strong>: Please make sure the following keys are present for your integration to work properly:";
            $missing_keys = false;
            $keys_to_check = [
                'API Access ID' => $this->api_id,
                'App ID'        => $this->app_id,
                'Secret Key'    => $this->secret_key
            ];
            foreach($keys_to_check as $key => $value) {
                if (empty($value)) {
                    $missing_keys = true;
                    $message .= "<br> - $key";
                }
            }
            if ($missing_keys == true) {
                printf('<div class="notice notice-warning"><p>%s</p></div>', $message);
            }
        }

        public function process_order_status_change($order_id) {
            $order = new WC_Order($order_id);
            $rc_order = new RC_Order($order_id, $this);

            $remove_referral_on_cancelled = $this->is_option_enabled('remove_referral_for_cancelled');
            $remove_referral_on_refund = $this->is_option_enabled('remove_referral_for_refunded');

            if ($order->get_status() == "cancelled" && $remove_referral_on_cancelled) {
                $rc_order->remove_referral();
            }

            if ($order->get_status() == "refunded" && $remove_referral_on_refund) {
                $rc_order->remove_referral();
            }
        }

        public function render_post_purchase_script($order_id) {
            $wc_pre_30 = version_compare( WC_VERSION, '3.0.0', '<');
            $order = new WC_Order($order_id);
            // https://en.support.wordpress.com/settings/general-settings/2/#timezone
            // This option is set when a timezone name is selected
            $timezone_string = get_option('timezone_string');
            if (!empty($timezone_string)) {
                $timestamp = DateTime::createFromFormat('Y-m-d H:i:s', $order->order_date, new DateTimeZone($timezone_string))->getTimestamp();
            } else {
                $timestamp = time();
            }
            $billing_first_name = $wc_pre_30? $order->billing_first_name : $order->get_billing_first_name();
            $billing_last_name  = $wc_pre_30? $order->billing_last_name : $order->get_billing_last_name();
            $billing_email      = $wc_pre_30? $order->billing_email : $order->get_billing_email();
            $encoded_email      = urlencode($billing_email);
            $order_total        = $order->get_total();
            $order_currency     = $wc_pre_30? $order->get_order_currency() : $order->get_currency();
            $order_number       = $order->get_order_number();

            // make sure first name is always populated to avoid checksum errors
            if (empty(strip_tags($billing_first_name)) === true) { // if first name is empty
                // extract name from email (i.e. john from john+doe@domain.com or john_doe from john_doe@domain.com)
                preg_match('/(?<extracted_name>\w+)/', $billing_email, $matches);
                $billing_first_name = $matches['extracted_name']; // assign extracted name as first name
            }

            $divData = [
                'id'                => $this->is_option_enabled('popup')? 'refcandy-popsicle' : 'refcandy-mint',
                'data-app-id'       => $this->get_option('app_id'),
                'data-fname'        => $billing_first_name,
                'data-lname'        => $billing_last_name,
                'data-email'        => $this->is_option_enabled('popup')? $billing_email : $encoded_email,
                'data-amount'       => $order_total,
                'data-currency'     => $order_currency,
                'data-timestamp'    => $timestamp,
                'data-external-reference-id' => $order_number,
                'data-signature'    => md5($billing_email.','.$billing_first_name.','.$order_total.','.$timestamp.','.$this->get_option('secret_key'))
            ];

            $popsicle_script = '<script>(function(e){var t,n,r,i,s,o,u,a,f,l,c,h,p,d,v;z="script";l="refcandy-purchase-js";c="refcandy-popsicle";p="go.referralcandy.com/purchase/";t="data-app-id";r={email:"a",fname:"b",lname:"c",amount:"d",currency:"e","accepts-marketing":"f",timestamp:"g","referral-code":"h",locale:"i","external-reference-id":"k",signature:"ab"};i=e.getElementsByTagName(z)[0];s=function(e,t){if(t){return""+e+"="+encodeURIComponent(t)}else{return""}};d=function(e){return""+p+h.getAttribute(t)+".js?lightbox=1&aa=75&"};if(!e.getElementById(l)){h=e.getElementById(c);if(h){o=e.createElement(z);o.id=l;a=function(){var e;e=[];for(n in r){u=r[n];v=h.getAttribute("data-"+n);e.push(s(u,v))}return e}();o.src="//"+d(h.getAttribute(t))+a.join("&");return i.parentNode.insertBefore(o,i)}}})(document);</script>';
            $mint_script = '<script>(function(e){var t,n,r,i,s,o,u,a,f,l,c,h,p,d,v;z="script";l="refcandy-purchase-js";c="refcandy-mint";p="go.referralcandy.com/purchase/";t="data-app-id";r={email:"a",fname:"b",lname:"c",amount:"d",currency:"e","accepts-marketing":"f",timestamp:"g","referral-code":"h",locale:"i","external-reference-id":"k",signature:"ab"};i=e.getElementsByTagName(z)[0];s=function(e,t){if(t){return""+e+"="+t}else{return""}};d=function(e){return""+p+h.getAttribute(t)+".js?aa=75&"};if(!e.getElementById(l)){h=e.getElementById(c);if(h){o=e.createElement(z);o.id=l;a=function(){var e;e=[];for(n in r){u=r[n];v=h.getAttribute("data-"+n);e.push(s(u,v))}return e}();o.src=""+e.location.protocol+"//"+d(h.getAttribute(t))+a.join("&");return i.parentNode.insertBefore(o,i)}}})(document);</script>';

            $quickfix = '';
            if ($this->is_option_enabled('popup') && $this->is_option_enabled('popup_quickfix')) {
                $quickfix = '<style>html { position: relative !important; }</style>';
            }

            $div = '<div '.implode(' ', array_map(function ($v, $k) { return $k . '="'.addslashes($v).'"'; }, $divData, array_keys($divData))).'></div>';

            $script = $this->is_option_enabled('popup')? $popsicle_script : $mint_script;

            echo $div.$script.$quickfix;
        }
    }
}
