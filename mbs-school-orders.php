<?php
/**
 * Plugin Name: MBS Order Forms
 * Description: Private online order forms for WooCommerce — schools, clubs, studios and events (Mark Nicholas Photography / Manhattan Beach Studios). Managed under WooCommerce > Order Forms; drop [mbs_order_form program="yourkey"] on a private page.
 * Version:     1.2.0
 * Author:      Anirudha
 * Requires PHP: 7.2
 * WC requires at least: 5.0
 */

if (!defined('ABSPATH')) exit;

define('MBS_SO_VER', '1.2.0');
define('MBS_SO_DIR', plugin_dir_path(__FILE__));
define('MBS_SO_URL', plugin_dir_url(__FILE__));
// Bump when assets/order-thumb.png changes, so installs that are still using OUR
// auto default get the new one. A thumbnail the client set themselves is untouched.
define('MBS_THUMB_VER', '2');

require_once MBS_SO_DIR . 'includes/programs.php';   // seed data only — see mbs-admin.php
require_once MBS_SO_DIR . 'includes/mbs-admin.php';  // School Order Forms manager + program storage
require_once MBS_SO_DIR . 'includes/mbs-export.php';

/**
 * Resolve an image reference to a URL.
 *
 * Program images can be either a file we ship in /assets ("samples/mug.jpg") or,
 * for logos picked in the admin screen, a full media-library URL. Anything that
 * already looks like a URL or an absolute path is passed straight through.
 */
function mbs_asset_url($v) {
    $v = (string) $v;
    if ($v === '') return '';
    if (preg_match('#^(https?:)?//#', $v) || $v[0] === '/') return $v;
    return MBS_SO_URL . 'assets/' . $v;
}

/* -------------------------------------------------------------------------
 *  Admin notice if WooCommerce is not active
 * ---------------------------------------------------------------------- */
