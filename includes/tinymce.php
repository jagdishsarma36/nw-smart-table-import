<?php

if (!defined('ABSPATH')) {
    exit;
}

function sti_add_tinymce_button($buttons) {

    $buttons[] = 'smart_table_import';

    return $buttons;
}

add_filter('mce_buttons', 'sti_add_tinymce_button');

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