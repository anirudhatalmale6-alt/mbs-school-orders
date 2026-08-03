<?php
/**
 * Plugin Name: MBS School Orders
 * Description: Private per-program sports photo order forms for WooCommerce (Mark Nicholas Photography / Manhattan Beach Studios). Use the shortcode [mbs_order_form program="redondo"] on a private page.
 * Version:     1.0.1
 * Author:      Anirudha
 * Requires PHP: 7.2
 * WC requires at least: 5.0
 */

if (!defined('ABSPATH')) exit;

define('MBS_SO_VER', '1.0.1');
define('MBS_SO_DIR', plugin_dir_path(__FILE__));
define('MBS_SO_URL', plugin_dir_url(__FILE__));

require_once MBS_SO_DIR . 'includes/programs.php';

/* -------------------------------------------------------------------------
 *  Admin notice if WooCommerce is not active
 * ---------------------------------------------------------------------- */
add_action('admin_init', function () {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p><strong>MBS School Orders</strong> needs WooCommerce active to work.</p></div>';
        });
    }
});

/* -------------------------------------------------------------------------
 *  Hidden "container" product — every athlete order is one line of this,
 *  with a custom price + full details. Keeps us from creating hundreds of SKUs.
 * ---------------------------------------------------------------------- */
function mbs_get_container_id() {
    $pid = (int) get_option('mbs_container_product_id');
    if ($pid && get_post_status($pid) === 'publish' && get_post_type($pid) === 'product') {
        return $pid;
    }
    if (!function_exists('wc_get_product') || !class_exists('WC_Product_Simple')) {
        return 0;
    }
    $p = new WC_Product_Simple();
    $p->set_name('Sports Photo Order');
    $p->set_status('publish');
    $p->set_catalog_visibility('hidden'); // never shows in the shop or search
    $p->set_price(0);
    $p->set_regular_price(0);
    $p->set_sold_individually(false);
    $p->set_tax_status('taxable');
    $p->set_virtual(true);  // photos are delivered via the school, so no shipping step at checkout
    $id = $p->save();
    update_option('mbs_container_product_id', $id);
    return $id;
}

register_activation_hook(__FILE__, function () {
    if (class_exists('WooCommerce')) {
        mbs_get_container_id();
    }
});

/* -------------------------------------------------------------------------
 *  Shortcode: renders the order form for a given program
 * ---------------------------------------------------------------------- */
add_shortcode('mbs_order_form', 'mbs_shortcode');
function mbs_shortcode($atts) {
    $atts = shortcode_atts(array('program' => 'redondo'), $atts, 'mbs_order_form');
    $programs = mbs_programs();
    $key = sanitize_key($atts['program']);

    if (!isset($programs[$key])) {
        return '<p>MBS: unknown program "' . esc_html($key) . '".</p>';
    }
    if (!class_exists('WooCommerce')) {
        return '<p>MBS: WooCommerce is not active.</p>';
    }

    $prog = $programs[$key];

    wp_enqueue_style('mbs-order', MBS_SO_URL . 'assets/mbs-order.css', array(), MBS_SO_VER);

    // Build the config the front-end renders from (single source of truth = PHP config above).
    $prog_js = $prog;
    $prog_js['logoUrl'] = !empty($prog['logo']) ? MBS_SO_URL . 'assets/' . $prog['logo'] : '';

    $config = array(
        'ajax'       => admin_url('admin-ajax.php'),
        'nonce'      => wp_create_nonce('mbs_add'),
        'cartUrl'    => wc_get_cart_url(),
        'programKey' => $key,
        'program'    => $prog_js,
    );

    ob_start();
    include MBS_SO_DIR . 'templates/form.php';
    $html = ob_get_clean();

    // Print the config + JS INLINE (not as an enqueued external file). Aggressive optimizers
    // on the host (WP Rocket, SiteGround Optimizer) were combining the external file into a
    // bundle that 404'd, so the form never initialised. Inline can't be combined away.
    // data-no-optimize / data-cfasync tell those plugins to leave this block alone.
    $js = file_get_contents(MBS_SO_DIR . 'assets/mbs-order.js');
    $html .= "\n<script data-no-optimize=\"1\" data-no-minify=\"1\" data-cfasync=\"false\">"
           . "/* mbs-school-orders inline */\nwindow.MBS = " . wp_json_encode($config) . ";\n"
           . $js . "\n</script>\n";

    return $html;
}

