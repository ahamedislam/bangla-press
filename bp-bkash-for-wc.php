<?php
/*
Plugin Name: BanglaPress Payment - bKash and Partial Payment
Description: One-click bKash payments on landing pages, accept partial payments, and provide checkout options with bKash, Nagad, Rocket, and Upay for WooCommerce.
Version: 1.2
Author: Anowar Hossain Rana
Author URI: https://cxrana.wordpress.com/
License: GPL-2.0+
License URI: https://www.gnu.org/licenses/gpl-2.0.html
*/

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Include the settings file
require_once plugin_dir_path(__FILE__) . 'bp-bkash-settings.php';
// Include the payment method class
function include_mobile_banking_gateway() {
    if (class_exists('WC_Payment_Gateway')) {
        include_once 'class-wc-gateway-mobile-banking.php';
    }
}
add_action('plugins_loaded', 'include_mobile_banking_gateway', 11);

// Enqueue Admin Scripts and Styles
function bkash_enqueue_admin_styles() {
    wp_enqueue_style('bkash-admin-style', plugin_dir_url(__FILE__) . 'assets/bkash-admin-style.css');
    wp_enqueue_script('admin-scripts', plugin_dir_url(__FILE__) . 'assets/js/admin-scripts.js', array('jquery'), '1.0.0', true);
}
add_action('admin_enqueue_scripts', 'bkash_enqueue_admin_styles');

class WC_BKASH_Manual_Payment {

