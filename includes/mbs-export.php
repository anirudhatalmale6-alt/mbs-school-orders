<?php
/**
 * MBS School Orders — Manufacturing / Fulfillment export.
 *
 * Every order uses one WooCommerce "container product", so WooCommerce's own
 * screens show every order as the same product. The real per-family selections
 * live in each line item's meta ("Order details", Athlete, Parent, Email, ...).
 * This module reads that meta and produces two CSVs:
 *   - Detailed : one row PER ITEM PER FAMILY (School, Item, Qty, Athlete, Team,
 *                Parent, Email, Phone, Order #, Date, Status) — filter by Item
 *                in Excel to see every family that bought a given product.
 *   - Summary  : one row PER ITEM (School, Item, Total Qty, # of families).
 *
 * Works on orders placed BEFORE this update by parsing the "Order details"
 * string; new orders also carry exact structured meta (_mbs_items) so no parsing
 * is needed for them.
 */

if (!defined('ABSPATH')) exit;

/* Admin page under WooCommerce menu. */
add_action('admin_menu', 'mbs_export_menu', 99);
function mbs_export_menu() {
    add_submenu_page(
        'woocommerce',
        'Order Forms — Manufacturing Export',
        'Order Export',
        'manage_woocommerce',
        'mbs-school-export',
        'mbs_export_page'
    );
}

function mbs_export_page() {
    $statuses = function_exists('wc_get_order_statuses') ? wc_get_order_statuses() : array(
        'wc-processing' => 'Processing', 'wc-completed' => 'Completed', 'wc-on-hold' => 'On hold',
    );
    $sel = isset($_GET['status']) && is_array($_GET['status']) ? array_map('sanitize_text_field', $_GET['status']) : array('wc-processing', 'wc-completed');
    $from   = isset($_GET['from'])   ? sanitize_text_field($_GET['from'])   : '';
    $to     = isset($_GET['to'])     ? sanitize_text_field($_GET['to'])     : '';
    $school = isset($_GET['school']) ? sanitize_text_field($_GET['school']) : '';
    $nonce  = wp_create_nonce('mbs_export');
    ?>
    <div class="wrap">
      <h1>School Orders — Manufacturing Export</h1>
      <p style="max-width:760px">Download a spreadsheet of what families ordered, broken out by item — so you can total up what to manufacture. Every order looks like one product inside WooCommerce because the order form packs each family's selections into a single line item; this export unpacks them back into per-item rows.</p>

      <form method="get" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="background:#fff;border:1px solid #ccd0d4;padding:16px 18px;max-width:760px">
        <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($nonce); ?>">
        <table class="form-table" role="presentation">
          <tr>
            <th scope="row"><label>Order status</label></th>
            <td>
              <?php foreach ($statuses as $k => $label) :
                $key = (strpos($k, 'wc-') === 0) ? $k : 'wc-' . $k; ?>
                <label style="display:inline-block;margin:0 14px 6px 0">
                  <input type="checkbox" name="status[]" value="<?php echo esc_attr($key); ?>" <?php checked(in_array($key, $sel, true)); ?>>
                  <?php echo esc_html($label); ?>
                </label>
              <?php endforeach; ?>
            </td>
          </tr>
          <tr>
            <th scope="row"><label for="mbs_from">Date range</label></th>
            <td>
              From <input type="date" id="mbs_from" name="from" value="<?php echo esc_attr($from); ?>">
              &nbsp; To <input type="date" name="to" value="<?php echo esc_attr($to); ?>">
              <p class="description">Leave blank for all dates. Range is by order date.</p>
            </td>
          </tr>
          <tr>
            <th scope="row"><label for="mbs_school">School / program contains</label></th>
            <td>
              <input type="text" id="mbs_school" name="school" value="<?php echo esc_attr($school); ?>" class="regular-text" placeholder="(optional) e.g. Mira Costa">
            </td>
          </tr>
        </table>
        <p>
          <button type="submit" name="action" value="mbs_export_detail" class="button button-primary">Download detailed CSV (per item, per family)</button>
          &nbsp;
          <button type="submit" name="action" value="mbs_export_summary" class="button">Download summary CSV (totals per item)</button>
        </p>
      </form>
    </div>
    <?php
}

