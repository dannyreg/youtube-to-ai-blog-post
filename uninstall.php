<?php
/**
 * Remove settings owned by YouTube To AI Blog Post.
 * Created drafts, published posts, and their media remain in WordPress.
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

$ytaibp_options = array(
    'ytaibp_transcript_api_key',
    'ytaibp_openai_api_key',
    'ytaibp_openai_model',
    'ytaibp_openai_system_prompt',
    'ytaibp_post_author',
    'ytaibp_post_category',
    'ytaibp_summary_prompt',
    'ytaibp_post_template',
    'ytaibp_plugin_enabled',
    'ytaibp_channel_id',
    'ytaibp_automation_state',
    'ytaibp_poll_lock',
);

foreach ( $ytaibp_options as $ytaibp_option ) {
    delete_option( $ytaibp_option );
}

wp_clear_scheduled_hook( 'ytaibp_check_youtube' );

// A killed request can leave a video lease behind. Posts and source metadata stay.
global $wpdb;
$ytaibp_locks = $wpdb->get_col( $wpdb->prepare(
    "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
    $wpdb->esc_like( 'ytaibp_video_lock_' ) . '%'
) );
foreach ( $ytaibp_locks as $ytaibp_lock ) {
    delete_option( $ytaibp_lock );
}