    public function __construct() {
        add_shortcode('bkash_payment_button', array($this, 'bkash_payment_button'));
        add_action('wp_footer', array($this, 'bkash_payment_popup'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('wp_ajax_process_bkash_payment', array($this, 'process_bkash_payment'));
        add_action('wp_ajax_nopriv_process_bkash_payment', array($this, 'process_bkash_payment'));
        add_action('woocommerce_admin_order_data_after_billing_address', array($this, 'display_bkash_order_details'), 10, 1);
        add_action('woocommerce_checkout_update_order_meta', array($this, 'save_bkash_payment_meta'));
    }

    // Enqueue Scripts and Styles
    public function enqueue_scripts() {
        wp_enqueue_script('jquery');
        wp_enqueue_style('bkash-style', plugin_dir_url(__FILE__) . 'assets/bkash-style.css', array(), '1.0.0');
        wp_enqueue_style('mobile-banking-style', plugin_dir_url(__FILE__) . 'assets/mobile-banking.css');
    }

    // Create Shortcode Button
    public function bkash_payment_button($atts) {
        $atts = shortcode_atts(array(
            'product_id' => '',
            'label' => '',
            'style' => ''
        ), $atts);

        if (empty($atts['product_id'])) {
            return '<div class="error">Please set a product ID!</div>';
        }

        $options = get_option('bkash_options');
        $button_label = !empty($atts['label']) ? $atts['label'] : (!empty($options['button_label']) ? $options['button_label'] : 'Buy with bKash');

        $product_id = intval($atts['product_id']);
        $product = wc_get_product($product_id);

        if ($product) {
            if ($product->is_type('simple')) {
                $product_price = wc_get_price_to_display($product);
            } elseif ($product->is_type('variable')) {
                $variations = $product->get_available_variations();
                $min_price = PHP_INT_MAX;
                $min_variation_attributes = array();

                foreach ($variations as $variation) {
                    $variation_product = wc_get_product($variation['variation_id']);
                    $variation_price = wc_get_price_to_display($variation_product);

                    if ($variation_price < $min_price) {
                        $min_price = $variation_price;
                        $min_variation_attributes = $variation['attributes'];
                    }
                }

                $product_price = $min_price;
                $min_attributes = json_encode($min_variation_attributes);
            }
        } else {
            $product_price = 0;
            $min_attributes = '{}';
        }

        $button_style = !empty($atts['style']) ? esc_attr($atts['style']) : '';

        ob_start();
        ?>
        <button class="bkash-payment-button" 
            data-product-id="<?php echo esc_attr($atts['product_id']); ?>" 
            data-product-price="<?php echo esc_attr($product_price); ?>" 
            data-min-attributes='<?php echo esc_attr($min_attributes); ?>' 
            style="<?php echo esc_attr($button_style); ?>">
            <?php echo esc_html($button_label); ?>
        </button>

        <script>
        jQuery(document).ready(function ($) {
            let selectedVariationData = '';
            let productPrice = <?php echo esc_js($product_price); ?>;
            let shippingPrice = 0;
            let quantity = 1;

            function updateTotalPrice() {
                let totalPrice = (productPrice * quantity) + shippingPrice;
                $('#totalPrice').text(totalPrice.toFixed(2));
            }

            $(document).on('found_variation', function (event, variation) {
                var variationPrice = variation.display_price;
                selectedVariationData = Object.entries(variation.attributes)
                    .map(([key, value]) => `${key.replace('attribute_', '')}: ${value}`)
                    .join(', ');
                productPrice = variationPrice;
                $('.bkash-payment-button').data('product-price', productPrice);
                console.log('Variation selected, updated price:', variationPrice);
                console.log('Selected variation data:', selectedVariationData);
                updateTotalPrice();
            });

            $(document).on('reset_data', function () {
                productPrice = <?php echo esc_js($product_price); ?>;
                $('.bkash-payment-button').data('product-price', productPrice);
                selectedVariationData = '';
                console.log('Variation reset, reverted to default price:', productPrice);
                updateTotalPrice();
            });

            $('input.qty').on('change', function () {
                quantity = parseInt($(this).val()) || 1;
                updateTotalPrice();
            });

            $('.bkash-payment-button').on('click', function (event) {
                event.preventDefault();
                var productId = $(this).data('product-id');
                var productPrice = $(this).data('product-price');
                $('#bkashPaymentModal').data({
                    'product-id': productId,
                    'product-price': productPrice,
                }).fadeIn();

                var billingEmail = '<?php echo esc_js(wp_get_current_user()->user_email); ?>';
                var checkoutHeaderLabel = '<?php echo esc_js($options['checkout_header_label'] ?? 'Checkout'); ?>';
                var firstNameLabel = '<?php echo esc_js($options['first_name_label'] ?? 'Name'); ?>';
                var emailLabel = '<?php echo esc_js($options['email_label'] ?? 'Email - Not Required'); ?>';
                var phoneLabel = '<?php echo esc_js($options['phone_label'] ?? 'Mobile'); ?>';
                var addressLabel = '<?php echo esc_js($options['address_label'] ?? 'Address'); ?>';
                var transactionIdLabel = '<?php echo esc_js($options['transaction_id_label'] ?? 'Last 4 Digit of Payment Number'); ?>';
                var submitButtonLabel = '<?php echo esc_js($options['submit_button_label'] ?? 'Proceed to Payment'); ?>';
                var processingMessage = '<?php echo esc_js($options['processing_message'] ?? 'Please wait, processing your payment...'); ?>';

                var formHtml = `
                    <form id="bkashCheckoutForm">
                        <h3>${checkoutHeaderLabel}</h3>
                        <label for="bkash_first_name">${firstNameLabel}:</label>
                        <input type="text" id="bkash_first_name" name="billing_first_name" required>
                        <label for="bkash_email">${emailLabel}:</label>
                        <input type="email" id="bkash_email" name="billing_email" value="${billingEmail}" >
                        <label for="bkash_phone">${phoneLabel}:</label>
                        <input type="tel" id="bkash_phone" name="billing_phone" required>
                        <label for="bkash_address">${addressLabel}:</label>
                        <input type="text" id="bkash_address" name="billing_address" required>
                        <label for="bkash_last_digit">${transactionIdLabel}:</label>
                        <input type="text" id="bkash_last_digit" name="bkash_last_digit" placeholder="1234" required>
                        <input type="hidden" name="product_id" value="${productId}">
                        <input type="hidden" id="product_price" name="product_price" value="${productPrice}">
                        <input type="hidden" id="variation_data" name="variation_data" value="${selectedVariationData}">
                        <input type="hidden" id="shipping_price" name="shipping_price" value="0">
                        <input type="hidden" id="quantity" name="quantity" value="${quantity}">
                        <input type="hidden" id="payment_method" name="payment_method" value="">
                        <button type="submit">${submitButtonLabel}</button>
                    </form>
                    <div id="processingMessage" style="display: none; margin-top: 10px;">${processingMessage}</div>
                `;
                $('#bkashCheckoutContainer').html(formHtml);

                $('input[name="operator"]').on('change', function () {
                    var selectedPaymentMethod = $(this).val();
                    $('#payment_method').val(selectedPaymentMethod);
                });
                $('input[name="operator"]:checked').trigger('change');
            });

            $(document).on('click', '.close', function () {
                $('#bkashPaymentModal').fadeOut();
            });

            $(document).on('click', function (event) {
                if ($(event.target).is('#bkashPaymentModal')) {
                    $('#bkashPaymentModal').fadeOut();
                }
            });

            $(document).on('submit', '#bkashCheckoutForm', function (e) {
                e.preventDefault();
                var formData = $(this).serialize();
                $('#processingMessage').fadeIn();

                $.post('<?php echo admin_url('admin-ajax.php'); ?>', {
                    action: 'process_bkash_payment',
                    data: formData
                }, function (response) {
                    $('#processingMessage').fadeOut();
                    if (response.success) {
                        window.location.href = response.data.redirect_url;
                    } else {
                        $('#bkashCheckoutContainer').prepend('<div class="error" style="color: red;">' + response.data.message + '</div>');
                    }
                });
            });

            $('#shipping_zone').on('change', function () {
                var selectedOption = $(this).find('option:selected');
                var selectedShippingPrice = selectedOption.text().split(' - ')[1];
                shippingPrice = parseFloat(selectedShippingPrice.replace('৳', '').trim()) || 0;
                $('#shipping_price').val(shippingPrice);
                updateTotalPrice();
            });

            updateTotalPrice();
        });
        </script>
        <?php
        return ob_get_clean();
    }

    public function bkash_payment_popup() {
        $options = get_option('bkash_options');
        ?>
        <!-- Modal Structure, initially hidden -->
        <div id="bkashPaymentModal" style="display: none;">
            <div class="modal-content">
                <span class="close">&times;</span>
                <div class="modal-body" style="display: flex;">
                    <div class="form-column" style="flex: 1; padding-right: 20px;">
                        <div id="bkashCheckoutContainer"></div>
                    </div>
                    <div class="logo-column" style="flex: 1; text-align: center;">
                        <div id="mobileOperator">
                            <label>
                                <input type="radio" name="operator" value="bkash" checked> <?php echo esc_html($options['bkash_label'] ?? 'bKash'); ?>
                            </label>
                            <label>
                                <input type="radio" name="operator" value="nagad"> <?php echo esc_html($options['nagad_label'] ?? 'Nagad'); ?>
                            </label>
                            <label>
                                <input type="radio" name="operator" value="rocket"> <?php echo esc_html($options['rocket_label'] ?? 'Rocket'); ?>
                            </label>
                            <label>
                                <input type="radio" name="operator" value="upay"> <?php echo esc_html($options['upay_label'] ?? 'Upay'); ?>
                            </label>
                        </div>
                        <div id="mobileInfo" style="margin-top: 20px;">
                            <p id="mobileNumber" style="font-weight: bold;"></p>
                            <img id="operatorLogo" src="" alt="Mobile Operator Logo">
                            <p id="instructions" style="font-style: italic;"></p>
                            <?php
                            $pay_label = esc_html($options['pay_label'] ?? 'Product Price');
                            ?>
                            <p style="font-style: normal; font-weight: normal; margin-top: 5px; font-size: 16px; color: #555; background-color: #f3f3f3; padding: 8px; border: 1px solid #ddd; border-radius: 3px; display: flex; align-items: center; justify-content: center;">
                                <?php echo $pay_label; ?> 
                                <span id="productPrice" style="color: #E2126E; font-size: 18px; font-weight: bold; margin: 0 5px;">
                                    <?php echo esc_html($product_price ?? '0'); ?>
                                </span>
                                ৳
                            </p>
                            <?php
                            $shipping_label = esc_html($options['shipping_label'] ?? 'Shipping:');
                            ?>
                            <p style="font-style: normal; font-weight: normal; margin-top: 5px; font-size: 16px; color: #555;">
                                <?php echo $shipping_label; ?> 
                                <?php $this->display_shipping_zones_dropdown(); ?>
                            </p>
                            <?php
                            $total_price_label = esc_html($options['total_price_label'] ?? 'Total Price:');
                            ?>
                            <p style="font-style: normal; font-weight: normal; margin-top: 5px; font-size: 16px; color: #555; background-color: #f3f3f3; padding: 8px; border: 1px solid #ddd; border-radius: 3px; display: flex; align-items: center; justify-content: center;">
                                <span id="totalPriceLabel"><?php echo $total_price_label; ?></span>
                                <span id="totalPrice" style="color: #E2126E; font-size: 18px; font-weight: bold; margin: 0 5px;">
                                    <?php echo esc_html($product_price ?? '0'); ?>
                                </span>
                                ৳
                            </p>
                        </div>
                    </div>
                </div><!-- Close modal-body -->
            </div><!-- Close modal-content -->
        </div><!-- Close bkashPaymentModal -->

        <script>
        jQuery(document).ready(function ($) {
            var defaultLogos = {
                'bkash': '<?php echo plugin_dir_url(__FILE__) . 'assets/icons/bKash-logo.png'; ?>',
                'nagad': '<?php echo plugin_dir_url(__FILE__) . 'assets/icons/nagad.png'; ?>',
                'rocket': '<?php echo plugin_dir_url(__FILE__) . 'assets/icons/rocket.png'; ?>',
                'upay': '<?php echo plugin_dir_url(__FILE__) . 'assets/icons/upay.png'; ?>'
            };

            $('input[name="operator"]').on('change', function () {
                var operator = $(this).val();
                var mobileNumber = '';
                var logoSrc = '';
                var instructions = '';
                var productPrice = $('#bkashPaymentModal').data('product-price');

                switch (operator) {
                    case 'bkash':
                        mobileNumber = '<?php echo esc_js($options['bkash_number'] ?? '017XXXXXXX'); ?>';
                        logoSrc = '<?php echo esc_url($options['bkash_logo'] ?? ''); ?>' || defaultLogos['bkash'];
                        instructions = '<?php echo esc_js($options['bkash_instruction'] ?? 'Please send money to this number.'); ?>';
                        break;
                    case 'nagad':
                        mobileNumber = '<?php echo esc_js($options['nagad_number'] ?? '018XXXXXXX'); ?>';
                        logoSrc = '<?php echo esc_url($options['nagad_logo'] ?? ''); ?>' || defaultLogos['nagad'];
                        instructions = '<?php echo esc_js($options['nagad_instruction'] ?? 'Please send money to this number.'); ?>';
                        break;
                    case 'rocket':
                        mobileNumber = '<?php echo esc_js($options['rocket_number'] ?? '019XXXXXXX'); ?>';
                        logoSrc = '<?php echo esc_url($options['rocket_logo'] ?? ''); ?>' || defaultLogos['rocket'];
                        instructions = '<?php echo esc_js($options['rocket_instruction'] ?? 'Please send money to this number.'); ?>';
                        break;
                    case 'upay':
                        mobileNumber = '<?php echo esc_js($options['upay_number'] ?? '016XXXXXXX'); ?>';
                        logoSrc = '<?php echo esc_url($options['upay_logo'] ?? ''); ?>' || defaultLogos['upay'];
                        instructions = '<?php echo esc_js($options['upay_instruction'] ?? 'Please send money to this number.'); ?>';
                        break;
                }

                if (logoSrc === '') {
                    logoSrc = defaultLogos[operator];
                }

                $('#mobileNumber').text(mobileNumber);
                $('#operatorLogo').attr('src', logoSrc).toggle(logoSrc !== '');
                $('#instructions').text(instructions);
                $('#productPrice').text(productPrice);
            });

            $('input[name="operator"]:checked').trigger('change');

            $('.bkash-payment-button').on('click', function () {
                var productId = $(this).data('product-id');
                var productPrice = $(this).data('product-price');
                $('#bkashPaymentModal').data('product-price', productPrice);
                $('input[name="operator"]:checked').trigger('change');
            });
        });
        </script>
        <?php
    }

    public function process_bkash_payment() {
        if (!isset($_POST['data'])) {
            wp_send_json_error(array('message' => 'All fields are required.'));
            wp_die();
        }

        parse_str($_POST['data'], $form_data);

        $first_name = sanitize_text_field($form_data['billing_first_name']);
        $email = sanitize_email($form_data['billing_email']);
        $phone = sanitize_text_field($form_data['billing_phone']);
        $address = sanitize_text_field($form_data['billing_address']);
        $bkash_last_digit = sanitize_text_field($form_data['bkash_last_digit']);
        $product_id = intval($form_data['product_id']);
        $product_price = floatval($form_data['product_price']);
        $shipping_price = floatval($form_data['shipping_price']);
        $payment_method = sanitize_text_field($form_data['payment_method']);
        $quantity = isset($form_data['quantity']) ? intval($form_data['quantity']) : 1;
        $variation_data = isset($form_data['variation_data']) ? $form_data['variation_data'] : '';

        $order = wc_create_order();
        $product = wc_get_product($product_id);

        if ($product) {
            $order->add_product($product, $quantity, array('subtotal' => $product_price, 'total' => $product_price * $quantity));

            if ($shipping_price > 0) {
                $item = new WC_Order_Item_Shipping();
                $item->set_method_title('Custom Shipping');
                $item->set_total($shipping_price);
                $order->add_item($item);
            }

            if (!empty($variation_data)) {
                $order->add_order_note('Variation Selected: ' . $variation_data);
                update_post_meta($order->get_id(), '_variation_data', $variation_data);
            }

            $order->set_address(array(
                'first_name' => $first_name,
                'email'      => $email,
                'phone'      => $phone,
                'address_1'  => $address,
            ), 'billing');

            $order->set_customer_id(0);
            $order->set_status('on-hold');
            $order->add_order_note('Payment made through ' . $payment_method . '.');
            $order->calculate_totals();
            $order->save();

            update_post_meta($order->get_id(), '_bkash_last_digit', $bkash_last_digit);
            update_post_meta($order->get_id(), '_payment_method', $payment_method);
            update_post_meta($order->get_id(), '_billing_address', $address);

            wp_send_json_success(array('redirect_url' => $order->get_checkout_order_received_url()));
        } else {
            wp_send_json_error(array('message' => 'Invalid product.'));
        }

        wp_die();
    }

    public function get_shipping_zones() {
        $shipping_zones = WC_Shipping_Zones::get_zones();
        $zone_data = [];

        foreach ($shipping_zones as $zone) {
            foreach ($zone['shipping_methods'] as $method) {
                if ($method->enabled === 'yes') {
                    $zone_data[] = [
                        'method_title' => $method->get_title(),
                        'zone_price' => $method->get_option('cost') ?: 0,
                    ];
                }
            }
        }

        return $zone_data;
    }

    public function display_shipping_zones_dropdown() {
        $zones = $this->get_shipping_zones();

        if (!empty($zones)) {
            echo '<select id="shipping_zone" name="shipping_zone">';
            echo '<option value="" disabled selected>Select</option>';
            foreach ($zones as $zone) {
                echo '<option value="' . esc_attr($zone['method_title']) . '">' . esc_html($zone['method_title'] . ' - ' . $zone['zone_price'] . '৳') . '</option>';
            }
            echo '</select>';
        } else {
            echo '<p>No shipping options available for this product.</p>';
        }
    }

    public function save_bkash_payment_meta($order_id) {
        if (isset($_POST['billing_first_name'])) {
            update_post_meta($order_id, '_billing_first_name', sanitize_text_field($_POST['billing_first_name']));
        }
        if (isset($_POST['billing_email'])) {
            update_post_meta($order_id, '_billing_email', sanitize_email($_POST['billing_email']));
        }
        if (isset($_POST['billing_phone'])) {
            update_post_meta($order_id, '_billing_phone', sanitize_text_field($_POST['billing_phone']));
        }
        if (isset($_POST['bkash_last_digit'])) {
            update_post_meta($order_id, '_bkash_last_digit', sanitize_text_field($_POST['bkash_last_digit']));
        }
        if (isset($_POST['variation_data'])) {
            update_post_meta($order_id, '_variation_data', sanitize_text_field($_POST['variation_data']));
        }
        if (isset($_POST['billing_address'])) {
            update_post_meta($order_id, '_billing_address', sanitize_text_field($_POST['billing_address']));
        }
    }

    public function display_bkash_order_details($order) {
        $first_name = get_post_meta($order->get_id(), '_billing_first_name', true);
        $email = get_post_meta($order->get_id(), '_billing_email', true);
        $phone = get_post_meta($order->get_id(), '_billing_phone', true);
        $bkash_last_digit = get_post_meta($order->get_id(), '_bkash_last_digit', true);
        $payment_method = get_post_meta($order->get_id(), '_payment_method', true);
        $variation_data = get_post_meta($order->get_id(), '_variation_data', true);
        $address = get_post_meta($order->get_id(), '_billing_address', true);

        echo '<div class="bkash-order-details">';
        echo '<h2>' . __('Payment Info', 'textdomain') . '</h4>';
        if (!empty($first_name)) {
            echo '<p><strong>' . __('Name:', 'textdomain') . '</strong> ' . esc_html($first_name) . '</p>';
        }
        if (!empty($email)) {
            echo '<p><strong>' . __('Email:', 'textdomain') . '</strong> ' . esc_html($email) . '</p>';
        }
        if (!empty($phone)) {
            echo '<p><strong>' . __('Phone:', 'textdomain') . '</strong> ' . esc_html($phone) . '</p>';
        }
        if (!empty($address)) {
            echo '<p><strong>' . __('Address:', 'textdomain') . '</strong> ' . esc_html($address) . '</p>';
        }
        if (!empty($bkash_last_digit)) {
            echo '<p><strong>' . __('Transaction ID:', 'textdomain') . '</strong> ' . esc_html($bkash_last_digit) . '</p>';
        }
        if (!empty($payment_method)) {
            echo '<p><strong>' . __('Payment Method:', 'textdomain') . '</strong> ' . esc_html(ucfirst($payment_method)) . '</p>';
        }
        if (!empty($variation_data)) {
            echo '<h2>' . __('Order Selection', 'textdomain') . '</h2>';
            echo '<p><strong>' . __('Selected Variation:', 'textdomain') . '</strong> ' . esc_html($variation_data) . '</p>';
        }
        echo '</div>';
    }
}

add_action('woocommerce_init', 'conditionally_add_bkash_payment_button');
function conditionally_add_bkash_payment_button() {
    $settings = get_option('woocommerce_mobile_banking_settings');
    $bkash_button_enabled = isset($settings['bkash_button_enabled']) ? $settings['bkash_button_enabled'] === 'yes' : false;

    if ($bkash_button_enabled) {
        add_action('woocommerce_after_add_to_cart_button', 'add_bkash_payment_button');
    }
}

function add_bkash_payment_button() {
    global $product;

    if (is_product()) {
        $product_id = $product->get_id();
        echo '<div style="display: inline-block; margin-left: 10px;">';
        echo do_shortcode('[bkash_payment_button product_id="' . $product_id . '"]');
        echo '</div>';
    }
}

add_action('woocommerce_thankyou', 'display_bkash_order_meta_on_thank_you_page', 10, 1);
function display_bkash_order_meta_on_thank_you_page($order_id) {
    if (!$order_id) {
        return;
    }

    $order = wc_get_order($order_id);
    if (!$order) {
        return;
    }

    $first_name = get_post_meta($order_id, '_billing_first_name', true);
    $email = get_post_meta($order_id, '_billing_email', true);
    $phone = get_post_meta($order_id, '_billing_phone', true);
    $bkash_last_digit = get_post_meta($order_id, '_bkash_last_digit', true);
    $variation_data = get_post_meta($order_id, '_variation_data', true);
    $payment_method = get_post_meta($order->get_id(), '_payment_method', true);
    $address = get_post_meta($order_id, '_billing_address', true);

    $order_number = $order->get_order_number();
    $shipping_address = $order->get_address('shipping');
    $amount_paid = $order->get_total();
    $remaining_due = $order->get_meta('_partial_payment_due');

    echo '<h2>' . __('Customer Info', 'textdomain') . '</h2>';
    echo '<div id="order-details" style="padding: 20px; border: 1px solid #ddd; background: #fff;">';
    echo '<p><strong>' . __('Order Number:', 'textdomain') . '</strong> ' . esc_html($order_number) . '</p>';
    if (!empty($first_name)) {
        echo '<p><strong>' . __('Name:', 'textdomain') . '</strong> ' . esc_html($first_name) . '</p>';
    }
    if (!empty($email)) {
        echo '<p><strong>' . __('Email:', 'textdomain') . '</strong> ' . esc_html($email) . '</p>';
    }
    if (!empty($address)) {
        echo '<p><strong>' . __('Address:', 'textdomain') . '</strong> ' . esc_html($address) . '</p>';
    }
    if (!empty($phone)) {
        echo '<p><strong>' . __('Phone:', 'textdomain') . '</strong> ' . esc_html($phone) . '</p>';
    }
    if (!empty($bkash_last_digit)) {
        echo '<p><strong>' . __('Transaction ID:', 'textdomain') . '</strong> ' . esc_html($bkash_last_digit) . '</p>';
    }
    if (!empty($variation_data)) {
        echo '<p><strong>' . __('Product Types:', 'textdomain') . '</strong> ' . esc_html($variation_data) . '</p>';
    }
    if (!empty($payment_method)) {
        echo '<p><strong>' . __('Payment Method:', 'textdomain') . '</strong> ' . esc_html(ucfirst($payment_method)) . '</p>';
    }
    if (!empty($amount_paid)) {
        echo '<p><strong>' . __('Amount Paid:', 'textdomain') . '</strong> ' . esc_html($amount_paid) . ' ৳</p>';
    }
    if ($remaining_due > 0) {
        echo '<p><strong>' . __('Remaining Due:', 'textdomain') . '</strong> ' . wc_price($remaining_due) . ' </p>';
        echo '<p id="remaining-due-amount" style="display:none;">' . wc_price($remaining_due) . '</p>';
    }
    echo '</div>';

    echo '<button id="download-image" class="button">Download as Image</button>';
    ?>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    <script>
        document.getElementById('download-image').addEventListener('click', function () {
            const element = document.getElementById('order-details');
            const remainingDueAmount = document.getElementById('remaining-due-amount') ? document.getElementById('remaining-due-amount').textContent : '';
            
            html2canvas(element).then(function (canvas) {
                const context = canvas.getContext('2d');
                context.font = "16px Arial";
                context.fillStyle = "#000";
                if (remainingDueAmount) {
                    context.fillText("Remaining Due: " + remainingDueAmount, 10, canvas.height - 30);
                }
                const link = document.createElement('a');
                link.href = canvas.toDataURL('image/png');
                link.download = 'order-details.png';
                link.click();
            });
        });
    </script>
    <?php
}

new WC_BKASH_Manual_Payment();