/* -------------------------------------------------------------------------
 *  Keep optimizer plugins from combining/delaying our inline script
 * ---------------------------------------------------------------------- */
add_filter('rocket_delay_js_exclusions', function ($e) { $e[] = 'mbs-school-orders'; $e[] = 'window.MBS'; return $e; });
add_filter('rocket_excluded_inline_js_content', function ($e) { $e[] = 'mbs-school-orders'; return $e; });
add_filter('sgo_js_minify_exclude', function ($e) { $e[] = 'mbs-order'; return $e; });
add_filter('sgo_javascript_combine_exclude', function ($e) { $e[] = 'mbs-order'; return $e; });
add_filter('sgo_js_async_exclude', function ($e) { $e[] = 'mbs-order'; return $e; });

/* -------------------------------------------------------------------------
 *  AJAX: add one athlete's order to the WooCommerce cart.
 *  Price is ALWAYS recomputed here from the server-side config (never trust the browser).
 * ---------------------------------------------------------------------- */
add_action('wp_ajax_mbs_add', 'mbs_ajax_add');
add_action('wp_ajax_nopriv_mbs_add', 'mbs_ajax_add');
function mbs_ajax_add() {
    check_ajax_referer('mbs_add', 'nonce');

    if (!function_exists('WC') || is_null(WC()->cart)) {
        wp_send_json_error('Cart unavailable.');
    }

    $programs = mbs_programs();
    $key = sanitize_key($_POST['program'] ?? '');
    if (!isset($programs[$key])) {
        wp_send_json_error('Unknown program.');
    }
    $prog = $programs[$key];

    $pkgKey = sanitize_text_field(wp_unslash($_POST['pkg'] ?? 'NONE'));
    $addons_in = json_decode(wp_unslash($_POST['addons'] ?? '{}'), true);
    if (!is_array($addons_in)) $addons_in = array();

    $total = 0.0;
    $lines = array();

    // Package
    if ($pkgKey !== 'NONE' && isset($prog['packages'][$pkgKey])) {
        $pk = $prog['packages'][$pkgKey];
        $total += floatval($pk['price']);
        $lines[] = $pk['name'] . ' — $' . number_format($pk['price'], 2);
    }

    // Add-ons (recompute from config)
    $addon_map = array();
    foreach ($prog['addons'] as $a) {
        $addon_map[$a['id']] = $a;
    }
    $has_buddy = false;
    foreach ($addons_in as $id => $q) {
        $id = sanitize_key($id);
        $q  = max(0, min(20, intval($q)));
        if ($q > 0 && isset($addon_map[$id])) {
            $a = $addon_map[$id];
            $line = floatval($a['p']) * $q;
            $total += $line;
            $lines[] = $a['t'] . ($q > 1 ? ' × ' . $q : '') . ' — $' . number_format($line, 2);
            if (!empty($a['buddy'])) $has_buddy = true;
        }
    }

    if ($total <= 0) {
        wp_send_json_error('Please pick a package or at least one item.');
    }

    // Athlete / parent details
    $athlete = sanitize_text_field(wp_unslash($_POST['athlete'] ?? ''));
    $parent  = sanitize_text_field(wp_unslash($_POST['parent'] ?? ''));
    if ($athlete === '' || $parent === '') {
        wp_send_json_error('Athlete and parent names are required.');
    }
    $buddy = sanitize_text_field(wp_unslash($_POST['buddy'] ?? ''));
    if ($has_buddy && $buddy === '') {
        wp_send_json_error('Please enter the buddy name(s).');
    }

    $meta = array(
        'total'   => round($total, 2),
        'program' => $prog['name'],
        'athlete' => $athlete,
        'parent'  => $parent,
        'jersey'  => sanitize_text_field(wp_unslash($_POST['jersey'] ?? '')),
        'team'    => sanitize_text_field(wp_unslash($_POST['team'] ?? '')),
        'sport'   => sanitize_text_field(wp_unslash($_POST['sport'] ?? '')),
        'phone'   => sanitize_text_field(wp_unslash($_POST['phone'] ?? '')),
        'email'   => sanitize_email(wp_unslash($_POST['email'] ?? '')),
        'buddy'   => $buddy,
        'lines'   => array_map('sanitize_text_field', $lines),
    );

    $pid = mbs_get_container_id();
    if (!$pid) {
        wp_send_json_error('Order product not set up. Re-activate the plugin.');
    }

    // Unique key so each athlete stays its own cart line (never merged).
    $cart_key = WC()->cart->add_to_cart($pid, 1, 0, array(), array(
        'mbs'         => $meta,
        'mbs_unique'  => md5($athlete . microtime(true) . wp_rand()),
    ));

    if (!$cart_key) {
        wp_send_json_error('Could not add to cart.');
    }

    wp_send_json_success(array(
        'count'   => WC()->cart->get_cart_contents_count(),
        'cartUrl' => wc_get_cart_url(),
        'total'   => wc_price($meta['total']),
    ));
}

