<?php

if (!defined('ABSPATH')) {
    exit;
}
/*
function sti_add_tinymce_button($buttons) {

    $buttons[] = 'smart_table_import';

    return $buttons;
}

add_filter('mce_buttons', 'sti_add_tinymce_button');*/

function sti_add_tinymce_button($buttons) {

    // remove existing button first
    $buttons = array_values(
        array_diff($buttons, array('smart_table_import'))
    );

    $new_buttons = array();

    foreach ($buttons as $button) {

        // place Import Table before default table button
        if ($button === 'table') {
            $new_buttons[] = 'smart_table_import';
        }

        $new_buttons[] = $button;
    }

    // fallback if table button not found
    if (!in_array('smart_table_import', $new_buttons)) {
        $new_buttons[] = 'smart_table_import';
    }

    return $new_buttons;
}

add_filter('mce_buttons_2', 'sti_add_tinymce_button');

function sti_add_tinymce_plugin($plugins) {

    $plugins['smart_table_import'] =
        STI_PLUGIN_URL . 'assets/js/editor.js';

    return $plugins;
}

add_filter('mce_external_plugins', 'sti_add_tinymce_plugin');

function sti_tinymce_settings($settings) {

    $settings['verify_html'] = false;

    $settings['extended_valid_elements'] =
        'table[*],thead[*],tbody[*],tfoot[*],tr[*],td[*],th[*],colgroup[*],col[*]';

    return $settings;
}

add_filter('tiny_mce_before_init', 'sti_tinymce_settings');