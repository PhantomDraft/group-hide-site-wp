<?php
/**
 * Plugin Name: PD Group Hide Site
 * Description: This plugin allows you to hide your website entirely with a redirect to a specified page or login page. You can also choose to hide only for specific roles, or hide only specific content (by ID or slug) with individual group settings.
 * Version:     1.1
 * Author:      PD
 * Author URI:  https://guides.phantom-draft.com/
 * License:     GPL2
 * Text Domain: pd-hide-site
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PD_Hide_Site {
    private static $instance = null;
    private $option_name = 'pd_hide_site_options';

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        // Register settings
        add_action( 'admin_init', [ $this, 'register_settings' ] );
        // Add settings menu
        add_action( 'admin_menu', [ $this, 'add_admin_menu' ] );
        // Intercept page requests for redirection
        add_action( 'template_redirect', [ $this, 'maybe_redirect' ] );
    }

    /**
     * Register settings in WordPress.
     */
    public function register_settings() {
        register_setting( $this->option_name, $this->option_name, [ $this, 'sanitize_settings' ] );

        add_settings_section(
            'pd_hide_site_main_section',
            'Main Site Hiding Settings',
            null,
            $this->option_name
        );

        // New parameter: hiding scope
        add_settings_field(
            'hide_scope',
            'Hiding Scope',
            [ $this, 'field_hide_scope_callback' ],
            $this->option_name,
            'pd_hide_site_main_section'
        );

        // Materials mapping field – appears if "hide by material" is selected
        add_settings_field(
            'materials_mapping',
            'Content to Hide',
            [ $this, 'field_materials_mapping_callback' ],
            $this->option_name,
            'pd_hide_site_main_section'
        );

        // Existing fields

        // Hiding mode (global mode)
        add_settings_field(
            'mode',
            'Global Hiding Mode',
            [ $this, 'field_mode_callback' ],
            $this->option_name,
            'pd_hide_site_main_section'
        );

        // Redirect page selection
        add_settings_field(
            'redirect_page',
            'Redirect Page',
            [ $this, 'field_redirect_page_callback' ],
            $this->option_name,
            'pd_hide_site_main_section'
        );

        // Roles for hiding (if "roles" mode is selected)
        add_settings_field(
            'roles',
            'Roles to Hide (Global)',
            [ $this, 'field_roles_callback' ],
            $this->option_name,
            'pd_hide_site_main_section'
        );
    }

    /**
     * Sanitize incoming settings.
     */
    public function sanitize_settings( $input ) {
        $valid = get_option( $this->option_name );

        // New parameter: hiding scope
        $valid['hide_scope'] = ( isset( $input['hide_scope'] ) && in_array( $input['hide_scope'], [ 'global', 'by_material' ] ) ) ? $input['hide_scope'] : 'global';

        // Process materials mapping – expects multi-line input
        if ( isset( $input['materials_mapping'] ) ) {
            // If received as an array, join it into a string
            if ( is_array( $input['materials_mapping'] ) ) {
                $input['materials_mapping'] = implode( "\n", $input['materials_mapping'] );
            }
            $lines = explode( "\n", $input['materials_mapping'] );
            $mappings = [];
            foreach ( $lines as $line ) {
                $line = trim( $line );
                if ( empty( $line ) ) {
                    continue;
                }
                // Expected format: identifier|role1,role2,...
                $parts = explode( '|', $line );
                $identifier = trim( $parts[0] );
                $groups = [];
                if ( isset( $parts[1] ) ) {
                    $groups_raw = explode( ',', $parts[1] );
                    foreach ( $groups_raw as $grp ) {
                        $grp = trim( $grp );
                        if ( ! empty( $grp ) ) {
                            $groups[] = sanitize_text_field( $grp );
                        }
                    }
                }
                if ( ! empty( $identifier ) ) {
                    $mappings[] = [
                        'identifier' => sanitize_text_field( $identifier ),
                        'groups'     => $groups,
                    ];
                }
            }
            $valid['materials_mapping'] = $mappings;
        }

        // Existing settings
        $valid['mode'] = isset( $input['mode'] ) && in_array( $input['mode'], [ 'off', 'full', 'roles' ] ) ? $input['mode'] : 'off';
        $valid['redirect_page'] = isset( $input['redirect_page'] ) ? absint( $input['redirect_page'] ) : 0;
        $valid['roles'] = isset( $input['roles'] ) && is_array( $input['roles'] ) ? array_map( 'sanitize_text_field', $input['roles'] ) : [];

        return $valid;
    }

    /**
     * Hiding scope selection field.
     */
    public function field_hide_scope_callback() {
        $options = get_option( $this->option_name );
        $hide_scope = isset( $options['hide_scope'] ) ? $options['hide_scope'] : 'global';
        ?>
        <select name="<?php echo esc_attr( $this->option_name ); ?>[hide_scope]">
            <option value="global" <?php selected( $hide_scope, 'global' ); ?>>Hide Entire Site</option>
            <option value="by_material" <?php selected( $hide_scope, 'by_material' ); ?>>Hide Only Specific Content (by ID/slug)</option>
        </select>
        <p class="description">Select the hiding scope. When "Hide Entire Site" is chosen, the global settings below will be used. When "Hide Only Specific Content" is selected, individual rules for the specified materials will apply.</p>
        <?php
    }

    /**
     * Multi-line text field for materials mapping.
     */
    public function field_materials_mapping_callback() {
        $options = get_option( $this->option_name );
        $mappings = isset( $options['materials_mapping'] ) ? $options['materials_mapping'] : [];
        // Convert mappings array back to lines for display
        $lines = [];
        foreach ( $mappings as $mapping ) {
            $line = $mapping['identifier'] . '|' . implode( ',', $mapping['groups'] );
            $lines[] = $line;
        }
        $value = implode( "\n", $lines );
        ?>
        <textarea name="<?php echo esc_attr( $this->option_name ); ?>[materials_mapping]" rows="5" cols="50" style="font-family: monospace;"><?php echo esc_textarea( $value ); ?></textarea>
        <p class="description">
            Enter the rules for content hiding. Each line should be in the format: <code>identifier|role1,role2,...</code>.<br>
            The identifier can be an ID (number) or slug (text). If left empty after the separator, the content will be hidden for all users.<br>
            Examples:<br>
            <code>42|editor,subscriber</code><br>
            <code>about-us|</code>
        </p>
        <?php
    }

    /**
     * Global hiding mode selection field.
     */
    public function field_mode_callback() {
        $options = get_option( $this->option_name );
        $mode = isset( $options['mode'] ) ? $options['mode'] : 'off';
        ?>
        <select name="<?php echo esc_attr( $this->option_name ); ?>[mode]">
            <option value="off" <?php selected( $mode, 'off' ); ?>>Disabled</option>
            <option value="full" <?php selected( $mode, 'full' ); ?>>Full Hiding</option>
            <option value="roles" <?php selected( $mode, 'roles' ); ?>>Hide for Selected Roles (others see the site)</option>
        </select>
        <p class="description">Global hiding settings. When "Hide Entire Site" is selected, the site will be hidden from guests but remain accessible to logged-in users.</p>
        <?php
    }

    /**
     * Redirect page selection field.
     */
    public function field_redirect_page_callback() {
        $options = get_option( $this->option_name );
        $redirect_page = isset( $options['redirect_page'] ) ? $options['redirect_page'] : 0;

        $pages = get_pages();
        ?>
        <select name="<?php echo esc_attr( $this->option_name ); ?>[redirect_page]">
            <option value="0" <?php selected( $redirect_page, 0 ); ?>>(default login page)</option>
            <?php foreach ( $pages as $page ) : ?>
                <option value="<?php echo $page->ID; ?>" <?php selected( $redirect_page, $page->ID ); ?>>
                    <?php echo esc_html( $page->post_title ); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <p class="description">If the option "(default login page)" is selected, users will be redirected to the standard login page (/wp-login.php). Otherwise, they will be redirected to the chosen page.</p>
        <?php
    }

    /**
     * Global roles selection field for hiding.
     */
    public function field_roles_callback() {
        global $wp_roles;
        $options = get_option( $this->option_name );
        $selected_roles = isset( $options['roles'] ) ? $options['roles'] : [];
        if ( ! isset( $wp_roles ) ) {
            $wp_roles = new WP_Roles();
        }
        $all_roles = $wp_roles->roles;

        foreach ( $all_roles as $role_key => $role_data ) {
            $checked = in_array( $role_key, $selected_roles ) ? 'checked' : '';
            ?>
            <label style="display:block;">
                <input type="checkbox" name="<?php echo esc_attr( $this->option_name ); ?>[roles][]" value="<?php echo esc_attr( $role_key ); ?>" <?php echo $checked; ?> />
                <?php echo esc_html( translate_user_role( $role_data['name'] ) ); ?>
            </label>
            <?php
        }
        ?>
        <p class="description">Select the roles for which the site should be hidden (in global mode, if "Hide for Selected Roles" is chosen).</p>
        <?php
    }

    /**
     * Add settings menu.
     */
    public function add_admin_menu() {
        // Add top-level "PD" menu
        add_menu_page(
            'PD',
            'PD',
            'manage_options',
            'pd_main_menu',
            '', // You can pass a function if you want the main page to output something
            'dashicons-shield', // Icon (optional)
            2  // Menu position (optional)
        );

        // Add submenu for PD Group Hide Site settings
        add_submenu_page(
            'pd_main_menu',
            'PD Group Hide Site',
            'PD Group Hide Site',
            'manage_options',
            'pd_hide_site',
            [ $this, 'options_page_html' ]
        );
    }

    /**
     * Template for the settings page.
     */
    public function options_page_html() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        ?>
        <div class="wrap">
            <h1>PD Group Hide Site Settings</h1>
            <form method="post" action="options.php">
                <?php
                settings_fields( $this->option_name );
                do_settings_sections( $this->option_name );
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    /*
     * Standard check for wp-login.php.
     */
    private function is_login_page() {
        return ( in_array( $GLOBALS['pagenow'], array( 'wp-login.php', 'wp-register.php' ) ) );
    }

    /**
     * Check and redirect if necessary.
     */
    public function maybe_redirect() {
        // Do not interfere in admin
        if ( is_admin() ) {
            return;
        }

        // Avoid loops during AJAX/REST requests
        if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
            return;
        }
        if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
            return;
        }

        // Load options
        $options = get_option( $this->option_name );
        $hide_scope = isset( $options['hide_scope'] ) ? $options['hide_scope'] : 'global';
        $redirect_page = isset( $options['redirect_page'] ) ? intval( $options['redirect_page'] ) : 0;

        // Check if we are already on the login page
        if ( $this->is_login_page() ) {
            return;
        }

        // If a specific redirect page is set (not 0), check that we are not on that page
        if ( $redirect_page !== 0 && is_page( $redirect_page ) ) {
            return;
        }

        if ( $hide_scope === 'global' ) {
            // Global mode – use default logic

            $mode = isset( $options['mode'] ) ? $options['mode'] : 'off';
            $roles_to_hide = isset( $options['roles'] ) ? $options['roles'] : [];

            if ( $mode === 'off' ) {
                return;
            }

            if ( ! is_user_logged_in() ) {
                $this->do_redirect( $redirect_page );
            } else {
                $user = wp_get_current_user();
                if ( $mode === 'full' ) {
                    if ( ! user_can( $user, 'manage_options' ) ) {
                        $this->do_redirect( $redirect_page );
                    }
                }
                if ( $mode === 'roles' ) {
                    foreach ( $user->roles as $user_role ) {
                        if ( in_array( $user_role, $roles_to_hide ) ) {
                            $this->do_redirect( $redirect_page );
                        }
                    }
                }
            }
        } elseif ( $hide_scope === 'by_material' ) {
            // Hiding by specific content

            // Determine if the current page is a content item (post, page, product, taxonomy, etc.)
            $current_id = null;
            $current_slug = null;
            if ( is_singular() ) {
                $post = get_post();
                if ( $post ) {
                    $current_id = $post->ID;
                    $current_slug = $post->post_name;
                }
            } elseif ( is_category() || is_tag() || is_tax() ) {
                $term = get_queried_object();
                if ( $term ) {
                    $current_id = $term->term_id;
                    $current_slug = $term->slug;
                }
            } else {
                // If identifier cannot be determined, do nothing
                return;
            }

            $mappings = isset( $options['materials_mapping'] ) ? $options['materials_mapping'] : [];
            foreach ( $mappings as $mapping ) {
                $identifier = $mapping['identifier'];
                $groups = isset( $mapping['groups'] ) ? $mapping['groups'] : [];

                // If identifier is numeric, compare with ID; otherwise, compare with slug
                if ( is_numeric( $identifier ) && (int)$identifier === (int)$current_id ) {
                    $this->process_material_mapping( $groups, $redirect_page );
                } elseif ( ! is_numeric( $identifier ) && $identifier === $current_slug ) {
                    $this->process_material_mapping( $groups, $redirect_page );
                }
            }
        }
    }

    /**
     * Process material rule: if groups array is empty – hide for all; otherwise, hide for users in the specified groups.
     */
    private function process_material_mapping( $groups, $redirect_page ) {
        if ( ! is_user_logged_in() ) {
            $this->do_redirect( $redirect_page );
        } else {
            $user = wp_get_current_user();
            // If the groups list is empty – hide for all logged-in users
            if ( empty( $groups ) ) {
                $this->do_redirect( $redirect_page );
            } else {
                foreach ( $user->roles as $role ) {
                    if ( in_array( $role, $groups ) ) {
                        $this->do_redirect( $redirect_page );
                    }
                }
            }
        }
    }

    /**
     * Execute redirection.
     */
    private function do_redirect( $redirect_page ) {
        if ( $redirect_page === 0 ) {
            wp_redirect( wp_login_url() );
        } else {
            $url = get_permalink( $redirect_page );
            if ( ! $url ) {
                $url = home_url(); // fallback
            }
            wp_redirect( $url );
        }
        exit;
    }
}

PD_Hide_Site::get_instance();