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

/** Blank program used by "Add New School". */
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
        'sports'        => array('Football'),
        'divisionLabel' => 'Team / Division',
        'divisions'     => array('Varsity', 'JV', 'Freshman'),
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
        'School Order Forms',
        'School Order Forms',
        'manage_woocommerce',
        'mbs-school-forms',
        'mbs_admin_router'
    );
}, 20);

function mbs_admin_router() {
    if (!current_user_can('manage_woocommerce')) {
        wp_die('You do not have permission to manage school order forms.');
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
        <h1 class="wp-heading-inline">School Order Forms</h1>
        <a href="<?php echo esc_url(mbs_admin_url(array('action' => 'edit'))); ?>" class="page-title-action">Add New School</a>
        <hr class="wp-header-end">

        <?php if (!empty($_GET['saved'])) : ?>
            <div class="notice notice-success is-dismissible"><p>Saved.</p></div>
        <?php endif; ?>
        <?php if (!empty($_GET['deleted'])) : ?>
            <div class="notice notice-success is-dismissible"><p>School removed. Its order page was left on the site — delete it from Pages if you don't want it.</p></div>
        <?php endif; ?>

        <p class="description" style="max-width:820px">
            Each school here is one private order page. The quickest way to set up a new one is
            <strong>Duplicate</strong> an existing school and change what's different — your product list
            usually stays the same.
        </p>

        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th style="width:26%">School</th>
                    <th style="width:12%">Shortcode key</th>
                    <th style="width:10%">Packages</th>
                    <th style="width:10%">Add-ons</th>
                    <th style="width:22%">Order page</th>
                    <th style="width:20%">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($programs)) : ?>
                <tr><td colspan="6">No schools yet. <a href="<?php echo esc_url(mbs_admin_url(array('action' => 'edit'))); ?>">Add your first one.</a></td></tr>
            <?php endif; ?>
            <?php foreach ($programs as $key => $p) :
                $edit_url = mbs_admin_url(array('action' => 'edit', 'key' => $key));
                $page_id  = isset($p['page_id']) ? (int) $p['page_id'] : 0;
                $page_ok  = $page_id && get_post_status($page_id) === 'publish';
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
                           onclick="return confirm('Remove <?php echo esc_js($p['name'] !== '' ? $p['name'] : $key); ?>? The order page itself is left on the site.');">Delete</a>
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

    $page_id = (int) ($p['page_id'] ?? 0);
    $page_ok = $page_id && get_post_status($page_id) === 'publish';

    // Existing add-on groups, offered as a datalist so groups stay consistent.
    $groups = array();
    foreach ($programs as $prog) {
        foreach ((array) ($prog['addons'] ?? array()) as $a) {
            if (!empty($a['group']) && !in_array($a['group'], $groups, true)) $groups[] = $a['group'];
        }
    }

    wp_enqueue_media();
    ?>
    <div class="wrap">
        <h1><?php echo $is_new ? 'Add New School' : 'Edit ' . esc_html($p['name'] !== '' ? $p['name'] : $key); ?></h1>

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

            <h2 class="title">The school</h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="mbs_name">School / program name</label></th>
                    <td>
                        <input name="name" id="mbs_name" type="text" class="regular-text" required
                               value="<?php echo esc_attr($p['name']); ?>" placeholder="Redondo Union Sports 2026">
                        <p class="description">Shown on order confirmations and in your exports.</p>
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
                                <strong>Careful:</strong> changing this on a school that already has a live page will break that page
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
                    <th scope="row">Initials fallback</th>
                    <td>
                        <input name="crest" type="text" value="<?php echo esc_attr($p['crest']); ?>" placeholder="RU" style="max-width:90px">
                        <input name="crestMascot" type="text" value="<?php echo esc_attr($p['crestMascot']); ?>" placeholder="SEA HAWKS" style="max-width:200px">
                        <p class="description">Only used when no logo is set.</p>
                    </td>
                </tr>
            </table>

            <h2 class="title">Teams &amp; sports</h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="mbs_sports">Sports</label></th>
                    <td>
                        <textarea name="sports" id="mbs_sports" rows="3" class="large-text code"><?php echo esc_textarea(mbs_array_to_lines($p['sports'])); ?></textarea>
                        <p class="description">One per line. With a single sport the field is hidden on the form; with two or more, parents get a dropdown.</p>
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
                        <p class="description">One per line — e.g. Varsity / JV / Freshman, or 10U / 11U / 12U Black.</p>
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
                    <th scope="row"><label for="mbs_products">Products link</label></th>
                    <td>
                        <input name="productsUrl" id="mbs_products" type="url" class="large-text code" value="<?php echo esc_attr($p['productsUrl']); ?>" placeholder="https://www.marknicholasphotography.com/products">
                        <p class="description">Shown at the top as "see photos &amp; descriptions of every product". Leave blank to hide it.</p>
                    </td>
                </tr>
            </table>

            <h2 class="title">Packages</h2>
            <p class="description">
                Untick <em>Show</em> to hide a package without deleting it. Parents can always choose "no package" and order individual items.
                In <em>What's included</em>, put <code>**stars around text**</code> to make it bold on the order form.
            </p>
            <table class="widefat striped" id="mbs-pkgs">
                <thead>
                    <tr>
                        <th style="width:60px">Show</th>
                        <th style="width:70px">Tag</th>
                        <th style="width:180px">Name</th>
                        <th style="width:100px">Price</th>
                        <th>What's included</th>
                        <th style="width:70px">Photo</th>
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
                Everything a parent can buy on its own. Untick <em>Show</em> to take an item off sale for this school
                without losing it. <em>Buddy</em> makes the form ask for the other child's name.
            </p>
            <table class="widefat striped" id="mbs-addons">
                <thead>
                    <tr>
                        <th style="width:60px">Show</th>
                        <th style="width:170px">Group</th>
                        <th>Item name</th>
                        <th style="width:100px">Price</th>
                        <th style="width:70px">Buddy</th>
                        <th style="width:70px">Photo</th>
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
                <button type="submit" class="button button-primary button-hero">Save school</button>
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

function mbs_pkg_row_html($i, $tag, $pk) {
    $pk = array_merge(array('name' => '', 'price' => '', 'inc' => '', 'img' => '', 'off' => 0), (array) $pk);
    $img_url = mbs_asset_url($pk['img']);
    ob_start(); ?>
    <tr>
        <td><input type="checkbox" name="packages[<?php echo esc_attr($i); ?>][on]" value="1" <?php checked(empty($pk['off'])); ?>></td>
        <td><input type="text" name="packages[<?php echo esc_attr($i); ?>][tag]" value="<?php echo esc_attr($tag); ?>" style="width:100%" maxlength="6" placeholder="A"></td>
        <td><input type="text" name="packages[<?php echo esc_attr($i); ?>][name]" value="<?php echo esc_attr($pk['name']); ?>" style="width:100%" placeholder="Package A"></td>
        <td><input type="number" step="0.01" min="0" name="packages[<?php echo esc_attr($i); ?>][price]" value="<?php echo esc_attr($pk['price']); ?>" style="width:100%"></td>
        <td>
            <input type="text" name="packages[<?php echo esc_attr($i); ?>][inc]" value="<?php echo esc_attr(mbs_inc_to_editor($pk['inc'])); ?>" style="width:100%" placeholder="2 × 5×7 prints · 8 wallets">
            <input type="hidden" name="packages[<?php echo esc_attr($i); ?>][img]" value="<?php echo esc_attr($pk['img']); ?>">
        </td>
        <td style="text-align:center">
            <?php if ($img_url) : ?>
                <img src="<?php echo esc_url($img_url); ?>" alt="" style="width:40px;height:40px;object-fit:cover;border-radius:5px" onerror="this.style.display='none'">
            <?php else : ?>
                <span style="color:#8c8f94">—</span>
            <?php endif; ?>
        </td>
        <td><button type="button" class="button-link mbs-del-row" style="color:#b32d2e">Remove</button></td>
    </tr>
    <?php
    return ob_get_clean();
}
function mbs_render_pkg_row($i, $tag, $pk) {
    echo mbs_pkg_row_html($i, $tag, $pk); // phpcs:ignore — built above with esc_* on every value
}

function mbs_addon_row_html($i, $a) {
    $a = array_merge(array('group' => '', 'id' => '', 't' => '', 'p' => '', 'img' => '', 'buddy' => 0, 'off' => 0), (array) $a);
    $img_url = mbs_asset_url($a['img']);
    ob_start(); ?>
    <tr>
        <td><input type="checkbox" name="addons[<?php echo esc_attr($i); ?>][on]" value="1" <?php checked(empty($a['off'])); ?>></td>
        <td><input type="text" name="addons[<?php echo esc_attr($i); ?>][group]" value="<?php echo esc_attr($a['group']); ?>" style="width:100%" list="mbs-groups" placeholder="Prints &amp; Wallets"></td>
        <td>
            <input type="text" name="addons[<?php echo esc_attr($i); ?>][t]" value="<?php echo esc_attr($a['t']); ?>" style="width:100%" placeholder="(1) 8&times;10 Individual Print">
            <input type="hidden" name="addons[<?php echo esc_attr($i); ?>][id]" value="<?php echo esc_attr($a['id']); ?>">
            <input type="hidden" name="addons[<?php echo esc_attr($i); ?>][img]" value="<?php echo esc_attr($a['img']); ?>">
        </td>
        <td><input type="number" step="0.01" min="0" name="addons[<?php echo esc_attr($i); ?>][p]" value="<?php echo esc_attr($a['p']); ?>" style="width:100%"></td>
        <td style="text-align:center"><input type="checkbox" name="addons[<?php echo esc_attr($i); ?>][buddy]" value="1" <?php checked(!empty($a['buddy'])); ?>></td>
        <td style="text-align:center">
            <?php if ($img_url) : ?>
                <img src="<?php echo esc_url($img_url); ?>" alt="" style="width:40px;height:40px;object-fit:cover;border-radius:5px" onerror="this.style.display='none'">
            <?php else : ?>
                <span style="color:#8c8f94">—</span>
            <?php endif; ?>
        </td>
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

    if ($key === '')  mbs_save_redirect($orig_key, 'Please give the school a shortcode key.');
    if ($name === '') mbs_save_redirect($orig_key, 'Please give the school a name.');
    if ($key !== $orig_key && isset($programs[$key])) {
        mbs_save_redirect($orig_key, 'The key "' . $key . '" is already used by another school. Pick a different one.');
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
        $packages[$tag] = array(
            'name'  => $pname !== '' ? $pname : ('Package ' . $tag),
            'tag'   => $tag,
            'price' => round((float) ($row['price'] ?? 0), 2),
            'inc'   => mbs_inc_from_editor(wp_unslash($row['inc'] ?? '')),
            'img'   => sanitize_text_field(wp_unslash($row['img'] ?? '')),
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
        $addons[] = array(
            'group' => sanitize_text_field(wp_unslash($row['group'] ?? '')),
            'id'    => $id,
            't'     => $t,
            'p'     => round((float) ($row['p'] ?? 0), 2),
            'img'   => sanitize_text_field(wp_unslash($row['img'] ?? '')),
            'buddy' => empty($row['buddy']) ? 0 : 1,
            'off'   => empty($row['on']) ? 1 : 0,
        );
    }
    $prog['addons'] = $addons;

    /* ---- the order page ---- */
    $page_id = (int) ($prog['page_id'] ?? 0);
    $page_ok = $page_id && get_post_status($page_id) === 'publish';
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
    if (!isset($programs[$key])) wp_die('That school no longer exists.');

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
