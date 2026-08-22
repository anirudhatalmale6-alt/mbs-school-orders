<?php
/**
 * School Order Forms manager — the no-code admin screen.
 *
 * Before this file, every school lived in includes/programs.php and adding one
 * meant editing PHP. Now schools live in the database (option `mbs_programs`)
 * and programs.php is only the SEED: on first load its contents are copied into
 * the database once, so the live Redondo page keeps working untouched and
 * nothing has to be retyped.
 *
 * After seeding, the database is the single source of truth. Editing
 * programs.php by hand has no further effect (by design — otherwise a file edit
 * and a screen edit would silently fight each other).
 */

if (!defined('ABSPATH')) exit;

const MBS_PROGRAMS_OPTION = 'mbs_programs';

/* -------------------------------------------------------------------------
 *  Storage
 * ---------------------------------------------------------------------- */

/**
 * Copy the file-defined programs into the database exactly once.
 *
 * Detection is on `false === get_option(...)` (i.e. the option has never been
 * created), NOT on "is it empty" — otherwise deleting your last school would
 * resurrect the seed data on the next page load.
 */
function mbs_maybe_seed_programs() {
    if (false !== get_option(MBS_PROGRAMS_OPTION)) return;
    $seed = function_exists('mbs_programs') ? mbs_programs() : array();
    add_option(MBS_PROGRAMS_OPTION, $seed, '', 'no');
}

/** Every program, exactly as stored (including any switched-off items). */
function mbs_get_programs() {
    mbs_maybe_seed_programs();
    $db = get_option(MBS_PROGRAMS_OPTION);
    if (false === $db || !is_array($db)) {
        // Option missing or corrupted — fall back to the file so the live order
        // pages keep working rather than showing "unknown program".
        return function_exists('mbs_programs') ? mbs_programs() : array();
    }
    return $db;
}

function mbs_save_programs($programs) {
    update_option(MBS_PROGRAMS_OPTION, $programs, 'no');
}

/**
 * One program as the ORDER FORM should see it: anything switched off in the
 * admin screen is removed here. Used by both the shortcode and the AJAX price
 * recompute, so a hidden item can't be ordered even by a hand-made request.
 */
function mbs_get_program($key) {
    $all = mbs_get_programs();
    if (!isset($all[$key]) || !is_array($all[$key])) return null;
    $p = $all[$key];

    if (!empty($p['packages']) && is_array($p['packages'])) {
        foreach ($p['packages'] as $k => $pk) {
            if (!empty($pk['off'])) unset($p['packages'][$k]);
        }
    }
    if (!empty($p['addons']) && is_array($p['addons'])) {
        $keep = array();
        foreach ($p['addons'] as $a) {
            if (empty($a['off'])) $keep[] = $a;
        }
        $p['addons'] = $keep;
    }
    return $p;
}

/**
 * Find a published page that already contains this form's shortcode.
 *
 * Pages created by hand before v1.1.0 (Redondo's, for one) aren't linked to a
 * form record, so without this the edit screen would offer to "create the order
 * page" and quietly produce a SECOND copy of a page that already exists.
 */
function mbs_find_page_for_key($key) {
    global $wpdb;
    if ($key === '' || empty($wpdb)) return 0;

    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT ID, post_content FROM {$wpdb->posts}
          WHERE post_type = 'page' AND post_status = 'publish' AND post_content LIKE %s
          ORDER BY ID ASC LIMIT 100",
        '%' . $wpdb->esc_like('[mbs_order_form') . '%'
    ));
    if (!$rows) return 0;

    foreach ($rows as $row) {
        if (!preg_match_all('/\[mbs_order_form([^\]]*)\]/', $row->post_content, $m)) continue;
        foreach ($m[1] as $attrs) {
            if (preg_match('/program\s*=\s*["\']?([a-z0-9_\-]+)/i', $attrs, $a)) {
                if (strtolower($a[1]) === $key) return (int) $row->ID;
            } elseif (trim($attrs) === '' && $key === 'redondo') {
                // [mbs_order_form] with no attribute falls back to "redondo".
                return (int) $row->ID;
            }
        }
    }
    return 0;
}

/**
 * The page id for a form, adopting an existing page the first time we find one
 * so the link sticks and we never offer to create a duplicate.
 */
function mbs_program_page_id($key, $prog) {
    $page_id = isset($prog['page_id']) ? (int) $prog['page_id'] : 0;
    if ($page_id && get_post_status($page_id) === 'publish') return $page_id;

    $found = mbs_find_page_for_key($key);
    if ($found) {
        $all = mbs_get_programs();
        if (isset($all[$key])) {
            $all[$key]['page_id'] = $found;
            mbs_save_programs($all);
        }
        return $found;
    }
    return 0;
}

/** Blank order form used by "Add New Order Form". */
function mbs_program_defaults() {
    return array(
        'name'          => '',
        'line1'         => '',
        'line2'         => 'Sports',
        'year'          => (string) gmdate('Y'),
        'mascot'        => '',
        'crest'         => '',
        'crestMascot'   => '',
        'logo'          => '',
        'logoSpot'      => 'left',   // left = beside the heading, right = its own big column
        'showHow'       => 1,
        'sports'        => array('Football'),
        'divisionLabel' => 'Team / Division',
        'divisions'     => array('Varsity', 'JV', 'Freshman'),
        // Wording. Defaults keep the original sports-photo language, so every
        // form that existed before v1.2.0 reads exactly as it always did.
        'whoLabel'      => 'Athlete',
        'buyerLabel'    => 'Parent',
        'jerseyLabel'   => 'Jersey #',
        'showJersey'    => 1,
        'sportLabel'    => 'Sport',
        'intro'         => '',
        // Which blocks the form asks for at all. Defaults = the original form.
        'showWho'       => 1,
        'showBuyer'     => 1,
        'phoneMode'     => 'req',   // req | opt | off
        'emailMode'     => 'req',   // req | opt | off
        'showNotes'     => 1,
        // Per-form branding + the printable order form
        'colorBg'       => '',
        'colorAccent'   => '',
        'pdfUrl'        => '',
        'pdfLabel'      => '',
        'deadline'      => '',
        'productsUrl'   => '',
        'packages'      => array(),
        'addons'        => array(),
        'page_id'       => 0,
    );
}

/* -------------------------------------------------------------------------
 *  Small helpers
 * ---------------------------------------------------------------------- */

/** Textarea (one per line) <-> array. */
function mbs_lines_to_array($text) {
    $out = array();
    foreach (preg_split('/\r\n|\r|\n/', (string) $text) as $line) {
        $line = sanitize_text_field(trim($line));
        if ($line !== '') $out[] = $line;
    }
    return $out;
}
function mbs_array_to_lines($arr) {
    return implode("\n", array_map('strval', (array) $arr));
}

/** Admin screen URL. */
function mbs_admin_url($args = array()) {
    return add_query_arg(array_merge(array('page' => 'mbs-school-forms'), $args), admin_url('admin.php'));
}

/**
 * Package tags become JSON object KEYS on the front end. A purely numeric key
 * would make PHP's json_encode emit an ARRAY instead of an object, and the form
 * JS does Object.keys(P.packages) — so force tags to start with a letter.
 */
function mbs_clean_tag($tag) {
    $tag = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $tag));
    if ($tag === '') return '';
    if (ctype_digit($tag)) $tag = 'P' . $tag;
    return substr($tag, 0, 6);
}

