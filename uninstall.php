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
);

foreach ( $ytaibp_options as $ytaibp_option ) {
    delete_option( $ytaibp_option );
}