/* -------------------------------------------------------------------------
 *  Apply the stored custom price to each cart line
 * ---------------------------------------------------------------------- */
add_action('woocommerce_before_calculate_totals', 'mbs_set_prices', 20, 1);
function mbs_set_prices($cart) {
    if (is_admin() && !defined('DOING_AJAX')) return;
    if (empty($cart) || !is_a($cart, 'WC_Cart')) return;
    foreach ($cart->get_cart() as $item) {
        if (!empty($item['mbs']['total']) && isset($item['data'])) {
            $item['data']->set_price(floatval($item['mbs']['total']));
        }
    }
}

/* -------------------------------------------------------------------------
 *  Show the athlete breakdown in the cart & checkout
 * ---------------------------------------------------------------------- */
add_filter('woocommerce_get_item_data', 'mbs_item_data', 10, 2);
function mbs_item_data($data, $cart_item) {
    if (empty($cart_item['mbs'])) return $data;
    $m = $cart_item['mbs'];
    $team = trim(($m['sport'] ? $m['sport'] . ' · ' : '') . $m['team']);
    $data[] = array('name' => 'Athlete', 'value' => esc_html($m['athlete'] . ($m['jersey'] ? ' · #' . $m['jersey'] : '')));
    if ($team) $data[] = array('name' => 'Team', 'value' => esc_html($team));
    if (!empty($m['lines'])) {
        $data[] = array('name' => 'Order', 'value' => wp_kses_post(implode('<br>', array_map('esc_html', $m['lines']))));
    }
    if (!empty($m['buddy'])) $data[] = array('name' => 'Buddies', 'value' => esc_html($m['buddy']));
    return $data;
}

/* -------------------------------------------------------------------------
 *  Nice line-item name in the cart/checkout
 * ---------------------------------------------------------------------- */
add_filter('woocommerce_cart_item_name', 'mbs_cart_item_name', 10, 3);
function mbs_cart_item_name($name, $cart_item, $cart_item_key) {
    if (empty($cart_item['mbs'])) return $name;
    $m = $cart_item['mbs'];
    return esc_html($m['athlete'] ? $m['athlete'] . ' — Photo Order' : 'Photo Order');
}

/* -------------------------------------------------------------------------
 *  Persist all details onto the order line item (so the photographer
 *  sees everything in WooCommerce > Orders)
 * ---------------------------------------------------------------------- */
add_action('woocommerce_checkout_create_order_line_item', 'mbs_order_line_item', 10, 4);
function mbs_order_line_item($item, $cart_item_key, $values, $order) {
    if (empty($values['mbs'])) return;
    $m = $values['mbs'];
    $item->set_name(($m['program'] ? $m['program'] . ' — ' : '') . ($m['athlete'] ?: 'Photo Order'));
    $item->add_meta_data('Athlete', $m['athlete'] . ($m['jersey'] ? ' #' . $m['jersey'] : ''), true);
    $team = trim(($m['sport'] ? $m['sport'] . ' · ' : '') . $m['team']);
    if ($team)          $item->add_meta_data('Team', $team, true);
    if ($m['parent'])   $item->add_meta_data('Parent', $m['parent'], true);
    if ($m['phone'])    $item->add_meta_data('Phone', $m['phone'], true);
    if ($m['email'])    $item->add_meta_data('Email', $m['email'], true);
    if (!empty($m['lines'])) $item->add_meta_data('Order details', implode('  |  ', $m['lines']), true);
    if ($m['buddy'])    $item->add_meta_data('Buddies', $m['buddy'], true);
}