/**
 * The package "what's included" text is rendered as HTML on the order form, and
 * one of the original packages uses <b> to highlight "Digital File included".
 * Showing raw tags in an admin text box looks broken, so bold is presented as
 * **stars** on the way in and converted back on the way out. Everything else is
 * shown as the real character (× rather than &times;).
 */
function mbs_inc_to_editor($html) {
    $s = html_entity_decode((string) $html, ENT_QUOTES, 'UTF-8');
    $s = preg_replace('#</?(?:b|strong)>#i', '**', $s);
    return $s;
}
function mbs_inc_from_editor($text) {
    $s = preg_replace('/\*\*(.+?)\*\*/s', '<b>$1</b>', (string) $text);
    return wp_kses($s, array('b' => array(), 'strong' => array(), 'em' => array(), 'i' => array(), 'br' => array()));
}

/** Turn an item title into a stable id when one isn't supplied. */
function mbs_make_id($title, $taken) {
    $base = sanitize_key(str_replace(array('×', '&times;'), 'x', (string) $title));
    $base = preg_replace('/[^a-z0-9]/', '', $base);
    if ($base === '') $base = 'item';
    $base = substr($base, 0, 20);
    $id = $base;
    $n = 2;
    while (in_array($id, $taken, true)) {
        $id = $base . $n;
        $n++;
    }
    return $id;
}

/* -------------------------------------------------------------------------
 *  Menu
 * ---------------------------------------------------------------------- */
add_action('admin_menu', function () {
    add_submenu_page(
        'woocommerce',
        'Order Forms',
        'Order Forms',
        'manage_woocommerce',
        'mbs-school-forms',
        'mbs_admin_router'
    );
}, 20);

function mbs_admin_router() {
    if (!current_user_can('manage_woocommerce')) {
        wp_die('You do not have permission to manage order forms.');
    }
    $action = isset($_GET['action']) ? sanitize_key($_GET['action']) : 'list';
    if ($action === 'edit') {
        mbs_admin_edit_screen();
        return;
    }
    mbs_admin_list_screen();
}

/* -------------------------------------------------------------------------
 *  Screen 1 — the list
 * ---------------------------------------------------------------------- */
function mbs_admin_list_screen() {
    $programs = mbs_get_programs();
    ?>
    <div class="wrap">
        <h1 class="wp-heading-inline">Order Forms</h1>
        <a href="<?php echo esc_url(mbs_admin_url(array('action' => 'edit'))); ?>" class="page-title-action">Add New Order Form</a>
        <hr class="wp-header-end">

        <?php if (!empty($_GET['saved'])) : ?>
            <div class="notice notice-success is-dismissible"><p>Saved.</p></div>
        <?php endif; ?>
        <?php if (!empty($_GET['deleted'])) : ?>
            <div class="notice notice-success is-dismissible"><p>Order form removed. Its page was left on the site — delete it from Pages if you don't want it.</p></div>
        <?php endif; ?>

        <p class="description" style="max-width:820px">
            Each row here is one private order page — a school, a club, a studio, or a one-off event.
            The quickest way to set up a new one is <strong>Duplicate</strong> an existing form and change
            what's different; your product list usually stays the same.
        </p>

        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th style="width:26%">Order form</th>
                    <th style="width:12%">Shortcode key</th>
                    <th style="width:10%">Packages</th>
                    <th style="width:10%">Add-ons</th>
                    <th style="width:22%">Order page</th>
                    <th style="width:20%">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($programs)) : ?>
                <tr><td colspan="6">No order forms yet. <a href="<?php echo esc_url(mbs_admin_url(array('action' => 'edit'))); ?>">Add your first one.</a></td></tr>
            <?php endif; ?>
            <?php foreach ($programs as $key => $p) :
                $edit_url = mbs_admin_url(array('action' => 'edit', 'key' => $key));
                $page_id  = mbs_program_page_id($key, $p);
                $page_ok  = $page_id > 0;
                $dup_url  = wp_nonce_url(admin_url('admin-post.php?action=mbs_duplicate_program&key=' . rawurlencode($key)), 'mbs_dup_' . $key);
                $del_url  = wp_nonce_url(admin_url('admin-post.php?action=mbs_delete_program&key=' . rawurlencode($key)), 'mbs_del_' . $key);
                $n_pkg    = !empty($p['packages']) ? count(array_filter((array) $p['packages'], function ($x) { return empty($x['off']); })) : 0;
                $n_add    = !empty($p['addons'])   ? count(array_filter((array) $p['addons'],   function ($x) { return empty($x['off']); })) : 0;
            ?>
                <tr>
                    <td>
                        <strong><a href="<?php echo esc_url($edit_url); ?>"><?php echo esc_html($p['name'] !== '' ? $p['name'] : $key); ?></a></strong>
                    </td>
                    <td><code><?php echo esc_html($key); ?></code></td>
                    <td><?php echo (int) $n_pkg; ?></td>
                    <td><?php echo (int) $n_add; ?></td>
                    <td>
                        <?php if ($page_ok) : ?>
                            <a href="<?php echo esc_url(get_permalink($page_id)); ?>" target="_blank" rel="noopener">View page</a>
                            &nbsp;·&nbsp;
                            <a href="<?php echo esc_url(get_edit_post_link($page_id)); ?>">Edit page</a>
                        <?php else : ?>
                            <span style="color:#b32d2e">Not created yet</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <a href="<?php echo esc_url($edit_url); ?>">Edit</a>
                        &nbsp;·&nbsp;
                        <a href="<?php echo esc_url($dup_url); ?>">Duplicate</a>
                        &nbsp;·&nbsp;
                        <a href="<?php echo esc_url($del_url); ?>" style="color:#b32d2e"
                           onclick="return confirm('Remove <?php echo esc_js($p['name'] !== '' ? $p['name'] : $key); ?>? The page itself is left on the site.');">Delete</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
}

/* -------------------------------------------------------------------------
 *  Screen 2 — add / edit one school
 * ---------------------------------------------------------------------- */
