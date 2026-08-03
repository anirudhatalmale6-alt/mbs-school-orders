<?php
/**
 * Program configs — this is the ONLY thing you edit to add a new school/team.
 * Copy a block, change the name/logo/sports/divisions/prices, give it a new key,
 * and drop [mbs_order_form program="yourkey"] on a new private page.
 */
if (!defined('ABSPATH')) exit;

function mbs_programs() {
    return array(

        // ============================================================
        'redondo' => array(
            'name'          => 'Redondo Union Sports 2026',
            'line1'         => 'Redondo Union',
            'line2'         => 'Sports',
            'year'          => '2026',
            'mascot'        => 'Sea Hawks Athletics',
            'crest'         => 'RU',            // used only if no logo set
            'crestMascot'   => 'SEA HAWKS',
            'logo'          => 'redondo-white.png',  // file in /assets ; leave '' for the initials crest
            'sports'        => array('Football'),    // 1 sport = field hidden; 2+ shows a Sport dropdown
            'divisionLabel' => 'Team / Division',
            'divisions'     => array('Varsity', 'JV', 'Freshman'),
            'deadline'      => 'March 14, 2026',
            'packages' => array(
                'A' => array('name' => 'Package A', 'tag' => 'A', 'price' => 35, 'img' => 'samples/memorymate.jpg', 'inc' => '2 &times; 5&times;7 prints &middot; 8 wallets &middot; 8&times;10 Memory Mate'),
                'B' => array('name' => 'Package B', 'tag' => 'B', 'price' => 45, 'img' => 'samples/memorymate.jpg', 'inc' => '4 &times; 5&times;7 prints &middot; 1 &times; 8&times;10 print &middot; 8 wallets &middot; 8&times;10 Memory Mate'),
                'C' => array('name' => 'Package C', 'tag' => 'C', 'price' => 55, 'img' => 'samples/memorymate.jpg', 'inc' => '<b>Digital File included</b> &middot; 4 &times; 5&times;7 prints &middot; 2 &times; 8&times;10 prints &middot; 8 wallets &middot; 8&times;10 Memory Mate'),
            ),
            // 'img' = optional sample photo shown in the "See sample" popup (file in /assets).
            // Leave 'img' off an item and it falls back to a neutral placeholder.
            'addons' => array(
                array('group' => 'Prints & Wallets', 'id' => 'i5x7',      't' => '(2) 5×7 Individual Prints', 'p' => 14, 'img' => 'samples/prints.jpg'),
                array('group' => 'Prints & Wallets', 'id' => 'i810',      't' => '(1) 8×10 Individual Print', 'p' => 14, 'img' => 'samples/prints.jpg'),
                array('group' => 'Prints & Wallets', 'id' => 'team810',   't' => '(1) 8×10 Team Photo', 'p' => 14, 'img' => 'samples/memorymate.jpg'),
                array('group' => 'Prints & Wallets', 'id' => 'jwallets',  't' => '(4) Jumbo Wallets', 'p' => 12, 'img' => 'samples/prints.jpg'),
                array('group' => 'Prints & Wallets', 'id' => 'swallets',  't' => '(8) Small Wallets', 'p' => 12, 'img' => 'samples/prints.jpg'),
                array('group' => 'Prints & Wallets', 'id' => 'buddy',     't' => 'Buddy Photo — (2) 5×7 Images', 'p' => 14, 'buddy' => true, 'img' => 'samples/prints.jpg'),
                array('group' => 'Keepsakes & Gifts', 'id' => 'mug',      't' => 'Photo Mug', 'p' => 25, 'img' => 'samples/mug.jpg'),
                array('group' => 'Keepsakes & Gifts', 'id' => 'tmug',     't' => 'Travel Mug', 'p' => 30, 'img' => 'samples/travelmug.jpg'),
                array('group' => 'Keepsakes & Gifts', 'id' => 'magnet',   't' => 'Photo Magnet', 'p' => 10, 'img' => 'samples/magnet.jpg'),
                array('group' => 'Keepsakes & Gifts', 'id' => 'keychain', 't' => 'Key Chain', 'p' => 10, 'img' => 'samples/bagtag.jpg'),
                array('group' => 'Keepsakes & Gifts', 'id' => 'buttons',  't' => 'Buttons', 'p' => 9, 'img' => 'samples/button.jpg'),
                array('group' => 'Keepsakes & Gifts', 'id' => 'dogtags',  't' => 'Custom Laser Dog Tags', 'p' => 18, 'img' => 'samples/dogtags.jpg'),
                array('group' => 'Keepsakes & Gifts', 'id' => 'earrings', 't' => 'Player ID Helmet Earrings', 'p' => 18, 'img' => 'samples/earrings.jpg'),
                array('group' => 'Keepsakes & Gifts', 'id' => 'coin',     't' => 'Custom Bronze Player Coin', 'p' => 35),
                array('group' => 'Speciality Items', 'id' => 'canvas',    't' => 'Canvas Print 16×20 (custom size)', 'p' => 55),
                array('group' => 'Speciality Items', 'id' => 'engrave',   't' => 'Laser Wood Engraving 8×10', 'p' => 45, 'img' => 'samples/engrave.jpg'),
                array('group' => 'Speciality Items', 'id' => 'statuette', 't' => 'Statuette w/ Stand', 'p' => 48, 'img' => 'samples/statuette.jpg'),
                array('group' => 'Speciality Items', 'id' => 'standup',   't' => 'Stand Up', 'p' => 35, 'img' => 'samples/standup.jpg'),
                array('group' => 'Speciality Items', 'id' => 'plaque',    't' => '8×10 Plaque (Individual + Team)', 'p' => 35, 'img' => 'samples/plaque.jpg'),
            ),
        ),

        // ============================================================
        // Example of a second program (youth org). Uncomment + adjust,
        // then put [mbs_order_form program="sharks"] on its own page.
        // 'sharks' => array(
        //     'name' => 'South Bay Sharks 2026', 'line1' => 'South Bay', 'line2' => 'Sharks', 'year' => '2026',
        //     'mascot' => 'Sharks Athletics', 'crest' => 'SB', 'crestMascot' => 'SHARKS', 'logo' => '',
        //     'sports' => array('Football', 'Cheer'),
        //     'divisionLabel' => 'Team / Division',
        //     'divisions' => array('10U', '11U', '12U Black', '12U Blue', '13U'),
        //     'deadline' => '', 'packages' => array(...different prices...), 'addons' => array(...),
        // ),

    );
}
