<?php

if (!defined('ABSPATH')) {
    exit;
}

return new class {
    private $config = [];

    private $plugin_data = [];

    private $plugin_file = '';

    private $plugin_basename = '';

    private $plugin_slug = '';

    private $cache_key = '';

    public function init(array $config) {
        $defaults = [
            'source' => 'auto',
            'cache_ttl' => HOUR_IN_SECONDS,
            'private_repo' => false,
            'token' => '',
            'author' => '',
            'homepage' => '',
        ];

        $this->config = array_merge($defaults, $config);
        $this->plugin_file = $this->config['plugin_file'] ?? '';

        if (!$this->plugin_file || !file_exists($this->plugin_file)) {
            return;
        }

        if (!function_exists('plugin_basename')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $this->plugin_basename = plugin_basename($this->plugin_file);
        $this->plugin_slug = $this->config['plugin_slug'] ?? dirname($this->plugin_basename);
        $this->cache_key = 'github_updater_' . md5(
            ($this->config['repository'] ?? '') . '|' . $this->plugin_basename
        );

        add_filter(
            'pre_set_site_transient_update_plugins',
            [$this, 'filter_update_transient']
        );

        add_filter(
            'plugins_api',
            [$this, 'filter_plugin_info'],
            10,
            3
        );

        add_filter(
            'upgrader_source_selection',
            [$this, 'filter_source_selection'],
            10,
            4
        );
    }

    public function filter_update_transient($transient) {
        if (!$this->plugin_basename) {
            return $transient;
        }

        $this->load_plugin_data();

        if (!is_object($transient)) {
            $transient = new stdClass();
        }

        if (!isset($transient->response) || !is_array($transient->response)) {
            $transient->response = [];
        }

        $remote = $this->get_remote_release();

        if (!$remote) {
            return $transient;
        }

        $local_version = $this->get_local_version();

        if ($local_version && version_compare($remote['version'], $local_version, '<=')) {
            return $transient;
        }

        $transient->response[$this->plugin_basename] = (object) [
            'slug' => $this->plugin_slug,
            'plugin' => $this->plugin_basename,
            'new_version' => $remote['version'],
            'url' => $remote['url'],
            'package' => $remote['download_url'],
            'tested' => $this->config['tested'] ?? '',
            'requires' => $this->plugin_data['RequiresWP'] ?? '',
            'requires_php' => $this->plugin_data['RequiresPHP'] ?? '',
        ];

        return $transient;
    }

    public function filter_plugin_info($result, $action, $args) {
        if ($action !== 'plugin_information') {
            return $result;
        }

        if (!is_object($args) || empty($args->slug) || $args->slug !== $this->plugin_slug) {
            return $result;
        }

        $remote = $this->get_remote_release();

        if (!$remote) {
            return $result;
        }

        $this->load_plugin_data();

        return (object) [
            'name' => $this->plugin_data['Name'] ?? 'Plugin',
            'slug' => $this->plugin_slug,
            'version' => $remote['version'],
            'author' => $this->config['author'] ?: ($this->plugin_data['Author'] ?? ''),
            'homepage' => $this->config['homepage'] ?: $remote['url'],
            'download_link' => $remote['download_url'],
            'requires' => $this->plugin_data['RequiresWP'] ?? '',
            'requires_php' => $this->plugin_data['RequiresPHP'] ?? '',
            'tested' => $this->config['tested'] ?? '',
            'last_updated' => $remote['published_at'] ?? '',
            'sections' => [
                'description' => $this->plugin_data['Description'] ?? '',
                'changelog' => $remote['notes'] ?: 'Release notes are available on GitHub.',
            ],
            'banners' => [],
            'icons' => [],
        ];
    }

    public function filter_source_selection($source, $remote_source, $upgrader, $hook_extra) {
        if (
            empty($hook_extra['plugin']) ||
            $hook_extra['plugin'] !== $this->plugin_basename
        ) {
            return $source;
        }

        $source_name = basename($source);
        $target_name = basename(dirname($this->plugin_file));

        if ($source_name === $target_name) {
            return $source;
        }

        $new_source = trailingslashit($remote_source) . $target_name;

        if (file_exists($new_source)) {
            return new WP_Error(
                'folder_exists',
                sprintf(
                    'Destination folder already exists: %s',
                    esc_html($target_name)
                )
            );
        }

        if (!@rename($source, $new_source)) {
            return new WP_Error(
                'rename_failed',
                'Could not rename the update package directory.'
            );
        }

        return $new_source;
    }

    private function load_plugin_data() {
        if ($this->plugin_data) {
            return;
        }

        if (!function_exists('get_plugin_data')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $this->plugin_data = get_plugin_data($this->plugin_file, false, false);
    }

    private function get_local_version() {
        $this->load_plugin_data();

        return $this->plugin_data['Version'] ?? '';
    }

    private function get_remote_release() {
        $cached = get_site_transient($this->cache_key);

        if (is_array($cached) && !empty($cached['version']) && !empty($cached['download_url'])) {
            return $cached;
        }

        $repository = $this->config['repository'] ?? '';

        if (!$repository || strpos($repository, '/') === false) {
            return false;
        }

        $source = strtolower($this->config['source'] ?? 'auto');
        $attempts = [];

        if ($source === 'auto' || $source === 'release') {
            $attempts[] = 'release';
        }

        if ($source === 'auto' || $source === 'tags') {
            $attempts[] = 'tags';
        }

        foreach ($attempts as $attempt) {
            $remote = $attempt === 'release'
                ? $this->fetch_latest_release($repository)
                : $this->fetch_latest_tag($repository);

            if (!$remote) {
                continue;
            }

            set_site_transient($this->cache_key, $remote, (int) $this->config['cache_ttl']);

            return $remote;
        }

        return false;
    }

    private function fetch_latest_release($repository) {
        $response = $this->github_request(
            'https://api.github.com/repos/' . $this->encode_repository($repository) . '/releases/latest'
        );

        if (is_wp_error($response)) {
            return false;
        }

        $code = wp_remote_retrieve_response_code($response);

        if ($code !== 200) {
            return false;
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);

        if (empty($data['tag_name']) || empty($data['zipball_url'])) {
            return false;
        }

        return [
            'version' => $this->normalize_version($data['tag_name']),
            'download_url' => $this->build_download_url($data['zipball_url']),
            'url' => $data['html_url'] ?? 'https://github.com/' . $repository,
            'notes' => $data['body'] ?? '',
            'published_at' => $data['published_at'] ?? '',
        ];
    }

    private function fetch_latest_tag($repository) {
        $response = $this->github_request(
            'https://api.github.com/repos/' . $this->encode_repository($repository) . '/tags?per_page=1'
        );

        if (is_wp_error($response)) {
            return false;
        }

        $code = wp_remote_retrieve_response_code($response);

        if ($code !== 200) {
            return false;
        }

        $tags = json_decode(wp_remote_retrieve_body($response), true);

        if (empty($tags[0]['name']) || empty($tags[0]['zipball_url'])) {
            return false;
        }

        return [
            'version' => $this->normalize_version($tags[0]['name']),
            'download_url' => $this->build_download_url($tags[0]['zipball_url']),
            'url' => 'https://github.com/' . $repository . '/releases',
            'notes' => '',
            'published_at' => '',
        ];
    }

    private function github_request($url) {
        $headers = [
            'Accept' => 'application/vnd.github+json',
            'User-Agent' => $this->plugin_data['Name'] ?? 'WordPress',
        ];

        if (!empty($this->config['token'])) {
            $headers['Authorization'] = 'Bearer ' . $this->config['token'];
        }

        return wp_remote_get($url, [
            'timeout' => 15,
            'headers' => $headers,
        ]);
    }

    private function normalize_version($version) {
        $version = trim((string) $version);
        $version = preg_replace('/^[vV]/', '', $version);

        return $version;
    }

    private function encode_repository($repository) {
        $parts = array_map('rawurlencode', explode('/', $repository));

        return implode('/', $parts);
    }

    private function build_download_url($url) {
        if (empty($this->config['private_repo']) || empty($this->config['token'])) {
            return $url;
        }

        return add_query_arg('access_token', $this->config['token'], $url);
    }
};