function mbs_admin_edit_screen() {
    $programs = mbs_get_programs();
    $key      = isset($_GET['key']) ? sanitize_key($_GET['key']) : '';
    $is_new   = ($key === '' || !isset($programs[$key]));
    $p        = $is_new ? mbs_program_defaults() : array_merge(mbs_program_defaults(), $programs[$key]);

    $page_id = $is_new ? 0 : mbs_program_page_id($key, $p);
    $page_ok = $page_id > 0;

    // Existing add-on groups, offered as a datalist so groups stay consistent.
    $groups = array();
    foreach ($programs as $prog) {
        foreach ((array) ($prog['addons'] ?? array()) as $a) {
            if (!empty($a['group']) && !in_array($a['group'], $groups, true)) $groups[] = $a['group'];
        }
    }

    wp_enqueue_media();
    // Core-registered, so this is a handle not a dependency we ship. The reorder arrows
    // work without it; this only adds drag.
    wp_enqueue_script('jquery-ui-sortable');
    ?>
    <style>
        .mbs-drop td { height: 46px; background: #f0f6fc; }
        .mbs-sortable tbody tr.ui-sortable-helper { box-shadow: 0 4px 14px rgba(0,0,0,.18); }
        .mbs-q input[type=text] { font-size: 12px; }
    </style>
    <div class="wrap">
        <h1><?php echo $is_new ? 'Add New Order Form' : 'Edit ' . esc_html($p['name'] !== '' ? $p['name'] : $key); ?></h1>

        <?php if (!empty($_GET['saved'])) : ?>
            <div class="notice notice-success is-dismissible"><p>
                Saved.
                <?php if ($page_ok) : ?>
                    Order page: <a href="<?php echo esc_url(get_permalink($page_id)); ?>" target="_blank" rel="noopener"><?php echo esc_html(get_permalink($page_id)); ?></a>
                <?php endif; ?>
            </p></div>
        <?php endif; ?>
        <?php if (!empty($_GET['err'])) : ?>
            <div class="notice notice-error"><p><?php echo esc_html(wp_unslash($_GET['err'])); ?></p></div>
        <?php endif; ?>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="mbs-form">
            <input type="hidden" name="action" value="mbs_save_program">
            <input type="hidden" name="orig_key" value="<?php echo esc_attr($is_new ? '' : $key); ?>">
            <?php wp_nonce_field('mbs_save_program'); ?>

            <h2 class="title">Name &amp; branding</h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="mbs_name">Name</label></th>
                    <td>
                        <input name="name" id="mbs_name" type="text" class="regular-text" required
                               value="<?php echo esc_attr($p['name']); ?>" placeholder="Redondo Union Sports 2026">
                        <p class="description">A school, club, studio or event. Shown on order confirmations and in your exports.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="mbs_key">Shortcode key</label></th>
                    <td>
                        <input name="key" id="mbs_key" type="text" class="regular-text" required
                               value="<?php echo esc_attr($is_new ? '' : $key); ?>" placeholder="redondo" pattern="[a-z0-9_\-]+">
                        <p class="description">
                            Lower-case, no spaces. Used in the shortcode: <code>[mbs_order_form program="<span id="mbs_key_echo"><?php echo esc_html($is_new ? 'yourkey' : $key); ?></span>"]</code><br>
                            <?php if (!$is_new) : ?>
                                <strong>Careful:</strong> changing this on a form that already has a live page will break that page
                                until you update the shortcode on it.
                            <?php endif; ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Header</th>
                    <td>
                        <input name="line1" type="text" class="regular-text" value="<?php echo esc_attr($p['line1']); ?>" placeholder="Redondo Union" style="max-width:220px">
                        <input name="line2" type="text" value="<?php echo esc_attr($p['line2']); ?>" placeholder="Sports" style="max-width:140px">
                        <input name="year" type="text" value="<?php echo esc_attr($p['year']); ?>" placeholder="2026" style="max-width:90px">
                        <p class="description">The three parts of the big headline: first line, second line, year.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="mbs_mascot">Mascot line</label></th>
                    <td>
                        <input name="mascot" id="mbs_mascot" type="text" class="regular-text" value="<?php echo esc_attr($p['mascot']); ?>" placeholder="Sea Hawks Athletics">
                    </td>
                </tr>
                <tr>
                    <th scope="row">Logo</th>
                    <td>
                        <input name="logo" id="mbs_logo" type="text" class="large-text code" value="<?php echo esc_attr($p['logo']); ?>">
                        <p>
                            <button type="button" class="button" id="mbs_pick_logo">Choose from Media Library</button>
                            <button type="button" class="button-link" id="mbs_clear_logo" style="color:#b32d2e;margin-left:10px">Remove logo</button>
                        </p>
                        <div id="mbs_logo_prev" style="margin-top:8px;<?php echo $p['logo'] === '' ? 'display:none' : ''; ?>">
                            <img src="<?php echo esc_url(mbs_asset_url($p['logo'])); ?>" alt=""
                                 style="max-height:80px;background:#0b1f3a;padding:8px;border-radius:8px">
                        </div>
                        <p class="description">A white or single-colour logo works best — it sits on the dark navy header.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="mbs_logospot">Logo placement</label></th>
                    <td>
                        <select name="logoSpot" id="mbs_logospot">
                            <option value="left" <?php selected(($p['logoSpot'] ?? 'left'), 'left'); ?>>Beside the heading (small)</option>
                            <option value="right" <?php selected(($p['logoSpot'] ?? 'left'), 'right'); ?>>On the right, large</option>
                        </select>
                        <p class="description">
                            "On the right, large" gives the logo its own column and up to three times the size — worth it when
                            you want the school, team or event to feel like the page belongs to them.
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">"How ordering works"</th>
                    <td>
                        <label><input type="checkbox" name="showHow" value="1" <?php checked(!empty($p['showHow'])); ?>> Show the three-step box in the header</label>
                        <p class="description">Untick it to give the logo the whole right-hand side.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Initials fallback</th>
                    <td>
                        <input name="crest" type="text" value="<?php echo esc_attr($p['crest']); ?>" placeholder="RU" style="max-width:90px">
                        <input name="crestMascot" type="text" value="<?php echo esc_attr($p['crestMascot']); ?>" placeholder="SEA HAWKS" style="max-width:200px">
                        <p class="description">Only used when no logo is set.</p>
                    </td>
                </tr>
            </table>

            <h2 class="title">Groups</h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="mbs_sports">Sports</label></th>
                    <td>
                        <textarea name="sports" id="mbs_sports" rows="3" class="large-text code"><?php echo esc_textarea(mbs_array_to_lines($p['sports'])); ?></textarea>
                        <p class="description">One per line — sports, class types, event sessions. With one entry the field is hidden; with two or more it becomes a dropdown.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="mbs_divlabel">Dropdown label</label></th>
                    <td><input name="divisionLabel" id="mbs_divlabel" type="text" class="regular-text" value="<?php echo esc_attr($p['divisionLabel']); ?>" placeholder="Team / Division"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="mbs_divisions">Teams / divisions</label></th>
                    <td>
                        <textarea name="divisions" id="mbs_divisions" rows="5" class="large-text code"><?php echo esc_textarea(mbs_array_to_lines($p['divisions'])); ?></textarea>
                        <p class="description">One per line — e.g. Varsity / JV / Freshman, 10U / 11U, or Beginner / Advanced. Leave empty to remove this dropdown from the form.</p>
                    </td>
                </tr>
            </table>

            <h2 class="title">Wording</h2>
            <p class="description" style="max-width:820px">
                This is what makes the same system work for a school, a dance studio, a club or a one-off event.
                Change the words here and the order form re-labels itself.
            </p>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="mbs_who">Who the order is for</label></th>
                    <td>
                        <input name="whoLabel" id="mbs_who" type="text" class="regular-text" value="<?php echo esc_attr($p['whoLabel']); ?>" placeholder="Athlete">
                        <p class="description">Athlete, Participant, Dancer, Guest, Player… The form asks for "&lt;this&gt; First Name".</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="mbs_buyer">Who is ordering</label></th>
                    <td>
                        <input name="buyerLabel" id="mbs_buyer" type="text" class="regular-text" value="<?php echo esc_attr($p['buyerLabel']); ?>" placeholder="Parent">
                        <p class="description">Parent, Customer, Contact…</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Jersey number</th>
                    <td>
                        <label><input type="checkbox" name="showJersey" value="1" <?php checked(!empty($p['showJersey'])); ?>> Ask for it</label>
                        <input name="jerseyLabel" type="text" value="<?php echo esc_attr($p['jerseyLabel']); ?>" placeholder="Jersey #" style="margin-left:14px;max-width:200px">
                        <p class="description">Untick for anything that isn't a team sport. The field disappears from the form.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="mbs_sportlabel">Category dropdown</label></th>
                    <td>
                        <input name="sportLabel" id="mbs_sportlabel" type="text" class="regular-text" value="<?php echo esc_attr($p['sportLabel']); ?>" placeholder="Sport">
                        <p class="description">The label above the Sports list higher up. Only shows when you list two or more.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="mbs_intro">Intro paragraph</label></th>
                    <td>
                        <textarea name="intro" id="mbs_intro" rows="3" class="large-text"><?php echo esc_textarea(mbs_inc_to_editor($p['intro'])); ?></textarea>
                        <p class="description">
                            The sentence under the big heading. Leave blank for the default:
                            "Official team &amp; individual sports photos. Pick a package, add any extras, and check out securely."
                            The deadline line, if you set one, is added after it automatically.
                        </p>
                    </td>
                </tr>
            </table>

            <h2 class="title">Which fields to ask for</h2>
            <p class="description" style="max-width:820px">
                Every box here is one less thing between a customer and a purchase. Selling prints at an art
                fair? Switch everything off except one name. The payment page still collects a name and email
                for the receipt — that part is the card processor's, not mine to remove.
            </p>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">Name fields</th>
                    <td>
                        <label style="display:block;margin-bottom:6px">
                            <input type="checkbox" name="showWho" value="1" <?php checked(!empty($p['showWho'])); ?>>
                            Ask for the <strong><?php echo esc_html($p['whoLabel']); ?></strong>'s first &amp; last name
                        </label>
                        <label style="display:block">
                            <input type="checkbox" name="showBuyer" value="1" <?php checked(!empty($p['showBuyer'])); ?>>
                            Ask for the <strong><?php echo esc_html($p['buyerLabel']); ?></strong>'s first &amp; last name
                        </label>
                        <p class="description">Keep at least one — an order has to carry a name.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="mbs_phonemode">Phone</label></th>
                    <td>
                        <select name="phoneMode" id="mbs_phonemode">
                            <option value="req" <?php selected(($p['phoneMode'] ?? 'req'), 'req'); ?>>Required</option>
                            <option value="opt" <?php selected(($p['phoneMode'] ?? 'req'), 'opt'); ?>>Optional</option>
                            <option value="off" <?php selected(($p['phoneMode'] ?? 'req'), 'off'); ?>>Don't ask</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="mbs_emailmode">Email</label></th>
                    <td>
                        <select name="emailMode" id="mbs_emailmode">
                            <option value="req" <?php selected(($p['emailMode'] ?? 'req'), 'req'); ?>>Required</option>
                            <option value="opt" <?php selected(($p['emailMode'] ?? 'req'), 'opt'); ?>>Optional</option>
                            <option value="off" <?php selected(($p['emailMode'] ?? 'req'), 'off'); ?>>Don't ask</option>
                        </select>
                        <p class="description">The receipt goes to whatever they enter at the payment page, so switching this off doesn't stop them getting one.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Notes box</th>
                    <td>
                        <label><input type="checkbox" name="showNotes" value="1" <?php checked(!empty($p['showNotes'])); ?>> Show "Notes / special requests"</label>
                    </td>
                </tr>
            </table>

            <h2 class="title">Colours</h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="mbs_colorbg">Header background</label></th>
                    <td>
                        <input type="color" id="mbs_colorbg_pick" value="<?php echo esc_attr($p['colorBg'] !== '' ? $p['colorBg'] : '#0b1f3a'); ?>" style="vertical-align:middle;width:56px;height:34px;padding:2px">
                        <input name="colorBg" id="mbs_colorbg" type="text" value="<?php echo esc_attr($p['colorBg']); ?>" placeholder="#0b1f3a" class="code" style="max-width:140px;vertical-align:middle">
                        <button type="button" class="button-link mbs-color-reset" data-for="colorbg" style="margin-left:10px">Use the default</button>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="mbs_coloraccent">Accent</label></th>
                    <td>
                        <input type="color" id="mbs_coloraccent_pick" value="<?php echo esc_attr($p['colorAccent'] !== '' ? $p['colorAccent'] : '#d81e2c'); ?>" style="vertical-align:middle;width:56px;height:34px;padding:2px">
                        <input name="colorAccent" id="mbs_coloraccent" type="text" value="<?php echo esc_attr($p['colorAccent']); ?>" placeholder="#d81e2c" class="code" style="max-width:140px;vertical-align:middle">
                        <button type="button" class="button-link mbs-color-reset" data-for="coloraccent" style="margin-left:10px">Use the default</button>
                        <p class="description">The year in the heading, the buttons and the required stars. Leave both blank for the standard navy and scarlet.</p>
                    </td>
                </tr>
            </table>

            <h2 class="title">Page extras</h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="mbs_deadline">Deadline line</label></th>
                    <td>
                        <input name="deadline" id="mbs_deadline" type="text" class="regular-text" value="<?php echo esc_attr($p['deadline']); ?>" placeholder="March 14, 2026">
                        <p class="description">Leave blank to hide the deadline sentence entirely.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Paper order form</th>
                    <td>
                        <input name="pdfUrl" id="mbs_pdf" type="text" class="large-text code" value="<?php echo esc_attr($p['pdfUrl']); ?>">
                        <p>
                            <button type="button" class="button" id="mbs_pick_pdf">Choose PDF from Media Library</button>
                            <button type="button" class="button-link" id="mbs_clear_pdf" style="color:#b32d2e;margin-left:10px">Remove</button>
                        </p>
                        <input name="pdfLabel" type="text" class="regular-text" value="<?php echo esc_attr($p['pdfLabel']); ?>" placeholder="Download the paper order form (PDF)">
                        <p class="description">Shows a download button near the top of the form, for families who want to order on paper. Leave the file blank to hide it. The second box is the button wording (optional).</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="mbs_products">Products link</label></th>
                    <td>
                        <input name="productsUrl" id="mbs_products" type="url" class="large-text code" value="<?php echo esc_attr($p['productsUrl']); ?>" placeholder="https://www.marknicholasphotography.com/products">
                        <p class="description">Shown at the top as "see photos &amp; descriptions of every product". Leave blank to hide it.</p>
                    </td>
                </tr>
            </table>

            <h2 class="title">Packages</h2>
            <p class="description">
                Drag the grip (or use the arrows) to change the order they appear in on the form.
                Untick <em>Show</em> to hide a package without deleting it. Parents can always choose "no package" and order individual items.
                In <em>What's included</em>, put <code>**stars around text**</code> to make it bold on the order form.
            </p>
            <table class="widefat striped mbs-sortable" id="mbs-pkgs">
                <thead>
                    <tr>
                        <th style="width:56px">Order</th>
                        <th style="width:60px">Show</th>
                        <th style="width:70px">Tag</th>
                        <th style="width:180px">Name</th>
                        <th style="width:100px">Price</th>
                        <th>What's included</th>
                        <th style="width:230px">Ask a question</th>
                        <th style="width:90px">Photo</th>
                        <th style="width:60px"></th>
                    </tr>
                </thead>
                <tbody>
                <?php
                $pi = 0;
                foreach ((array) $p['packages'] as $tag => $pk) {
                    mbs_render_pkg_row($pi, $tag, $pk);
                    $pi++;
                }
                ?>
                </tbody>
            </table>
            <p><button type="button" class="button" id="mbs-add-pkg">+ Add a package</button></p>

            <h2 class="title">Add-on items</h2>
            <p class="description">
                Everything a parent can buy on its own. Drag the grip (or use the arrows) to change the order.
                Items are shown on the form under their <em>Group</em> heading, so on saving they are tidied into
                group order — moving a row above the first row of another group moves that whole group up.
                Untick <em>Show</em> to take an item off sale for this school without losing it.
                <em>Buddy</em> makes the form ask for the other child's name.
            </p>
            <p class="description" style="border-left:4px solid #2271b1;background:#f0f6fc;padding:8px 12px;max-width:820px">
                <strong>Ask a question</strong> is for anything you have to make to order — an engraving, a dog tag,
                a coin, a plaque. Type the question you want the buyer to answer ("What would you like engraved?")
                and it appears under that item on the form the moment they add one. The answer travels through to the
                order, the confirmation email, and its own column in the Manufacturing Export, so it lands next to the
                item on the sheet you work from. Leave it blank and nothing changes.
            </p>
            <table class="widefat striped mbs-sortable" id="mbs-addons">
                <thead>
                    <tr>
                        <th style="width:56px">Order</th>
                        <th style="width:60px">Show</th>
                        <th style="width:170px">Group</th>
                        <th>Item name</th>
                        <th style="width:100px">Price</th>
                        <th style="width:230px">Ask a question</th>
                        <th style="width:70px">Buddy</th>
                        <th style="width:90px">Photo</th>
                        <th style="width:60px"></th>
                    </tr>
                </thead>
                <tbody>
                <?php
                $ai = 0;
                foreach ((array) $p['addons'] as $a) {
                    mbs_render_addon_row($ai, $a);
                    $ai++;
                }
                ?>
                </tbody>
            </table>
            <p><button type="button" class="button" id="mbs-add-addon">+ Add an item</button></p>

            <datalist id="mbs-groups">
                <?php foreach ($groups as $g) : ?>
                    <option value="<?php echo esc_attr($g); ?>"></option>
                <?php endforeach; ?>
            </datalist>

            <h2 class="title">The order page</h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">Page</th>
                    <td>
                        <?php if ($page_ok) : ?>
                            <p>
                                <a href="<?php echo esc_url(get_permalink($page_id)); ?>" target="_blank" rel="noopener"><?php echo esc_html(get_permalink($page_id)); ?></a>
                                &nbsp;·&nbsp; <a href="<?php echo esc_url(get_edit_post_link($page_id)); ?>">Edit page</a>
                            </p>
                            <p class="description">This is the link you send the school. It isn't in any menu, so nobody finds it by browsing.</p>
                        <?php else : ?>
                            <label>
                                <input type="checkbox" name="create_page" value="1" checked>
                                Create the order page for me when I save
                            </label>
                            <p class="description">Makes a published page with the shortcode already on it, and hands you the link. It won't be added to any menu.</p>
                        <?php endif; ?>
                    </td>
                </tr>
            </table>

            <p class="submit">
                <button type="submit" class="button button-primary button-hero">Save order form</button>
                <a href="<?php echo esc_url(mbs_admin_url()); ?>" class="button" style="margin-left:8px">Cancel</a>
            </p>
        </form>
    </div>

    <script>
    (function () {
        var pkgIdx = <?php echo (int) $pi; ?>, addIdx = <?php echo (int) $ai; ?>;

        var PKG_TPL = <?php echo wp_json_encode(mbs_pkg_row_html('__i__', '', array())); ?>;
        var ADD_TPL = <?php echo wp_json_encode(mbs_addon_row_html('__i__', array())); ?>;

        function add(tableId, tpl, idx) {
            var tb = document.querySelector('#' + tableId + ' tbody');
            var tr = document.createElement('tr');
            tr.innerHTML = tpl.replace(/__i__/g, idx).replace(/^\s*<tr[^>]*>|<\/tr>\s*$/g, '');
            tb.appendChild(tr);
        }
        document.getElementById('mbs-add-pkg').addEventListener('click', function () { add('mbs-pkgs', PKG_TPL, pkgIdx++); });
        document.getElementById('mbs-add-addon').addEventListener('click', function () { add('mbs-addons', ADD_TPL, addIdx++); });

        // Remove a row (delegated, so it works on rows added after page load).
        document.addEventListener('click', function (e) {
            var b = e.target.closest ? e.target.closest('.mbs-del-row') : null;
            if (!b) return;
            e.preventDefault();
            var tr = b.closest('tr');
            if (tr && confirm('Remove this row?')) tr.parentNode.removeChild(tr);
        });

        /* ---- reorder ----
         * Nothing stores a position number. The rows POST in DOM order and are saved in
         * that order, so moving the <tr> IS the edit — which also means a row added and
         * then moved needs no special handling, and there is no ordering field to drift
         * out of step with the list.
         *
         * The arrows are the dependable half: they are this file and nothing else. The
         * drag grip is layered on top only if jQuery UI sortable is actually there, so a
         * site where it isn't still reorders perfectly well. */
        document.addEventListener('click', function (e) {
            var up = e.target.closest ? e.target.closest('.mbs-up') : null;
            var dn = e.target.closest ? e.target.closest('.mbs-down') : null;
            if (!up && !dn) return;
            e.preventDefault();
            var tr = (up || dn).closest('tr'), tb = tr && tr.parentNode;
            if (!tb) return;
            if (up && tr.previousElementSibling) tb.insertBefore(tr, tr.previousElementSibling);
            if (dn && tr.nextElementSibling)     tb.insertBefore(tr.nextElementSibling, tr);
            flash(tr);
        });
        function flash(tr) {
            if (!tr) return;
            tr.style.transition = 'background-color .45s';
            tr.style.backgroundColor = '#fff3cd';
            setTimeout(function () { tr.style.backgroundColor = ''; }, 450);
        }
        if (window.jQuery && jQuery.fn && jQuery.fn.sortable) {
            jQuery('.mbs-sortable tbody').sortable({
                handle: '.mbs-grip',
                axis: 'y',
                helper: function (e, tr) {
                    // Without this the cells collapse to their content while dragging,
                    // because a floating <tr> has lost the table that sized it.
                    var w = tr.children().map(function () { return jQuery(this).outerWidth(); }).get();
                    var c = tr.clone();
                    c.children().each(function (i) { jQuery(this).width(w[i]); });
                    return c;
                },
                placeholder: 'mbs-drop',
                forcePlaceholderSize: true
            });
        }

        // Echo the shortcode key as it's typed.
        var k = document.getElementById('mbs_key'), ke = document.getElementById('mbs_key_echo');
        if (k && ke) k.addEventListener('input', function () { ke.textContent = k.value || 'yourkey'; });

        // Logo picker (WordPress media library).
        var frame;
        var pick = document.getElementById('mbs_pick_logo');
        if (pick) pick.addEventListener('click', function (e) {
            e.preventDefault();
            if (frame) { frame.open(); return; }
            frame = wp.media({ title: 'Choose a logo', library: { type: 'image' }, button: { text: 'Use this logo' }, multiple: false });
            frame.on('select', function () {
                var a = frame.state().get('selection').first().toJSON();
                document.getElementById('mbs_logo').value = a.url;
                var prev = document.getElementById('mbs_logo_prev');
                prev.style.display = '';
                prev.querySelector('img').src = a.url;
            });
            frame.open();
        });
        // Per-product photo pickers. Delegated, so rows added after page load work too.
        var photoFrame;
        document.addEventListener('click', function (e) {
            var pick = e.target.closest ? e.target.closest('.mbs-pick-photo') : null;
            if (pick) {
                e.preventDefault();
                var cell = pick.closest('.mbs-photo');
                photoFrame = wp.media({ title: 'Choose a product photo', library: { type: 'image' },
                                        button: { text: 'Use this photo' }, multiple: false });
                photoFrame.on('select', function () {
                    var a = photoFrame.state().get('selection').first().toJSON();
                    cell.querySelector('.mbs-photo-val').value = a.url;
                    var img = cell.querySelector('.mbs-photo-prev');
                    img.src = a.url; img.style.display = '';
                    cell.querySelector('.mbs-clear-photo').style.display = 'block';
                });
                photoFrame.open();
                return;
            }
            var clear = e.target.closest ? e.target.closest('.mbs-clear-photo') : null;
            if (clear) {
                e.preventDefault();
                var c = clear.closest('.mbs-photo');
                c.querySelector('.mbs-photo-val').value = '';
                c.querySelector('.mbs-photo-prev').style.display = 'none';
                clear.style.display = 'none';
            }
        });

        // Colour pickers stay in step with their hex boxes, both ways.
        [['mbs_colorbg', 'mbs_colorbg_pick'], ['mbs_coloraccent', 'mbs_coloraccent_pick']].forEach(function (pair) {
            var text = document.getElementById(pair[0]), swatch = document.getElementById(pair[1]);
            if (!text || !swatch) return;
            swatch.addEventListener('input', function () { text.value = swatch.value; });
            text.addEventListener('input', function () {
                if (/^#[0-9a-fA-F]{6}$/.test(text.value.trim())) swatch.value = text.value.trim();
            });
        });
        document.querySelectorAll('.mbs-color-reset').forEach(function (b) {
            b.addEventListener('click', function (e) {
                e.preventDefault();
                var t = document.getElementById('mbs_' + b.dataset.for);
                if (t) t.value = '';
            });
        });

        // PDF picker (any file type — it's a document, not an image).
        var pdfFrame;
        var pdfBtn = document.getElementById('mbs_pick_pdf');
        if (pdfBtn) pdfBtn.addEventListener('click', function (e) {
            e.preventDefault();
            if (!pdfFrame) {
                pdfFrame = wp.media({ title: 'Choose the paper order form',
                                      button: { text: 'Use this file' }, multiple: false });
                pdfFrame.on('select', function () {
                    var a = pdfFrame.state().get('selection').first().toJSON();
                    document.getElementById('mbs_pdf').value = a.url;
                });
            }
            pdfFrame.open();
        });
        var pdfClr = document.getElementById('mbs_clear_pdf');
        if (pdfClr) pdfClr.addEventListener('click', function (e) {
            e.preventDefault();
            document.getElementById('mbs_pdf').value = '';
        });

        var clr = document.getElementById('mbs_clear_logo');
        if (clr) clr.addEventListener('click', function (e) {
            e.preventDefault();
            document.getElementById('mbs_logo').value = '';
            document.getElementById('mbs_logo_prev').style.display = 'none';
        });
    })();
    </script>
    <?php
}

/* ---- row renderers (shared by PHP render + the JS "add row" template) ---- */

/**
 * Tidy the saved add-on order into GROUP order.
 *
 * The form draws items under their group heading, and a group's place is decided by where
 * its first item falls. Without this, an admin list of A, B, A would render as A, A, B and
 * the order you dragged the rows into would not be the order on the page. Sorting here —
 * stable, by the group's first appearance — keeps the admin table and the form identical,
 * and still lets you move a whole group by dragging one of its rows past another group.
 */
function mbs_sort_addons_by_group($addons) {
    $order = array();
    foreach ($addons as $a) {
        $g = (string) ($a['group'] ?? '');
        if (!array_key_exists($g, $order)) $order[$g] = count($order);
    }
    // Decorate with the original index so equal groups keep the order they were dragged into
    // (usort is not stable on every PHP version this plugin has to run on).
    $tmp = array();
    foreach ($addons as $i => $a) $tmp[] = array($order[(string) ($a['group'] ?? '')], $i, $a);
    usort($tmp, function ($x, $y) { return $x[0] === $y[0] ? ($x[1] <=> $y[1]) : ($x[0] <=> $y[0]); });
    $out = array();
    foreach ($tmp as $t) $out[] = $t[2];
    return $out;
}

/**
 * The reorder handle. Order is not stored as a number anywhere — the rows POST in DOM
 * order and are saved in that order, so moving a <tr> IS the edit. The arrows are the
 * reliable path (they need nothing but this file); the grip is a nicety on top.
 */
function mbs_move_cell() {
    return '<td class="mbs-move" style="width:56px;white-space:nowrap;text-align:center;cursor:grab">'
         // A plain character, not a dashicon: a dashicons class with no glyph inside renders as
         // an empty span, so if that stylesheet ever isn't on the screen the drag handle is
         // invisible and only the arrows are left. This always draws something.
         . '<span class="mbs-grip" title="Drag to reorder" style="color:#8c8f94;cursor:grab;font-size:15px;line-height:1;vertical-align:middle;user-select:none">&#10303;</span>'
         . '<button type="button" class="button-link mbs-up" title="Move up" style="text-decoration:none;padding:0 3px">&#9650;</button>'
         . '<button type="button" class="button-link mbs-down" title="Move down" style="text-decoration:none;padding:0 3px">&#9660;</button>'
         . '</td>';
}

/**
 * The "ask a question" cell, shared by package and add-on rows.
 *
 * This is the field that makes an engraving, a dog tag or a coin orderable at all: without
 * it there is nowhere for the buyer to say what goes ON the thing. Leave it blank and the
 * item behaves exactly as it did before.
 */
function mbs_question_cell($base, $a) {
    $q    = isset($a['q']) ? (string) $a['q'] : '';
    $qreq = !empty($a['qreq']);
    ob_start(); ?>
    <td class="mbs-q">
        <input type="text" name="<?php echo esc_attr($base); ?>[q]" value="<?php echo esc_attr($q); ?>"
               style="width:100%" maxlength="120" placeholder="e.g. What would you like engraved?">
        <label style="font-size:12px;color:#646970;display:block;margin-top:3px">
            <input type="checkbox" name="<?php echo esc_attr($base); ?>[qreq]" value="1" <?php checked($qreq); ?>>
            answer required
        </label>
    </td>
    <?php
    return ob_get_clean();
}

/** The Photo cell used by both package and add-on rows. */
function mbs_photo_cell($name, $value) {
    $url = mbs_asset_url($value);
    ob_start(); ?>
    <td class="mbs-photo">
        <img class="mbs-photo-prev" src="<?php echo esc_url($url); ?>" alt=""
             style="width:40px;height:40px;object-fit:cover;border-radius:5px;<?php echo $url ? '' : 'display:none'; ?>"
             onerror="this.style.display='none'">
        <input type="hidden" class="mbs-photo-val" name="<?php echo esc_attr($name); ?>" value="<?php echo esc_attr($value); ?>">
        <button type="button" class="button-link mbs-pick-photo" style="display:block;margin-top:2px">Choose</button>
        <button type="button" class="button-link mbs-clear-photo" style="display:<?php echo $value !== '' ? 'block' : 'none'; ?>;color:#b32d2e">Remove</button>
    </td>
    <?php
    return ob_get_clean();
}

function mbs_pkg_row_html($i, $tag, $pk) {
    $pk = array_merge(array('name' => '', 'price' => '', 'inc' => '', 'img' => '', 'q' => '', 'qreq' => 0, 'off' => 0), (array) $pk);
    ob_start(); ?>
    <tr>
        <?php echo mbs_move_cell(); // phpcs:ignore — fixed markup ?>
        <td><input type="checkbox" name="packages[<?php echo esc_attr($i); ?>][on]" value="1" <?php checked(empty($pk['off'])); ?>></td>
        <td><input type="text" name="packages[<?php echo esc_attr($i); ?>][tag]" value="<?php echo esc_attr($tag); ?>" style="width:100%" maxlength="6" placeholder="A"></td>
        <td><input type="text" name="packages[<?php echo esc_attr($i); ?>][name]" value="<?php echo esc_attr($pk['name']); ?>" style="width:100%" placeholder="Package A"></td>
        <td><input type="number" step="0.01" min="0" name="packages[<?php echo esc_attr($i); ?>][price]" value="<?php echo esc_attr($pk['price']); ?>" style="width:100%"></td>
        <td>
            <input type="text" name="packages[<?php echo esc_attr($i); ?>][inc]" value="<?php echo esc_attr(mbs_inc_to_editor($pk['inc'])); ?>" style="width:100%" placeholder="2 × 5×7 prints · 8 wallets">
        </td>
        <?php echo mbs_question_cell('packages[' . $i . ']', $pk); ?>
        <?php echo mbs_photo_cell('packages[' . $i . '][img]', $pk['img']); ?>
        <td><button type="button" class="button-link mbs-del-row" style="color:#b32d2e">Remove</button></td>
    </tr>
    <?php
    return ob_get_clean();
}
function mbs_render_pkg_row($i, $tag, $pk) {
    echo mbs_pkg_row_html($i, $tag, $pk); // phpcs:ignore — built above with esc_* on every value
}

function mbs_addon_row_html($i, $a) {
    $a = array_merge(array('group' => '', 'id' => '', 't' => '', 'p' => '', 'img' => '', 'q' => '', 'qreq' => 0, 'buddy' => 0, 'off' => 0), (array) $a);
    ob_start(); ?>
    <tr>
        <?php echo mbs_move_cell(); // phpcs:ignore — fixed markup ?>
        <td><input type="checkbox" name="addons[<?php echo esc_attr($i); ?>][on]" value="1" <?php checked(empty($a['off'])); ?>></td>
        <td><input type="text" name="addons[<?php echo esc_attr($i); ?>][group]" value="<?php echo esc_attr($a['group']); ?>" style="width:100%" list="mbs-groups" placeholder="Prints &amp; Wallets"></td>
        <td>
            <input type="text" name="addons[<?php echo esc_attr($i); ?>][t]" value="<?php echo esc_attr($a['t']); ?>" style="width:100%" placeholder="(1) 8&times;10 Individual Print">
            <input type="hidden" name="addons[<?php echo esc_attr($i); ?>][id]" value="<?php echo esc_attr($a['id']); ?>">
        </td>
        <td><input type="number" step="0.01" min="0" name="addons[<?php echo esc_attr($i); ?>][p]" value="<?php echo esc_attr($a['p']); ?>" style="width:100%"></td>
        <?php echo mbs_question_cell('addons[' . $i . ']', $a); ?>
        <td style="text-align:center"><input type="checkbox" name="addons[<?php echo esc_attr($i); ?>][buddy]" value="1" <?php checked(!empty($a['buddy'])); ?>></td>
        <?php echo mbs_photo_cell('addons[' . $i . '][img]', $a['img']); ?>
        <td><button type="button" class="button-link mbs-del-row" style="color:#b32d2e">Remove</button></td>
    </tr>
    <?php
    return ob_get_clean();
}
function mbs_render_addon_row($i, $a) {
    echo mbs_addon_row_html($i, $a); // phpcs:ignore — built above with esc_* on every value
}

/* -------------------------------------------------------------------------
 *  Save
 * ---------------------------------------------------------------------- */
add_action('admin_post_mbs_save_program', 'mbs_handle_save_program');
function mbs_handle_save_program() {
    if (!current_user_can('manage_woocommerce')) wp_die('Not allowed.');
    check_admin_referer('mbs_save_program');

    $programs = mbs_get_programs();
    $orig_key = isset($_POST['orig_key']) ? sanitize_key(wp_unslash($_POST['orig_key'])) : '';
    $key      = isset($_POST['key']) ? sanitize_key(wp_unslash($_POST['key'])) : '';
    $name     = sanitize_text_field(wp_unslash($_POST['name'] ?? ''));

    if ($key === '')  mbs_save_redirect($orig_key, 'Please give the order form a shortcode key.');
    if ($name === '') mbs_save_redirect($orig_key, 'Please give the order form a name.');
    if ($key !== $orig_key && isset($programs[$key])) {
        mbs_save_redirect($orig_key, 'The key "' . $key . '" is already used by another order form. Pick a different one.');
    }

    // Start from whatever we already had, so nothing we don't show on this
    // screen (page_id, and anything added in future) gets silently dropped.
    $existing = ($orig_key !== '' && isset($programs[$orig_key])) ? $programs[$orig_key] : array();
    $prog = array_merge(mbs_program_defaults(), $existing);

    $prog['name']          = $name;
    $prog['line1']         = sanitize_text_field(wp_unslash($_POST['line1'] ?? ''));
    $prog['line2']         = sanitize_text_field(wp_unslash($_POST['line2'] ?? ''));
    $prog['year']          = sanitize_text_field(wp_unslash($_POST['year'] ?? ''));
    $prog['mascot']        = sanitize_text_field(wp_unslash($_POST['mascot'] ?? ''));
    $prog['crest']         = sanitize_text_field(wp_unslash($_POST['crest'] ?? ''));
    $prog['crestMascot']   = sanitize_text_field(wp_unslash($_POST['crestMascot'] ?? ''));
    $prog['divisionLabel'] = sanitize_text_field(wp_unslash($_POST['divisionLabel'] ?? ''));
    $prog['deadline']      = sanitize_text_field(wp_unslash($_POST['deadline'] ?? ''));
    $prog['productsUrl']   = esc_url_raw(wp_unslash($_POST['productsUrl'] ?? ''));
    $prog['sports']        = mbs_lines_to_array(wp_unslash($_POST['sports'] ?? ''));
    $prog['whoLabel']      = sanitize_text_field(wp_unslash($_POST['whoLabel'] ?? '')) ?: 'Athlete';
    $prog['buyerLabel']    = sanitize_text_field(wp_unslash($_POST['buyerLabel'] ?? '')) ?: 'Parent';
    $prog['jerseyLabel']   = sanitize_text_field(wp_unslash($_POST['jerseyLabel'] ?? '')) ?: 'Jersey #';
    $prog['sportLabel']    = sanitize_text_field(wp_unslash($_POST['sportLabel'] ?? '')) ?: 'Sport';
    $prog['showJersey']    = empty($_POST['showJersey']) ? 0 : 1;
    $prog['intro']         = mbs_inc_from_editor(wp_unslash($_POST['intro'] ?? ''));
    $prog['showWho']       = empty($_POST['showWho'])   ? 0 : 1;
    $prog['showBuyer']     = empty($_POST['showBuyer']) ? 0 : 1;
    $prog['showNotes']     = empty($_POST['showNotes']) ? 0 : 1;
    $prog['showHow']       = empty($_POST['showHow']) ? 0 : 1;
    $prog['logoSpot']      = (isset($_POST['logoSpot']) && $_POST['logoSpot'] === 'right') ? 'right' : 'left';
    // An order must carry a name, so refuse to switch both name blocks off.
    if (!$prog['showWho'] && !$prog['showBuyer']) $prog['showWho'] = 1;
    $mode = function ($v) { return in_array($v, array('req', 'opt', 'off'), true) ? $v : 'req'; };
    $prog['phoneMode']     = $mode(sanitize_text_field(wp_unslash($_POST['phoneMode'] ?? 'req')));
    $prog['emailMode']     = $mode(sanitize_text_field(wp_unslash($_POST['emailMode'] ?? 'req')));
    $prog['colorBg']       = mbs_hex(wp_unslash($_POST['colorBg'] ?? ''));
    $prog['colorAccent']   = mbs_hex(wp_unslash($_POST['colorAccent'] ?? ''));
    $prog['pdfUrl']        = esc_url_raw(wp_unslash($_POST['pdfUrl'] ?? ''));
    $prog['pdfLabel']      = sanitize_text_field(wp_unslash($_POST['pdfLabel'] ?? ''));
    $prog['divisions']     = mbs_lines_to_array(wp_unslash($_POST['divisions'] ?? ''));

    // Logo: a media-library URL, or a filename shipped in /assets.
    $logo = trim((string) wp_unslash($_POST['logo'] ?? ''));
    $prog['logo'] = (preg_match('#^(https?:)?//#', $logo) || (isset($logo[0]) && $logo[0] === '/'))
        ? esc_url_raw($logo)
        : sanitize_file_name($logo);

    /* ---- packages (assoc array keyed by tag) ---- */
    $packages = array();
    $rows = isset($_POST['packages']) && is_array($_POST['packages']) ? $_POST['packages'] : array();
    foreach ($rows as $row) {
        $pname = sanitize_text_field(wp_unslash($row['name'] ?? ''));
        $tag   = mbs_clean_tag(wp_unslash($row['tag'] ?? ''));
        if ($pname === '' && $tag === '') continue;          // blank row, skip
        if ($tag === '') $tag = mbs_clean_tag(substr($pname, 0, 3));
        if ($tag === '') $tag = 'P';
        while (isset($packages[$tag])) $tag .= 'X';           // keep tags unique
        $q = sanitize_text_field(wp_unslash($row['q'] ?? ''));
        $packages[$tag] = array(
            'name'  => $pname !== '' ? $pname : ('Package ' . $tag),
            'tag'   => $tag,
            'price' => round((float) ($row['price'] ?? 0), 2),
            'inc'   => mbs_inc_from_editor(wp_unslash($row['inc'] ?? '')),
            'img'   => sanitize_text_field(wp_unslash($row['img'] ?? '')),
            'q'     => $q,
            // "Required" with no question is a trap: nothing is ever asked, so nothing can
            // be answered, and the form would refuse every order for a reason it never showed.
            'qreq'  => ($q !== '' && !empty($row['qreq'])) ? 1 : 0,
            'off'   => empty($row['on']) ? 1 : 0,
        );
    }
    $prog['packages'] = $packages;

    /* ---- add-ons (ordered list) ---- */
    $addons = array();
    $taken  = array();
    $rows = isset($_POST['addons']) && is_array($_POST['addons']) ? $_POST['addons'] : array();
    foreach ($rows as $row) {
        $t = sanitize_text_field(wp_unslash($row['t'] ?? ''));
        if ($t === '') continue;                              // blank row, skip
        // Keep the existing id wherever there is one: it's what past orders and
        // in-progress carts reference. Only generate one for a brand-new item.
        $id = sanitize_key(wp_unslash($row['id'] ?? ''));
        if ($id === '' || in_array($id, $taken, true)) $id = mbs_make_id($t, $taken);
        $taken[] = $id;
        $q = sanitize_text_field(wp_unslash($row['q'] ?? ''));
        $addons[] = array(
            'group' => sanitize_text_field(wp_unslash($row['group'] ?? '')),
            'id'    => $id,
            't'     => $t,
            'p'     => round((float) ($row['p'] ?? 0), 2),
            'img'   => sanitize_text_field(wp_unslash($row['img'] ?? '')),
            'q'     => $q,
            'qreq'  => ($q !== '' && !empty($row['qreq'])) ? 1 : 0,
            'buddy' => empty($row['buddy']) ? 0 : 1,
            'off'   => empty($row['on']) ? 1 : 0,
        );
    }
    $prog['addons'] = mbs_sort_addons_by_group($addons);

    /* ---- the order page ---- */
    // Look for a page that already carries this shortcode before offering to make one.
    $page_id = $orig_key !== '' ? mbs_program_page_id($orig_key, $prog) : 0;
    if (!$page_id) $page_id = mbs_find_page_for_key($key);
    if ($page_id) $prog['page_id'] = $page_id;
    $page_ok = $page_id > 0;
    if (!$page_ok && !empty($_POST['create_page'])) {
        $new_id = wp_insert_post(array(
            'post_title'     => $name,
            'post_content'   => '[mbs_order_form program="' . $key . '"]',
            'post_status'    => 'publish',
            'post_type'      => 'page',
            'comment_status' => 'closed',
            'ping_status'    => 'closed',
        ));
        if ($new_id && !is_wp_error($new_id)) $prog['page_id'] = (int) $new_id;
    } elseif ($page_ok && $key !== $orig_key) {
        // The key changed, so the shortcode on the existing page now points at a
        // program that no longer exists. Repoint it rather than leaving a broken page.
        $page = get_post($page_id);
        if ($page) {
            $updated = preg_replace(
                '/\[mbs_order_form\s+program=("|\')' . preg_quote($orig_key, '/') . '\\1\s*\]/',
                '[mbs_order_form program="' . $key . '"]',
                $page->post_content
            );
            if ($updated !== null && $updated !== $page->post_content) {
                wp_update_post(array('ID' => $page_id, 'post_content' => $updated));
            }
        }
    }

    // Renaming the key moves the entry rather than leaving a stale copy behind.
    if ($orig_key !== '' && $orig_key !== $key) unset($programs[$orig_key]);
    $programs[$key] = $prog;
    mbs_save_programs($programs);

    wp_safe_redirect(mbs_admin_url(array('action' => 'edit', 'key' => $key, 'saved' => 1)));
    exit;
}

function mbs_save_redirect($key, $err) {
    wp_safe_redirect(mbs_admin_url(array('action' => 'edit', 'key' => $key, 'err' => rawurlencode($err))));
    exit;
}

/* -------------------------------------------------------------------------
 *  Duplicate
 * ---------------------------------------------------------------------- */
add_action('admin_post_mbs_duplicate_program', function () {
    if (!current_user_can('manage_woocommerce')) wp_die('Not allowed.');
    $key = isset($_GET['key']) ? sanitize_key(wp_unslash($_GET['key'])) : '';
    check_admin_referer('mbs_dup_' . $key);

    $programs = mbs_get_programs();
    if (!isset($programs[$key])) wp_die('That order form no longer exists.');

    $copy = $programs[$key];
    $copy['page_id'] = 0;                       // the copy needs its own page
    $copy['name']    = trim(($copy['name'] ?? $key) . ' (copy)');

    $new = $key . '-copy';
    $n = 2;
    while (isset($programs[$new])) { $new = $key . '-copy' . $n; $n++; }

    $programs[$new] = $copy;
    mbs_save_programs($programs);

    wp_safe_redirect(mbs_admin_url(array('action' => 'edit', 'key' => $new)));
    exit;
});

/* -------------------------------------------------------------------------
 *  Delete — removes the school only. The WordPress page is deliberately left
 *  alone: deleting a live page on the client's site is not ours to do silently.
 * ---------------------------------------------------------------------- */
add_action('admin_post_mbs_delete_program', function () {
    if (!current_user_can('manage_woocommerce')) wp_die('Not allowed.');
    $key = isset($_GET['key']) ? sanitize_key(wp_unslash($_GET['key'])) : '';
    check_admin_referer('mbs_del_' . $key);

    $programs = mbs_get_programs();
    if (isset($programs[$key])) {
        unset($programs[$key]);
        mbs_save_programs($programs);
    }
    wp_safe_redirect(mbs_admin_url(array('deleted' => 1)));
    exit;
});