add_action('admin_init', function () {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p><strong>MBS Order Forms</strong> needs WooCommerce active to work.</p></div>';
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
        mbs_ensure_container_image($pid); // no-op if it already has a thumbnail
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
    mbs_ensure_container_image($id);
    return $id;
}

/**
 * Give the hidden container product a clean branded thumbnail so it never shows
 * WooCommerce's grey "placeholder" image (which looked broken in the cart and in
 * the Square/WooPay order summary). Imports assets/order-thumb.png into the media
 * library and sets it as the product's featured image.
 *
 * Rules:
 *  - No image yet            -> import our default and set it.
 *  - Our default, but we've  -> re-import the new default (MBS_THUMB_VER bumped)
 *    shipped a new one          and replace it.
 *  - The client set their own -> never touch it.
 */
function mbs_ensure_container_image($product_id) {
    if (!$product_id) return;

    $current   = (int) get_post_thumbnail_id($product_id);
    $ours      = (int) get_option('mbs_order_thumb_id');
    $ours_ver  = (string) get_option('mbs_order_thumb_ver');

    // The client picked their own image — leave it completely alone.
    if ($current && $current !== $ours) return;

    // Already showing OUR current default — nothing to do.
    if ($current && $current === $ours && $ours_ver === MBS_THUMB_VER) return;

    $att_id = mbs_import_thumb($product_id);
    if (!$att_id) return;

    set_post_thumbnail($product_id, $att_id);
    update_option('mbs_order_thumb_id', (int) $att_id);
    update_option('mbs_order_thumb_ver', MBS_THUMB_VER);

    // Tidy up the previous auto-thumbnail we're replacing (never the client's).
    if ($ours && $ours !== $att_id) {
        wp_delete_attachment($ours, true);
    }
}

/** Import assets/order-thumb.png into the media library; returns the attachment id. */
function mbs_import_thumb($product_id) {
    $src = MBS_SO_DIR . 'assets/order-thumb.png';
    if (!file_exists($src) || !function_exists('wp_upload_dir')) return 0;

    $upload = wp_upload_dir();
    if (!empty($upload['error'])) return 0;
    $filename = wp_unique_filename($upload['path'], 'mbs-photo-order.png');
    $dest = trailingslashit($upload['path']) . $filename;
    if (!@copy($src, $dest)) return 0;

    $att_id = wp_insert_attachment(array(
        'post_mime_type' => 'image/png',
        'post_title'     => 'Sports Photo Order',
        'post_content'   => '',
        'post_status'    => 'inherit',
    ), $dest, $product_id);
    if (is_wp_error($att_id) || !$att_id) return 0;

    if (file_exists(ABSPATH . 'wp-admin/includes/image.php')) {
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $meta = wp_generate_attachment_metadata($att_id, $dest);
        wp_update_attachment_metadata($att_id, $meta);
    }
    return (int) $att_id;
}

/* -------------------------------------------------------------------------
 *  Dedicated real-payment checkout page for photo orders.
 *
 *  This site's normal WooCommerce "Checkout" page was repurposed into a
 *  gear-rental QUOTE form (a Contact Form 7 form, no payment). Photo orders are
 *  paid by card, so they need a real checkout. We keep our own page that simply
 *  holds the [woocommerce_checkout] shortcode, and route photo-order carts to it
 *  (see the filters below). The rental quote page is left completely untouched.
 * ---------------------------------------------------------------------- */
function mbs_get_checkout_page_id() {
    $pid = (int) get_option('mbs_checkout_page_id');
    if ($pid && get_post_status($pid) === 'publish' && get_post_type($pid) === 'page') {
        return $pid;
    }
    if (!function_exists('wp_insert_post')) return 0;
    $pid = wp_insert_post(array(
        'post_title'     => 'Sports Photo Checkout',
        'post_name'      => 'sports-photo-checkout',
        'post_content'   => '[woocommerce_checkout]',
        'post_status'    => 'publish',
        'post_type'      => 'page',
        'comment_status' => 'closed',
        'ping_status'    => 'closed',
    ));
    if ($pid && !is_wp_error($pid)) {
        update_option('mbs_checkout_page_id', (int) $pid);
        return (int) $pid;
    }
    return 0;
}

register_activation_hook(__FILE__, function () {
    // Copy the file-defined schools into the database on first activation, so the
    // existing live order pages keep working and nothing has to be retyped.
    mbs_maybe_seed_programs();
    if (class_exists('WooCommerce')) {
        mbs_get_container_id();
        mbs_get_checkout_page_id();
    }
});

/* -------------------------------------------------------------------------
 *  Shortcode: renders the order form for a given program
 * ---------------------------------------------------------------------- */
add_shortcode('mbs_order_form', 'mbs_shortcode');
function mbs_shortcode($atts) {
    $atts = shortcode_atts(array('program' => 'redondo'), $atts, 'mbs_order_form');
    $key  = sanitize_key($atts['program']);
    $prog = mbs_get_program($key);   // from the School Order Forms screen; hidden items already removed

    if (!$prog) {
        return '<p>MBS: unknown program "' . esc_html($key) . '".</p>';
    }
    if (!class_exists('WooCommerce')) {
        return '<p>MBS: WooCommerce is not active.</p>';
    }

    // Load the display + body webfonts the design uses (Anton / Barlow / Barlow Condensed /
    // JetBrains Mono). Without these a visitor's browser falls back to a system font whose
    // taller metrics can overlap the headline — and the design just looks off.
    wp_enqueue_style('mbs-fonts', 'https://fonts.googleapis.com/css2?family=Anton&family=Barlow:wght@400;500;600;700&family=Barlow+Condensed:wght@600;700&family=JetBrains+Mono&display=swap', array(), null);
    wp_enqueue_style('mbs-order', MBS_SO_URL . 'assets/mbs-order.css', array('mbs-fonts'), MBS_SO_VER);

    // Build the config the front-end renders from (single source of truth = PHP config above).
    $prog_js = $prog;
    $prog_js['logoUrl'] = mbs_asset_url($prog['logo'] ?? '');

    // Turn each item's 'img' filename into a full URL the browser can load in the sample popup.
    if (!empty($prog_js['packages'])) {
        foreach ($prog_js['packages'] as $k => $pk) {
            $prog_js['packages'][$k]['imgUrl'] = mbs_asset_url($pk['img'] ?? '');
        }
    }
    if (!empty($prog_js['addons'])) {
        foreach ($prog_js['addons'] as $i => $a) {
            $prog_js['addons'][$i]['imgUrl'] = mbs_asset_url($a['img'] ?? '');
        }
    }

    $config = array(
        'ajax'       => admin_url('admin-ajax.php'),
        'nonce'      => wp_create_nonce('mbs_add'),
        'cartUrl'    => wc_get_cart_url(),
        'programKey' => $key,
        'program'    => $prog_js,
    );

    // Edit mode: ?mbs_edit=<cart_item_key> re-opens the form pre-filled with that
    // athlete so a change replaces the cart line instead of adding a new one. (The
    // front-end ALSO fetches this via AJAX from the URL key, so editing still works
    // even when this page is served from cache — see mbs_ajax_edit_data.)
    if (!empty($_GET['mbs_edit']) && WC()->cart) {
        $ek   = sanitize_text_field(wp_unslash($_GET['mbs_edit']));
        $cart = WC()->cart->get_cart();
        if (isset($cart[$ek]) && !empty($cart[$ek]['mbs'])) {
            $config['editKey'] = $ek;
            $config['edit']    = mbs_build_edit($cart[$ek]['mbs']);
        }
    }

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
 *  Build the "edit" payload (all fields needed to re-populate the form) from a
 *  cart item's stored meta. Shared by the shortcode (page render) and the AJAX
 *  fetch below. Falls back to splitting the combined name for lines saved before
 *  the raw first/last fields existed.
 * ---------------------------------------------------------------------- */
function mbs_build_edit($m) {
    $af = $m['athFirst'] ?? ''; $al = $m['athLast'] ?? '';
    if ($af === '' && !empty($m['athlete'])) { $p = explode(' ', $m['athlete'], 2); $af = $p[0]; $al = $p[1] ?? ''; }
    $pf = $m['parFirst'] ?? ''; $pl = $m['parLast'] ?? '';
    if ($pf === '' && !empty($m['parent'])) { $p = explode(' ', $m['parent'], 2); $pf = $p[0]; $pl = $p[1] ?? ''; }
    return array(
        'athFirst' => $af, 'athLast' => $al,
        'jersey'   => $m['jersey'] ?? '', 'team' => $m['team'] ?? '', 'sport' => $m['sport'] ?? '',
        'parFirst' => $pf, 'parLast' => $pl,
        'phone'    => $m['phone'] ?? '', 'email' => $m['email'] ?? '', 'notes' => $m['notes'] ?? '',
        'buddy'    => $m['buddy'] ?? '',
        'pkg'      => $m['pkg'] ?? 'NONE',
        'addons'   => !empty($m['addons']) && is_array($m['addons']) ? $m['addons'] : (object) array(),
    );
}

/* -------------------------------------------------------------------------
 *  AJAX: return one cart line's saved details so the form can pre-fill for an
 *  edit. Fetched live (never cached), so "Edit this athlete" works even when the
 *  order-form page itself is served from WP Rocket / SiteGround cache.
 * ---------------------------------------------------------------------- */
add_action('wp_ajax_mbs_edit_data', 'mbs_ajax_edit_data');
add_action('wp_ajax_nopriv_mbs_edit_data', 'mbs_ajax_edit_data');
function mbs_ajax_edit_data() {
    check_ajax_referer('mbs_add', 'nonce');
    if (!function_exists('WC') || is_null(WC()->cart)) {
        wp_send_json_error('Cart unavailable.');
    }
    $ek   = sanitize_text_field(wp_unslash($_POST['key'] ?? ''));
    $cart = WC()->cart->get_cart();
    if (!isset($cart[$ek]) || empty($cart[$ek]['mbs'])) {
        wp_send_json_error('Item not found.');
    }
    wp_send_json_success(array('editKey' => $ek, 'edit' => mbs_build_edit($cart[$ek]['mbs'])));
}

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

    $key  = sanitize_key($_POST['program'] ?? '');
    $prog = mbs_get_program($key);   // hidden packages/items are already stripped, so they can't be ordered
    if (!$prog) {
        wp_send_json_error('Unknown program.');
    }

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
    $addon_qty = array();   // raw id => qty, kept so an order can be edited later
    foreach ($addons_in as $id => $q) {
        $id = sanitize_key($id);
        $q  = max(0, min(20, intval($q)));
        if ($q > 0 && isset($addon_map[$id])) {
            $a = $addon_map[$id];
            $line = floatval($a['p']) * $q;
            $total += $line;
            $lines[] = $a['t'] . ($q > 1 ? ' × ' . $q : '') . ' — $' . number_format($line, 2);
            $addon_qty[$id] = $q;
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
    $phone = sanitize_text_field(wp_unslash($_POST['phone'] ?? ''));
    $email = sanitize_email(wp_unslash($_POST['email'] ?? ''));
    if (strlen(preg_replace('/\D/', '', $phone)) !== 10) {
        wp_send_json_error('Please enter a 10-digit phone number.');
    }
    if ($email === '' || !is_email($email)) {
        wp_send_json_error('A valid email address is required.');
    }
    $buddy = sanitize_text_field(wp_unslash($_POST['buddy'] ?? ''));
    $notes = sanitize_textarea_field(wp_unslash($_POST['notes'] ?? ''));
    if (function_exists('mb_substr')) { $notes = mb_substr($notes, 0, 500); }

    // Remember which order-form page this came from, so the cart/checkout can
    // offer a "back to the order form" link.
    $form_url = esc_url_raw(wp_unslash($_POST['form_url'] ?? ''));
    if ($form_url && function_exists('WC') && WC()->session) {
        WC()->session->set('mbs_form_url', $form_url);
    }
    if ($has_buddy && $buddy === '') {
        wp_send_json_error('Please enter the buddy name(s).');
    }

    $meta = array(
        'total'    => round($total, 2),
        'program'  => $prog['name'],
        'athlete'  => $athlete,
        'parent'   => $parent,
        'athFirst' => sanitize_text_field(wp_unslash($_POST['athFirst'] ?? '')),
        'athLast'  => sanitize_text_field(wp_unslash($_POST['athLast'] ?? '')),
        'parFirst' => sanitize_text_field(wp_unslash($_POST['parFirst'] ?? '')),
        'parLast'  => sanitize_text_field(wp_unslash($_POST['parLast'] ?? '')),
        'jersey'   => sanitize_text_field(wp_unslash($_POST['jersey'] ?? '')),
        'team'     => sanitize_text_field(wp_unslash($_POST['team'] ?? '')),
        'sport'    => sanitize_text_field(wp_unslash($_POST['sport'] ?? '')),
        'phone'    => $phone,
        'email'    => $email,
        'buddy'    => $buddy,
        'notes'    => $notes,
        'pkey'     => $key,         // which order form this came from (for cart wording)
        'pkg'      => $pkgKey,      // raw selection, for editing later
        'addons'   => $addon_qty,   // raw id => qty, for editing later
        'lines'    => array_map('sanitize_text_field', $lines),
    );

    $pid = mbs_get_container_id();
    if (!$pid) {
        wp_send_json_error('Order product not set up. Re-activate the plugin.');
    }

    // If this is an edit of an existing cart line, drop the old one first so we
    // replace it instead of adding a duplicate.
    $edit_key = sanitize_text_field(wp_unslash($_POST['edit_key'] ?? ''));
    $is_edit  = false;
    if ($edit_key) {
        $cart = WC()->cart->get_cart();
        if (isset($cart[$edit_key]) && !empty($cart[$edit_key]['mbs'])) {
            WC()->cart->remove_cart_item($edit_key);
            $is_edit = true;
        }
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
        'edit'    => $is_edit,
    ));
}

/**
 * The word a given order form uses for the person an order is for ("Athlete" by
 * default, but a dance studio or an event may call them something else). Cart
 * lines saved before v1.2.0 have no form key, so they keep the original wording.
 */
function mbs_who_label($cart_item, $lower = false) {
    $label = 'Athlete';
    if (!empty($cart_item['mbs']['pkey'])) {
        $prog = mbs_get_program($cart_item['mbs']['pkey']);
        if ($prog && !empty($prog['whoLabel'])) $label = $prog['whoLabel'];
    }
    return $lower ? strtolower($label) : $label;
}

/** The same word, taken from whichever photo-order line is first in the cart. */
function mbs_cart_who_label($lower = false) {
    if (function_exists('WC') && !is_null(WC()->cart)) {
        foreach (WC()->cart->get_cart() as $item) {
            if (!empty($item['mbs'])) return mbs_who_label($item, $lower);
        }
    }
    return $lower ? 'athlete' : 'Athlete';
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
    $data[] = array('name' => 'Athlete', 'value' => '<strong>' . esc_html($m['athlete'] . ($m['jersey'] ? ' · #' . $m['jersey'] : '')) . '</strong>');
    if ($team) $data[] = array('name' => 'Team', 'value' => esc_html($team));
    // One row per ordered item. We used to join these with <br>, but Square/WooPay's
    // order summary renders meta as PLAIN TEXT, so the <br> showed up literally. A
    // separate labelled row per line reads cleanly in the cart, the checkout AND
    // WooPay. The first line is the package (when one was chosen); the rest are extras.
    if (!empty($m['lines'])) {
        $has_pkg = !empty($m['pkg']) && $m['pkg'] !== 'NONE';
        foreach ($m['lines'] as $i => $line) {
            $label = ($has_pkg && $i === 0) ? 'Package' : 'Item';
            $data[] = array('name' => $label, 'value' => esc_html($line));
        }
    }
    if (!empty($m['buddy'])) $data[] = array('name' => 'Buddies', 'value' => esc_html($m['buddy']));
    if (!empty($m['notes'])) $data[] = array('name' => 'Notes', 'value' => esc_html($m['notes']));
    return $data;
}

/* -------------------------------------------------------------------------
 *  Nice line-item name in the cart/checkout
 * ---------------------------------------------------------------------- */
add_filter('woocommerce_cart_item_name', 'mbs_cart_item_name', 10, 3);
function mbs_cart_item_name($name, $cart_item, $cart_item_key) {
    if (empty($cart_item['mbs'])) return $name;
    $m = $cart_item['mbs'];
    // Make the athlete's name the bold, prominent part of the cart line; keep the
    // "— Photo Order" tag small and muted so the name is what stands out.
    if (!empty($m['athlete'])) {
        $label = '<span style="font-weight:800;font-size:1.1em;color:#0b1f3a">' . esc_html($m['athlete']) . '</span>'
               . '<span style="color:#6b7a90;font-weight:500;font-size:.92em"> &mdash; Photo Order</span>';
    } else {
        $label = esc_html('Photo Order');
    }
    // On the cart page, offer an "Edit this athlete" link back to the order form.
    if (function_exists('is_cart') && is_cart()) {
        $form_url = (function_exists('WC') && WC()->session) ? WC()->session->get('mbs_form_url') : '';
        if ($form_url) {
            $edit = add_query_arg('mbs_edit', $cart_item_key, $form_url);
            $label .= '<br><a href="' . esc_url($edit) . '" class="mbs-edit-link" style="display:inline-block;margin-top:7px;padding:6px 14px;border:1.5px solid #0b1f3a;border-radius:8px;font-size:13px;font-weight:700;text-decoration:none;color:#0b1f3a">&#9998; Edit this ' . esc_html(mbs_who_label($cart_item, true)) . '</a>';
        }
    }
    return $label;
}

/* -------------------------------------------------------------------------
 *  Compatibility with the site's leftover gear-rental "quote mode".
 *
 *  The Bridge child theme (from when this site was an equipment-rental store)
 *  appends "/day" to every price and relabels the cart's checkout button to
 *  "Request a Quote" (and "Update cart" to "Update Quote"). That's correct for
 *  renting gear, but wrong for photo orders, which are paid online by card.
 *
 *  These filters quietly undo that behaviour ONLY when a photo order is in the
 *  cart. Any normal rental cart is left exactly as-is, so the gear-rental quote
 *  flow keeps working. Deactivating this plugin reverts everything.
 * ---------------------------------------------------------------------- */

// True if the current cart contains at least one MBS photo-order line.
function mbs_cart_has_order() {
    if (!function_exists('WC') || is_null(WC()->cart)) return false;
    foreach (WC()->cart->get_cart() as $item) {
        if (!empty($item['mbs'])) return true;
    }
    return false;
}

// 1) Strip the theme's "/day" suffix from our own cart lines (rental lines keep it).
add_filter('woocommerce_cart_item_price', 'mbs_strip_day_suffix', 20, 3);
function mbs_strip_day_suffix($price, $cart_item, $cart_item_key) {
    if (!empty($cart_item['mbs'])) {
        $price = preg_replace('#\s*/\s*day\s*$#i', '', $price);
    }
    return $price;
}

// 2) When a photo order is in the cart, swap the theme's relabelled
//    "Request a Quote" button back to a real "Proceed to Checkout" button.
//    The theme only overrode the button TEMPLATE (it left the standard
//    WooCommerce hook registered), so we can cleanly replace it here.
add_action('woocommerce_before_cart', 'mbs_maybe_fix_checkout_button');
function mbs_maybe_fix_checkout_button() {
    if (!mbs_cart_has_order()) return;
    remove_action('woocommerce_proceed_to_checkout', 'woocommerce_button_proceed_to_checkout', 20);
    add_action('woocommerce_proceed_to_checkout', 'mbs_render_checkout_button', 20);
}
function mbs_render_checkout_button() {
    // Link straight to our real-payment checkout page (don't rely on the filtered
    // wc_get_checkout_url, which an edge case could leave empty).
    $pid = mbs_get_checkout_page_id();
    $url = $pid ? get_permalink($pid) : wc_get_checkout_url();
    // IMPORTANT: this site's theme turns the checkout button into a "Request a Quote"
    // action by binding JS to the standard WooCommerce classes (.checkout-button / .wc-forward)
    // and cancelling the click. That hijacked OUR button too, so it did nothing for shoppers.
    // Fix: (a) do NOT use those classes, and (b) force navigation in JS via an inline handler
    // that runs first, so even if something calls preventDefault, we still go to checkout.
    echo '<a href="' . esc_url($url) . '"'
       . ' class="button alt mbs-checkout-button"'
       . ' data-mbs-checkout="1"'
       . ' onclick="window.location.href=this.href;return false;"'
       . ' style="display:inline-block">Continue to Payment &rarr;</a>';
}

// 3) Undo the theme's "Update cart" -> "Update Quote" relabel on the cart page
//    when a photo order is present (guarded so it only runs on that exact string).
//    NOTE: return a plain literal, NOT __()/translate() — calling a translation
//    function inside a 'gettext' filter re-enters this same filter and recurses.
add_filter('gettext', 'mbs_fix_update_cart_text', 30, 3);
function mbs_fix_update_cart_text($translated, $text, $domain) {
    if ($translated === 'Update Quote' && mbs_cart_has_order()) {
        return 'Update cart';
    }
    return $translated;
}

// 4) Send photo-order carts to our real-payment checkout page instead of the
//    site's rental-quote "Checkout" page. Cart-based, so rental carts (and any
//    non-cart context) still get the normal checkout URL untouched.
add_filter('woocommerce_get_checkout_url', 'mbs_checkout_url', 20, 1);
function mbs_checkout_url($url) {
    if (mbs_cart_has_order()) {
        $pid = mbs_get_checkout_page_id();
        if ($pid) return get_permalink($pid);
    }
    return $url;
}

// 5) Point the order-received / thank-you page at our checkout page for photo
//    orders (it hosts [woocommerce_checkout], which also renders the
//    confirmation). Order-based, so it still works after the cart is emptied.
add_filter('woocommerce_get_checkout_order_received_url', 'mbs_order_received_url', 20, 2);
function mbs_order_received_url($url, $order) {
    if (!$order || !is_a($order, 'WC_Order')) return $url;
    $cid = mbs_get_container_id();
    foreach ($order->get_items() as $item) {
        if ((int) $item->get_product_id() === (int) $cid) {
            $pid = mbs_get_checkout_page_id();
            if ($pid) {
                $u = wc_get_endpoint_url('order-received', $order->get_id(), get_permalink($pid));
                return add_query_arg('key', $order->get_order_key(), $u);
            }
            break;
        }
    }
    return $url;
}

// 6) Pre-fill the checkout's billing fields from what the parent already typed in
//    the order form (name / phone / email), so they don't have to enter it twice.
//    Only kicks in for photo-order carts; the buyer can still edit any field.
function mbs_first_order_meta() {
    if (!function_exists('WC') || is_null(WC()->cart)) return null;
    foreach (WC()->cart->get_cart() as $item) {
        if (!empty($item['mbs'])) return $item['mbs'];
    }
    return null;
}
function mbs_split_name($full) {
    $full = trim((string) $full);
    if ($full === '') return array('', '');
    $parts = preg_split('/\s+/', $full, 2);
    return array($parts[0], isset($parts[1]) ? $parts[1] : '');
}
add_filter('woocommerce_checkout_get_value', 'mbs_prefill_checkout', 10, 2);
function mbs_prefill_checkout($value, $input) {
    $m = mbs_first_order_meta();
    if (!$m) return $value;
    switch ($input) {
        case 'billing_email': return !empty($m['email']) ? $m['email'] : $value;
        case 'billing_phone': return !empty($m['phone']) ? $m['phone'] : $value;
        case 'billing_first_name':
            $n = mbs_split_name($m['parent'] ?? ''); return $n[0] !== '' ? $n[0] : $value;
        case 'billing_last_name':
            $n = mbs_split_name($m['parent'] ?? ''); return $n[1] !== '' ? $n[1] : $value;
    }
    return $value;
}

// 7) Offer a "back to the order form" link on the cart & checkout, so a parent
//    can return to fix a detail or add another athlete. Uses the order-form URL
//    saved when they added to cart.
add_action('woocommerce_before_cart', 'mbs_back_to_form_link', 5);
add_action('woocommerce_before_checkout_form', 'mbs_back_to_form_link', 5);
function mbs_back_to_form_link() {
    if (!mbs_cart_has_order()) return;
    $url = (function_exists('WC') && WC()->session) ? WC()->session->get('mbs_form_url') : '';
    if (!$url) return;
    echo '<p class="mbs-back-link" style="margin:0 0 18px;font-size:15px"><a href="' . esc_url($url) . '">&larr; CLICK HERE to add another ' . esc_html(mbs_cart_who_label(true)) . '</a></p>';
}

// 8) Lock our photo-order line to quantity 1. Each athlete is its own line and the
//    price is that athlete's whole order, so a cart quantity of 2 would just double
//    everything — which never makes sense here. Marking the product "sold
//    individually" makes the cart show a fixed "1" instead of an editable stepper.
add_filter('woocommerce_is_sold_individually', 'mbs_sold_individually', 10, 2);
function mbs_sold_individually($val, $product) {
    if (is_a($product, 'WC_Product') && (int) $product->get_id() === (int) mbs_get_container_id()) {
        return true;
    }
    return $val;
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
    if (!empty($m['notes'])) $item->add_meta_data('Notes', $m['notes'], true);

    // Hidden, machine-readable copies so the Manufacturing Export is exact
    // (no parsing of the human "Order details" string). Underscore prefix keeps
    // them out of the order display. See includes/mbs-export.php.
    if ($m['program']) $item->add_meta_data('_mbs_school', $m['program'], true);
    $exact = array();
    foreach ((array) $m['lines'] as $ln) {
        $name = preg_replace('/\s*—\s*\$[\d,]+\.\d{2}\s*$/u', '', (string) $ln);
        $qty  = 1;
        if (preg_match('/\s*×\s*(\d+)\s*$/u', $name, $mq)) {
            $qty  = max(1, (int) $mq[1]);
            $name = preg_replace('/\s*×\s*\d+\s*$/u', '', $name);
        }
        $name = trim($name);
        if ($name !== '') $exact[] = array('name' => $name, 'qty' => $qty);
    }
    if ($exact) $item->add_meta_data('_mbs_items', wp_json_encode($exact), true);
}