/* Both buttons submit to admin-post.php with the same nonce. */
add_action('admin_post_mbs_export_detail',  'mbs_export_run');
add_action('admin_post_mbs_export_summary', 'mbs_export_run');

function mbs_export_run() {
    if (!current_user_can('manage_woocommerce')) wp_die('Not allowed.');
    check_admin_referer('mbs_export');

    $mode    = (isset($_GET['action']) && $_GET['action'] === 'mbs_export_summary') ? 'summary' : 'detail';
    $sel     = isset($_GET['status']) && is_array($_GET['status']) ? array_map('sanitize_text_field', $_GET['status']) : array('wc-processing', 'wc-completed');
    $from    = isset($_GET['from'])   ? sanitize_text_field($_GET['from'])   : '';
    $to      = isset($_GET['to'])     ? sanitize_text_field($_GET['to'])     : '';
    $school_f = isset($_GET['school']) ? trim(sanitize_text_field($_GET['school'])) : '';

    $rows = mbs_export_collect($sel, $from, $to, $school_f);

    $fname = 'mbs-orders-' . $mode . '-' . gmdate('Ymd') . '.csv';
    nocache_headers();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $fname . '"');
    $out = fopen('php://output', 'w');
    fprintf($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel shows accents correctly

    if ($mode === 'summary') {
        // Aggregate by School + Item.
        $agg = array();
        foreach ($rows as $r) {
            $k = $r['school'] . "\x1f" . $r['item'];
            if (!isset($agg[$k])) $agg[$k] = array('school' => $r['school'], 'item' => $r['item'], 'qty' => 0, 'fam' => array());
            $agg[$k]['qty'] += $r['qty'];
            $agg[$k]['fam'][strtolower($r['email'] ?: $r['parent'] ?: $r['athlete'])] = true;
        }
        // Sort by item, then school.
        usort($agg, function ($a, $b) {
            $c = strcasecmp($a['item'], $b['item']);
            return $c !== 0 ? $c : strcasecmp($a['school'], $b['school']);
        });
        fputcsv($out, array('School / Program', 'Item', 'Total Qty', '# of Families'));
        foreach ($agg as $a) {
            fputcsv($out, array($a['school'], $a['item'], $a['qty'], count($a['fam'])));
        }
    } else {
        fputcsv($out, array('School / Program', 'Item', 'Qty', 'Athlete', 'Jersey', 'Team', 'Parent', 'Email', 'Phone', 'Address', 'Notes', 'Order #', 'Date', 'Status'));
        foreach ($rows as $r) {
            fputcsv($out, array(
                $r['school'], $r['item'], $r['qty'], $r['athlete'], $r['jersey'], $r['team'],
                $r['parent'], $r['email'], $r['phone'], $r['address'], $r['notes'], $r['order'], $r['date'], $r['status'],
            ));
        }
    }
    fclose($out);
    exit;
}

/**
 * Walk matching orders and return a flat list of item rows.
 * Prefers exact structured meta (_mbs_items) on new orders; falls back to
 * parsing the human "Order details" string on older orders.
 */
