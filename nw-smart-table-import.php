<?php
/**
 * Plugin Name: NW Table Importer
 * Plugin URI: https://jagdish.info
 * Description: Import Excel, CSV, TSV, and HTML tables into Classic Editor.
 * Version: 1.0.0
 * Author: Jagdish Sarma
 * License: GPL2
 */

if (!defined('ABSPATH')) {
    exit;
}

define('STI_PLUGIN_URL', plugin_dir_url(__FILE__));
define('STI_PLUGIN_PATH', plugin_dir_path(__FILE__));

require_once STI_PLUGIN_PATH . 'includes/tinymce.php';

function sti_enqueue_admin_assets($hook) {

    if (
        $hook !== 'post.php' &&
        $hook !== 'post-new.php'
    ) {
        return;
    }

    wp_enqueue_style(
        'sti-editor-css',
        STI_PLUGIN_URL . 'assets/css/editor.css',
        [],
        '1.0.0'
    );

    wp_enqueue_script(
        'sti-papaparse',
        STI_PLUGIN_URL . 'assets/js/papaparse.min.js',
        [],
        '5.4.1',
        true
    );

    wp_enqueue_script(
        'sti-parsers',
        STI_PLUGIN_URL . 'assets/js/parsers.js',
        ['jquery', 'sti-papaparse'],
        '1.0.0',
        true
    );

    wp_enqueue_script(
        'sti-editor',
        STI_PLUGIN_URL . 'assets/js/editor.js',
        ['jquery', 'sti-parsers'],
        '1.0.0',
        true
    );
}

add_action('admin_enqueue_scripts', 'sti_enqueue_admin_assets');