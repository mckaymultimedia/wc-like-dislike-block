<?php
/**
 * Plugin Name: WooCommerce Like/Dislike Block
 * Description: A custom Gutenberg block for liking/disliking WooCommerce products
 * Version: 1.0.0
 * Author: Your Name
 * License: GPL v2 or later
 * Text Domain: wc-like-dislike
 */

defined( 'ABSPATH' ) || exit;

// Define plugin constants
define( 'WC_LIKE_DISLIKE_VERSION', '1.0.0' );
define( 'WC_LIKE_DISLIKE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WC_LIKE_DISLIKE_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );

class WC_Like_Dislike_Block {

    public function __construct() {
        add_action( 'init', array( $this, 'init' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_scripts' ) );
        add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
        add_action( 'wp_ajax_wc_like_dislike_vote', array( $this, 'handle_ajax_vote' ) );
        add_action( 'wp_ajax_nopriv_wc_like_dislike_vote', array( $this, 'handle_no_privileges' ) );
    }

    public function init() {
        // Register block
        register_block_type( WC_LIKE_DISLIKE_PLUGIN_PATH . 'build', array(
            'render_callback' => array( $this, 'render_block' )
        ) );

        // Create database table for storing likes/dislikes
        $this->create_database_table();
    }

    private function create_database_table() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'wc_product_likes';
        
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            product_id bigint(20) NOT NULL,
            user_id bigint(20) NOT NULL,
            vote_type enum('like','dislike') NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY user_product (user_id, product_id)
        ) $charset_collate;";

        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
        dbDelta( $sql );
    }

    public function render_block( $attributes ) {
        if ( ! is_user_logged_in() ) {
            return '<div class="wc-like-dislike-block">' . 
                   __( 'Please log in to like/dislike products.', 'wc-like-dislike' ) . 
                   '</div>';
        }

        global $product;
        if ( ! $product ) {
            return '<div class="wc-like-dislike-block">' . 
                   __( 'Product not found.', 'wc-like-dislike' ) . 
                   '</div>';
        }

        $product_id = $product->get_id();
        $user_id = get_current_user_id();

        // Get user's current vote
        $current_vote = $this->get_user_vote( $product_id, $user_id );
        
        // Get total counts
        $like_count = $this->get_vote_count( $product_id, 'like' );
        $dislike_count = $this->get_vote_count( $product_id, 'dislike' );

        ob_start();
        ?>
        <div class="wc-like-dislike-block" 
             data-product-id="<?php echo esc_attr( $product_id ); ?>"
             data-user-id="<?php echo esc_attr( $user_id ); ?>"
             data-nonce="<?php echo esc_attr( wp_create_nonce( 'wc_like_dislike_nonce' ) ); ?>">
            
            <div class="wc-like-dislike-buttons">
                <button class="wc-like-button <?php echo $current_vote === 'like' ? 'active' : ''; ?>" 
                        data-vote="like">
                    <span class="dashicons dashicons-thumbs-up"></span>
                    <span class="wc-count"><?php echo esc_html( $like_count ); ?></span>
                </button>
                
                <button class="wc-dislike-button <?php echo $current_vote === 'dislike' ? 'active' : ''; ?>" 
                        data-vote="dislike">
                    <span class="dashicons dashicons-thumbs-down"></span>
                    <span class="wc-count"><?php echo esc_html( $dislike_count ); ?></span>
                </button>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    public function enqueue_frontend_scripts() {
        if ( is_product() ) {
            wp_enqueue_script(
                'wc-like-dislike-frontend',
                WC_LIKE_DISLIKE_PLUGIN_URL . 'build/frontend.js',
                array( 'jquery' ),
                WC_LIKE_DISLIKE_VERSION,
                true
            );

            wp_localize_script( 'wc-like-dislike-frontend', 'wcLikeDislike', array(
                'ajaxurl' => admin_url( 'admin-ajax.php' ),
                'nonce' => wp_create_nonce( 'wc_like_dislike_nonce' )
            ) );
        }
    }

    public function register_rest_routes() {
        register_rest_route( 'wc-like-dislike/v1', '/vote', array(
            'methods' => 'POST',
            'callback' => array( $this, 'handle_vote' ),
            'permission_callback' => array( $this, 'check_permissions' )
        ) );
    }

    public function check_permissions() {
        return is_user_logged_in();
    }

    public function handle_vote( $request ) {
        $params = $request->get_params();
        $product_id = intval( $params['product_id'] );
        $user_id = get_current_user_id();
        $vote_type = sanitize_text_field( $params['vote_type'] );

        if ( ! in_array( $vote_type, array( 'like', 'dislike' ) ) ) {
            return new WP_Error( 'invalid_vote', __( 'Invalid vote type', 'wc-like-dislike' ), array( 'status' => 400 ) );
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'wc_product_likes';

        // Check if user already voted
        $existing_vote = $wpdb->get_var( $wpdb->prepare(
            "SELECT vote_type FROM $table_name WHERE user_id = %d AND product_id = %d",
            $user_id, $product_id
        ) );

        if ( $existing_vote ) {
            if ( $existing_vote === $vote_type ) {
                // Remove vote if clicking same button again
                $wpdb->delete( $table_name, array( 'user_id' => $user_id, 'product_id' => $product_id ) );
            } else {
                // Update vote if different type
                $wpdb->update( $table_name, array( 'vote_type' => $vote_type ), array( 'user_id' => $user_id, 'product_id' => $product_id ) );
            }
        } else {
            // Insert new vote
            $wpdb->insert( $table_name, array(
                'user_id' => $user_id,
                'product_id' => $product_id,
                'vote_type' => $vote_type
            ) );
        }

        // Return updated counts
        return array(
            'like_count' => $this->get_vote_count( $product_id, 'like' ),
            'dislike_count' => $this->get_vote_count( $product_id, 'dislike' ),
            'user_vote' => $existing_vote === $vote_type ? null : $vote_type
        );
    }

    private function get_user_vote( $product_id, $user_id ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'wc_product_likes';
        
        return $wpdb->get_var( $wpdb->prepare(
            "SELECT vote_type FROM $table_name WHERE user_id = %d AND product_id = %d",
            $user_id, $product_id
        ) );
    }

    private function get_vote_count( $product_id, $vote_type ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'wc_product_likes';
        
        return $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM $table_name WHERE product_id = %d AND vote_type = %s",
            $product_id, $vote_type
        ) ) ?: 0;
    }

    public function handle_ajax_vote() {
        check_ajax_referer( 'wc_like_dislike_nonce', 'nonce' );
    
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( __( 'You must be logged in to vote.', 'wc-like-dislike' ) );
            return;
        }
    
        $product_id = intval( $_POST['product_id'] );
        $user_id = get_current_user_id();
        $vote_type = sanitize_text_field( $_POST['vote_type'] );
    
        if ( ! in_array( $vote_type, array( 'like', 'dislike' ) ) ) {
            wp_send_json_error( __( 'Invalid vote type.', 'wc-like-dislike' ) );
            return;
        }
    
        global $wpdb;
        $table_name = $wpdb->prefix . 'wc_product_likes';
    
        // Check if user already voted
        $existing_vote = $wpdb->get_var( $wpdb->prepare(
            "SELECT vote_type FROM $table_name WHERE user_id = %d AND product_id = %d",
            $user_id, $product_id
        ) );
    
        if ( $existing_vote ) {
            if ( $existing_vote === $vote_type ) {
                // Remove vote if clicking same button again
                $wpdb->delete( $table_name, array( 'user_id' => $user_id, 'product_id' => $product_id ) );
                $user_vote = null;
            } else {
                // Update vote if different type
                $wpdb->update( $table_name, array( 'vote_type' => $vote_type ), array( 'user_id' => $user_id, 'product_id' => $product_id ) );
                $user_vote = $vote_type;
            }
        } else {
            // Insert new vote
            $wpdb->insert( $table_name, array(
                'user_id' => $user_id,
                'product_id' => $product_id,
                'vote_type' => $vote_type
            ) );
            $user_vote = $vote_type;
        }
    
        wp_send_json_success( array(
            'like_count' => $this->get_vote_count( $product_id, 'like' ),
            'dislike_count' => $this->get_vote_count( $product_id, 'dislike' ),
            'user_vote' => $user_vote
        ) );
    }
    
    public function handle_no_privileges() {
        wp_send_json_error( __( 'You must be logged in to vote.', 'wc-like-dislike' ) );
    }
}

new WC_Like_Dislike_Block();