function mbs_export_collect($statuses, $from, $to, $school_f) {
    $rows = array();
    if (!function_exists('wc_get_orders')) return $rows;

    $statuses = array_map(function ($s) { return (strpos($s, 'wc-') === 0) ? substr($s, 3) : $s; }, $statuses);
    if (empty($statuses)) $statuses = array('processing', 'completed');

    $args = array(
        'limit'   => -1,
        'status'  => $statuses,
        'orderby' => 'date',
        'order'   => 'ASC',
        'type'    => 'shop_order',
    );
    if ($from) $args['date_after']  = $from . ' 00:00:00';
    if ($to)   $args['date_before'] = $to . ' 23:59:59';

    $orders = wc_get_orders($args);
    foreach ($orders as $order) {
        $oid  = $order->get_id();
        $date = $order->get_date_created() ? $order->get_date_created()->date('Y-m-d') : '';
        $stat = $order->get_status();

        // Billing address as one clean line (repeated on every row of this order).
        $addr_parts = array_filter(array(
            $order->get_billing_address_1(), $order->get_billing_address_2(),
            $order->get_billing_city(), $order->get_billing_state(),
            $order->get_billing_postcode(), $order->get_billing_country(),
        ));
        $address = implode(', ', $addr_parts);

        foreach ($order->get_items() as $item) {
            $athlete = (string) $item->get_meta('Athlete');
            $jersey  = '';
            if (preg_match('/#\s*([A-Za-z0-9]+)\s*$/', $athlete, $mj)) {
                $jersey  = $mj[1];
                $athlete = trim(preg_replace('/#\s*[A-Za-z0-9]+\s*$/', '', $athlete));
            }
            $team   = (string) $item->get_meta('Team');
            $parent = (string) $item->get_meta('Parent');
            $email  = (string) $item->get_meta('Email');
            $phone  = (string) $item->get_meta('Phone');
            $notes  = (string) $item->get_meta('Notes');

            // School / program: exact meta first, else the item-name prefix "Program — Athlete".
            $school = (string) $item->get_meta('_mbs_school');
            if ($school === '') {
                $nm = $item->get_name();
                $school = (strpos($nm, ' — ') !== false) ? trim(substr($nm, 0, strpos($nm, ' — '))) : '';
            }

            if ($school_f !== '' && stripos($school, $school_f) === false) continue;

            // Items: exact structured meta if present, else parse "Order details".
            $items = array();
            $raw = $item->get_meta('_mbs_items');
            if ($raw) {
                $dec = json_decode($raw, true);
                if (is_array($dec)) {
                    foreach ($dec as $it) {
                        $name = isset($it['name']) ? trim($it['name']) : '';
                        $qty  = isset($it['qty'])  ? max(1, (int) $it['qty']) : 1;
                        if ($name !== '') $items[] = array('name' => $name, 'qty' => $qty);
                    }
                }
            }
            if (empty($items)) {
                $details = (string) $item->get_meta('Order details');
                foreach (mbs_parse_details($details) as $it) $items[] = $it;
            }
            if (empty($items)) continue;

            foreach ($items as $it) {
                $rows[] = array(
                    'school' => $school, 'item' => $it['name'], 'qty' => $it['qty'],
                    'athlete' => $athlete, 'jersey' => $jersey, 'team' => $team,
                    'parent' => $parent, 'email' => $email, 'phone' => $phone,
                    'address' => $address, 'notes' => $notes,
                    'order' => $oid, 'date' => $date, 'status' => $stat,
                );
            }
        }
    }
    return $rows;
}

/**
 * Parse an "Order details" string like
 *   "Silver Package — $45.00  |  Extra 5x7 × 3 — $27.00"
 * into [{name, qty}, ...]. Strips the trailing " — $price" and reads "× N".
 */
function mbs_parse_details($details) {
    $out = array();
    if ($details === '') return $out;
    $parts = preg_split('/\s*\|\s*/', $details);
    foreach ($parts as $p) {
        $p = trim($p);
        if ($p === '') continue;
        // Drop the trailing price " — $12.34".
        $p = preg_replace('/\s*—\s*\$[\d,]+\.\d{2}\s*$/u', '', $p);
        $qty = 1;
        // Read a trailing "× N" quantity.
        if (preg_match('/\s*×\s*(\d+)\s*$/u', $p, $mq)) {
            $qty = max(1, (int) $mq[1]);
            $p = trim(preg_replace('/\s*×\s*\d+\s*$/u', '', $p));
        }
        $p = trim($p);
        if ($p !== '') $out[] = array('name' => $p, 'qty' => $qty);
    }
    return $out;
}
