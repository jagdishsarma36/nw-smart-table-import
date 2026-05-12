<?php
/**
 * GitHub Plugin Updater
 *
 * A reusable class that enables automatic WordPress plugin updates
 * directly from a GitHub repository (public or private).
 *
 * Compatible with PHP 7.2+ and WordPress 5.0+
 *
 * Usage:
 *   $updater = require plugin_dir_path(__FILE__) . 'includes/github-updater.php';
 *   $updater->init([
 *       'plugin_file' => __FILE__,
 *       'repository'  => 'username/repo-name',
 *       'source'      => 'auto',   // 'auto' | 'release' | 'branch'
 *       'branch'      => 'main',   // used when source = 'branch'
 *       'private'     => false,
 *       'token'       => '',       // required when private = true
 *   ]);
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'STI_GitHub_Plugin_Updater' ) ) :

class STI_GitHub_Plugin_Updater {

    /** @var array Merged configuration */
    private $config = array();

    /** @var string Plugin slug (folder/file.php) */
    private $plugin_slug = '';

    /** @var string Base GitHub API URL */
    private $api_base = 'https://api.github.com/repos/';

    /** @var object|null Cached release data */
    private $release_cache = null;

    // -------------------------------------------------------------------------
    // Boot
    // -------------------------------------------------------------------------

    /**
     * Initialise the updater with the supplied config and register WP hooks.
     *
     * @param array $config
     * @return self
     */
    public function init( $config ) {
        $defaults = array(
            'plugin_file' => '',
            'repository'  => '',
            'source'      => 'auto',  // 'auto' | 'release' | 'branch'
            'branch'      => 'main',
            'private'     => false,
            'token'       => '',
        );

        $this->config      = array_merge( $defaults, $config );
        $this->plugin_slug = plugin_basename( $this->config['plugin_file'] );

        if ( empty( $this->config['repository'] ) || empty( $this->config['plugin_file'] ) ) {
            _doing_it_wrong(
                __METHOD__,
                'GitHub Updater requires "plugin_file" and "repository".',
                '1.0.0'
            );
            return $this;
        }

        $this->register_hooks();

        return $this;
    }

    // -------------------------------------------------------------------------
    // Hooks
    // -------------------------------------------------------------------------

    private function register_hooks() {
        // Inject update info into the transient WordPress checks.
        add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_for_update' ) );

        // Supply package details for the update screen.
        add_filter( 'plugins_api', array( $this, 'plugin_info' ), 20, 3 );

        // After the update completes, rename the unzipped folder if needed.
        add_filter( 'upgrader_source_selection', array( $this, 'fix_source_dir' ), 10, 4 );
    }

    // -------------------------------------------------------------------------
    // Update Check
    // -------------------------------------------------------------------------

    /**
     * Called by WordPress when it checks for plugin updates.
     *
     * @param object $transient
     * @return object
     */
    public function check_for_update( $transient ) {
        if ( empty( $transient->checked ) ) {
            return $transient;
        }

        $release = $this->get_release();

        if ( ! $release ) {
            return $transient;
        }

        $remote_version = $this->parse_version( $release );
        $local_version  = isset( $transient->checked[ $this->plugin_slug ] )
            ? $transient->checked[ $this->plugin_slug ]
            : '0.0.0';

        if ( version_compare( $remote_version, $local_version, '>' ) ) {
            $transient->response[ $this->plugin_slug ] = $this->build_update_object( $release, $remote_version );
        }

        return $transient;
    }

    /**
     * Build the stdClass object WordPress expects in the update transient.
     *
     * @param object $release
     * @param string $version
     * @return object
     */
    private function build_update_object( $release, $version ) {
        $obj              = new stdClass();
        $obj->slug        = $this->get_plugin_folder();
        $obj->plugin      = $this->plugin_slug;
        $obj->new_version = $version;
        $obj->url         = 'https://github.com/' . $this->config['repository'];
        $obj->package     = $this->get_download_url( $release );
        $obj->tested      = get_bloginfo( 'version' );
        $obj->icons       = array();
        $obj->banners     = array();

        return $obj;
    }

    // -------------------------------------------------------------------------
    // Plugin Info (Details popup)
    // -------------------------------------------------------------------------

    /**
     * Supply plugin information to the "View version X.X.X details" popup.
     *
     * @param false|object|array $result
     * @param string             $action
     * @param object             $args
     * @return false|object
     */
    public function plugin_info( $result, $action, $args ) {
        if ( 'plugin_information' !== $action ) {
            return $result;
        }

        if ( ! isset( $args->slug ) || $args->slug !== $this->get_plugin_folder() ) {
            return $result;
        }

        $release = $this->get_release();

        if ( ! $release ) {
            return $result;
        }

        $plugin_data = get_plugin_data( $this->config['plugin_file'] );
        $version     = $this->parse_version( $release );

        $info                = new stdClass();
        $info->name          = ! empty( $plugin_data['Name'] ) ? $plugin_data['Name'] : $this->get_plugin_folder();
        $info->slug          = $this->get_plugin_folder();
        $info->version       = $version;
        $info->author        = ! empty( $plugin_data['Author'] ) ? $plugin_data['Author'] : '';
        $info->homepage      = 'https://github.com/' . $this->config['repository'];
        $info->requires      = ! empty( $plugin_data['RequiresWP'] ) ? $plugin_data['RequiresWP'] : '5.0';
        $info->requires_php  = ! empty( $plugin_data['RequiresPHP'] ) ? $plugin_data['RequiresPHP'] : '7.2';
        $info->tested        = get_bloginfo( 'version' );
        $info->sections      = array(
            'description' => ! empty( $plugin_data['Description'] ) ? $plugin_data['Description'] : '',
            'changelog'   => $this->format_changelog( $release ),
        );
        $info->download_link = $this->get_download_url( $release );

        return $info;
    }

    // -------------------------------------------------------------------------
    // Source Directory Fix
    // -------------------------------------------------------------------------

    /**
     * GitHub zips the repo as "repo-tag/", which may not match the expected
     * plugin folder name. Rename it so WP puts files in the right place.
     *
     * @param string       $source
     * @param string       $remote_source
     * @param WP_Upgrader  $upgrader
     * @param array        $hook_extra
     * @return string|WP_Error
     */
    public function fix_source_dir( $source, $remote_source, $upgrader, $hook_extra ) {
        if ( ! isset( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->plugin_slug ) {
            return $source;
        }

        global $wp_filesystem;

        $expected = trailingslashit( dirname( $source ) ) . $this->get_plugin_folder() . '/';

        if ( $source !== $expected ) {
            if ( $wp_filesystem->move( $source, $expected ) ) {
                return $expected;
            }
            return new WP_Error(
                'github_updater_rename_failed',
                sprintf( 'Could not rename plugin directory from %s to %s.', $source, $expected )
            );
        }

        return $source;
    }

    // -------------------------------------------------------------------------
    // GitHub API
    // -------------------------------------------------------------------------

    /**
     * Fetch and cache release data from GitHub.
     *
     * @return object|null
     */
    private function get_release() {
        if ( null !== $this->release_cache ) {
            return $this->release_cache;
        }

        $source = $this->resolve_source();

        if ( 'branch' === $source ) {
            $url = $this->api_base . $this->config['repository'] . '/branches/' . $this->config['branch'];
        } else {
            $url = $this->api_base . $this->config['repository'] . '/releases/latest';
        }

        $args = array(
            'timeout' => 10,
            'headers' => array(
                'Accept'     => 'application/vnd.github+json',
                'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ),
            ),
        );

        if ( $this->config['private'] && ! empty( $this->config['token'] ) ) {
            $args['headers']['Authorization'] = 'Bearer ' . $this->config['token'];
        }

        $response = wp_remote_get( $url, $args );

        if ( is_wp_error( $response ) ) {
            return null;
        }

        if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
            return null;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ) );

        if ( empty( $body ) ) {
            return null;
        }

        // For branch source, wrap the commit info into a minimal release-like object.
        if ( 'branch' === $source ) {
            $body = $this->normalise_branch_response( $body );
        }

        $this->release_cache = $body;

        return $this->release_cache;
    }

    /**
     * Turn a branch API response into something that resembles a release object.
     *
     * @param object $branch
     * @return object
     */
    private function normalise_branch_response( $branch ) {
        $release              = new stdClass();
        $release->tag_name    = isset( $branch->commit->sha ) ? $branch->commit->sha : 'dev';
        $release->name        = 'Latest commit on ' . $this->config['branch'];
        $release->body        = '';
        $release->zipball_url = 'https://github.com/' . $this->config['repository']
                                . '/archive/refs/heads/' . $this->config['branch'] . '.zip';
        $release->assets      = array();

        return $release;
    }

    /**
     * Resolve the effective source strategy.
     * 'auto' prefers 'release', falls back to 'branch' if no releases exist.
     *
     * @return string 'release' | 'branch'
     */
    private function resolve_source() {
        $source = $this->config['source'];

        if ( 'auto' !== $source ) {
            return $source;
        }

        // Probe the releases endpoint; fall back to branch on 404 or error.
        $test_url = $this->api_base . $this->config['repository'] . '/releases/latest';
        $args     = array(
            'timeout' => 8,
            'headers' => array(
                'Accept'     => 'application/vnd.github+json',
                'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ),
            ),
        );

        if ( $this->config['private'] && ! empty( $this->config['token'] ) ) {
            $args['headers']['Authorization'] = 'Bearer ' . $this->config['token'];
        }

        $response = wp_remote_get( $test_url, $args );

        if ( is_wp_error( $response ) ) {
            return 'branch';
        }

        if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
            return 'branch';
        }

        return 'release';
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Extract a clean semver string from a release object.
     *
     * @param object $release
     * @return string
     */
    private function parse_version( $release ) {
        $tag = isset( $release->tag_name ) ? $release->tag_name : '0.0.0';
        return ltrim( $tag, 'vV' );
    }

    /**
     * Return the download URL for the release zip.
     *
     * @param object $release
     * @return string
     */
    private function get_download_url( $release ) {
        // Prefer an explicit .zip asset attached to the release.
        if ( ! empty( $release->assets ) && is_array( $release->assets ) ) {
            foreach ( $release->assets as $asset ) {
                $name = isset( $asset->name ) ? $asset->name : '';
                // PHP 7.2-compatible alternative to str_ends_with().
                if ( '.zip' === substr( $name, -4 ) ) {
                    $download_url = isset( $asset->browser_download_url )
                        ? $asset->browser_download_url
                        : '';
                    if ( $this->config['private'] && ! empty( $this->config['token'] ) ) {
                        return add_query_arg( 'access_token', $this->config['token'], $download_url );
                    }
                    return $download_url;
                }
            }
        }

        // Fall back to GitHub's auto-generated zipball.
        if ( ! empty( $release->zipball_url ) ) {
            $url = $release->zipball_url;
        } else {
            $url = 'https://github.com/' . $this->config['repository']
                   . '/archive/refs/heads/' . $this->config['branch'] . '.zip';
        }

        if ( $this->config['private'] && ! empty( $this->config['token'] ) ) {
            return add_query_arg( 'access_token', $this->config['token'], $url );
        }

        return $url;
    }

    /**
     * Get the plugin's directory folder name (the part before the slash in the slug).
     *
     * @return string
     */
    private function get_plugin_folder() {
        $parts = explode( '/', $this->plugin_slug );
        return $parts[0];
    }

    /**
     * Format the GitHub release body into basic HTML for the changelog tab.
     *
     * @param object $release
     * @return string
     */
    private function format_changelog( $release ) {
        $body = isset( $release->body ) ? $release->body : '';

        if ( empty( $body ) ) {
            return '<p>No changelog provided.</p>';
        }

        // Light Markdown -> HTML: bold, unordered lists, line breaks.
        $html = esc_html( $body );
        $html = preg_replace( '/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $html );
        $html = preg_replace( '/^[\*\-] (.+)$/m', '<li>$1</li>', $html );
        $html = preg_replace( '/(<li>.+<\/li>)/s', '<ul>$1</ul>', $html );
        $html = nl2br( $html );

        return $html;
    }
}

endif; // class_exists STI_GitHub_Plugin_Updater

// Return a singleton instance so `require` yields a ready-to-use object.
return new STI_GitHub_Plugin_Updater();