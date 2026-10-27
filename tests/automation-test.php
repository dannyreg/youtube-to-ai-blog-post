<?php
/** Run with: php tests/automation-test.php. No real API calls or WordPress DB. */
error_reporting( E_ALL );
set_error_handler( function ( $severity, $message, $file, $line ) {
    if ( error_reporting() & $severity ) {
        throw new ErrorException( $message, 0, $severity, $file, $line );
    }
    return false;
} );
define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'WPINC', 'tests/fixtures' );
define( 'HOUR_IN_SECONDS', 3600 );

class WP_Error {
    private $code;
    private $message;
    private $data;
    public function __construct( $code, $message, $data = null ) { $this->code = $code; $this->message = $message; $this->data = $data; }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
    public function get_error_data() { return $this->data; }
}
class TestRedirect extends Exception {}
class TestDie extends Exception {}
class TestJson extends Exception {
    public $success;
    public $data;
    public function __construct( $success, $data ) { $this->success = $success; $this->data = $data; }
}
class TestDatabase {
    public $options = 'wp_options';
    public $posts = 'wp_posts';
    public $postmeta = 'wp_postmeta';
    public function prepare( $sql, ...$args ) { return array( $sql, $args ); }
    public function query( $query ) {
        list( $sql, $args ) = $query;
        if ( 0 === strpos( $sql, 'DELETE' ) ) {
            list( $name, $value ) = $args;
            if ( isset( $GLOBALS['options'][ $name ] ) && $GLOBALS['options'][ $name ] === $value ) {
                unset( $GLOBALS['options'][ $name ] );
                return 1;
            }
        } elseif ( 0 === strpos( $sql, 'UPDATE' ) ) {
            list( $value, $name, $expected ) = $args;
            if ( isset( $GLOBALS['before_cas'] ) ) {
                $callback = $GLOBALS['before_cas'];
                unset( $GLOBALS['before_cas'] );
                $callback();
            }
            if ( isset( $GLOBALS['options'][ $name ] ) && maybe_serialize( $GLOBALS['options'][ $name ] ) === $expected ) {
                $GLOBALS['options'][ $name ] = unserialize( $value );
                return 1;
            }
        }
        return 0;
    }
    public function get_var( $query ) {
        list( $sql, $args ) = $query;
        foreach ( $GLOBALS['posts'] as $id => $post ) {
            if ( false !== strpos( $sql, "p.post_status <> 'trash'" ) && 'trash' === $post['post_status'] ) {
                continue;
            }
            if ( $post['guid'] === $args[0] || ( isset( $post['meta_input']['_ytaibp_video_id'] ) && $post['meta_input']['_ytaibp_video_id'] === $args[1] ) ) {
                return $id;
            }
        }
        return null;
    }
    public function get_col( $query ) { return array_values( array_filter( array_keys( $GLOBALS['options'] ), function ( $name ) { return 0 === strpos( $name, 'ytaibp_video_lock_' ); } ) ); }
    public function esc_like( $value ) { return addcslashes( $value, '_%\\' ); }
}
class TestFeedItem {
    private $entry;
    public function __construct( $entry ) { $this->entry = $entry; }
    public function get_item_tags( $namespace, $name ) {
        $values = array( 'videoId' => $this->entry['id'], 'channelId' => isset( $this->entry['channel'] ) ? $this->entry['channel'] : CHANNEL, 'published' => gmdate( 'c', $this->entry['published'] ) );
        return isset( $values[ $name ] ) ? array( array( 'data' => $values[ $name ] ) ) : null;
    }
    public function get_title() { return 'Feed title'; }
}
class TestFeed {
    public $timeout;
    public $cache_enabled = true;
    private $entries;
    public function __construct( $entries ) { $this->entries = $entries; }
    public function get_items( $start, $count ) { return array_map( function ( $entry ) { return new TestFeedItem( $entry ); }, $this->entries ); }
    public function set_timeout( $value ) { $this->timeout = $value; }
    public function enable_cache( $enabled ) { $this->cache_enabled = $enabled; }
}

function is_wp_error( $value ) { return $value instanceof WP_Error; }
function __( $text, $domain = '' ) { return $text; }
function esc_html__( $text, $domain = '' ) { return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' ); }
function get_option( $name, $default = false ) {
    if ( array_key_exists( $name, $GLOBALS['cache'] ) ) { return $GLOBALS['cache'][ $name ]; }
    $value = array_key_exists( $name, $GLOBALS['options'] ) ? $GLOBALS['options'][ $name ] : $default;
    // Unlike a persistent options cache, missing defaults are not stored here.
    if ( array_key_exists( $name, $GLOBALS['options'] ) ) { $GLOBALS['cache'][ $name ] = $value; }
    return $value;
}
function update_option( $name, $value, $autoload = false ) { $GLOBALS['options'][ $name ] = $value; unset( $GLOBALS['cache'][ $name ] ); return true; }
function add_option( $name, $value, $deprecated = '', $autoload = false ) { if ( array_key_exists( $name, $GLOBALS['options'] ) ) { return false; } return update_option( $name, $value, $autoload ); }
function delete_option( $name ) { unset( $GLOBALS['options'][ $name ], $GLOBALS['cache'][ $name ] ); return true; }
function wp_cache_delete( $name, $group ) { unset( $GLOBALS['cache'][ $name ] ); }
function maybe_serialize( $value ) { return is_array( $value ) ? serialize( $value ) : $value; }
function wp_generate_uuid4() { return 'test-session-' . ++$GLOBALS['uuid']; }
function add_action( $hook, $callback, $priority = 10, $args = 1 ) { $GLOBALS['hooks'][ $hook ][] = $callback; }
function add_filter( $hook, $callback, $priority = 10, $args = 1 ) { add_action( $hook, $callback, $priority, $args ); }
function remove_action( $hook, $callback, $priority = 10 ) { foreach ( isset( $GLOBALS['hooks'][ $hook ] ) ? $GLOBALS['hooks'][ $hook ] : array() as $key => $value ) { if ( $value === $callback ) { unset( $GLOBALS['hooks'][ $hook ][ $key ] ); } } }
function remove_filter( $hook, $callback, $priority = 10 ) { remove_action( $hook, $callback, $priority ); }
function apply_filters( $hook, $value, ...$args ) {
    if ( 'ytaibp_feed_retry_delay' === $hook ) {
        $GLOBALS['retry_delays'][] = $value;
        return 0; // Exercise the real retry loop without sleeping in tests.
    }
    return $value;
}
function register_deactivation_hook( $file, $callback ) { $GLOBALS['deactivate'] = $callback; }
function wp_next_scheduled( $hook ) { return isset( $GLOBALS['cron'][ $hook ] ) ? $GLOBALS['cron'][ $hook ]['time'] : false; }
function wp_schedule_event( $time, $interval, $hook ) { $GLOBALS['cron'][ $hook ] = array( 'time' => $time, 'interval' => $interval ); ++$GLOBALS['schedule_calls']; return true; }
function wp_clear_scheduled_hook( $hook ) { unset( $GLOBALS['cron'][ $hook ] ); }
function get_current_user_id() { return $GLOBALS['current_user']; }
function current_user_can( $capability ) { return $GLOBALS['authorized']; }
function user_can( $id, $capability ) { return in_array( (int) $id, $GLOBALS['authors'], true ); }
function check_admin_referer( $action, $name ) { if ( ! $GLOBALS['nonce'] ) { throw new TestDie( 'Invalid nonce' ); } }
function check_ajax_referer( $action, $name, $die ) { return $GLOBALS['nonce']; }
function wp_die( $message ) { throw new TestDie( $message ); }
function wp_safe_redirect( $url ) { throw new TestRedirect( $url ); }
function wp_send_json_success( $data ) { throw new TestJson( true, $data ); }
function wp_send_json_error( $data, $status = 400 ) { throw new TestJson( false, $data ); }
function admin_url( $path ) { return 'https://example.test/wp-admin/' . $path; }
function home_url( $path ) { return 'https://example.test' . $path; }
function wp_parse_url( $url ) { return parse_url( $url ); }
function wp_unslash( $value ) { return is_array( $value ) ? array_map( 'wp_unslash', $value ) : ( is_string( $value ) ? stripslashes( $value ) : $value ); }
function wp_slash( $value ) { return is_array( $value ) ? array_map( 'wp_slash', $value ) : ( is_string( $value ) ? addslashes( $value ) : $value ); }
function absint( $value ) { return abs( (int) $value ); }
function term_exists( $id, $taxonomy ) { return 1 === (int) $id; }
function sanitize_text_field( $value ) { return trim( strip_tags( $value ) ); }
function sanitize_textarea_field( $value ) { return trim( strip_tags( $value ) ); }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $value ) ); }
function wp_kses_post( $value ) { return $value; }
function wp_strip_all_tags( $value ) { return strip_tags( $value ); }
function esc_url_raw( $value ) { return $value; }
function esc_url( $value ) { return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' ); }
function esc_html( $value ) { return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $value ) { return esc_html( $value ); }
function make_clickable( $value ) { return $value; }
function wp_json_encode( $value ) { return json_encode( $value ); }
function date_i18n( $format, $time ) { return gmdate( $format ? $format : 'Y-m-d', $time ); }
function wp_remote_retrieve_response_code( $response ) { return $response['status']; }
function wp_remote_retrieve_body( $response ) { return $response['body']; }
function fetch_feed( $url ) {
    $GLOBALS['feed_urls'][] = $url;
    if ( isset( $GLOBALS['on_feed'] ) ) { $callback = $GLOBALS['on_feed']; $callback( $url ); }
    $lifetime = 43200;
    foreach ( $GLOBALS['hooks']['wp_feed_cache_transient_lifetime'] as $callback ) { $lifetime = $callback( $lifetime, $url ); }
    $GLOBALS['feed_cache'] = $lifetime;
    $response = $GLOBALS['feed_results'] ? array_shift( $GLOBALS['feed_results'] ) : $GLOBALS['feed'];
    $feed = new TestFeed( is_wp_error( $response ) ? array() : $response );
    foreach ( $GLOBALS['hooks']['wp_feed_options'] as $callback ) { $callback( $feed, $url ); }
    $GLOBALS['feed_configs'][] = array( 'timeout' => $feed->timeout, 'cache' => $feed->cache_enabled );
    if ( is_wp_error( $response ) ) { return $response; }
    return $feed;
}
function wp_remote_post( $url, $args ) {
    $GLOBALS['requests'][] = array( $url, $args );
    if ( isset( $GLOBALS['on_request'] ) ) { $callback = $GLOBALS['on_request']; $callback( $url ); }
    if ( strpos( $url, 'youtube-transcript' ) !== false ) {
        if ( isset( $GLOBALS['transcript_error'] ) ) { return $GLOBALS['transcript_error']; }
        $body = json_decode( $args['body'], true );
        $GLOBALS['requested_video'] = $body['ids'][0];
        $record = array( 'id' => $body['ids'][0], 'title' => 'Video title', 'text' => 'A usable transcript.', 'channelId' => CHANNEL );
        if ( isset( $GLOBALS['provider_thumbnail'] ) ) {
            $record['microformat']['playerMicroformatRenderer']['thumbnail']['thumbnails'] = array( array( 'url' => $GLOBALS['provider_thumbnail'], 'width' => 1280 ) );
        }
        if ( ! empty( $GLOBALS['missing_transcript'] ) ) { $record['text'] = ''; }
        return array( 'status' => 200, 'body' => json_encode( array( $record ) ) );
    }
    return array( 'status' => 200, 'body' => json_encode( array( 'choices' => array( array( 'finish_reason' => 'stop', 'message' => array( 'content' => '<p>Generated summary.</p>' ) ) ) ) ) );
}
function wp_insert_post( $post, $error ) {
    $id = $GLOBALS['next_post_id']++;
    $GLOBALS['posts'][ $id ] = wp_unslash( $post );
    return $id;
}
function get_post_status( $id ) { return $GLOBALS['posts'][ $id ]['post_status']; }
function get_the_title( $id ) { return $GLOBALS['posts'][ $id ]['post_title']; }
function get_post_meta( $id, $key, $single = false ) { return isset( $GLOBALS['post_meta'][ $id ][ $key ] ) ? $GLOBALS['post_meta'][ $id ][ $key ] : ''; }
function update_post_meta( $id, $key, $value ) { $GLOBALS['post_meta'][ $id ][ $key ] = $value; }
function delete_post_meta( $id, $key ) { unset( $GLOBALS['post_meta'][ $id ][ $key ] ); }
function has_post_thumbnail( $id ) { return ! empty( $GLOBALS['thumbnails'][ $id ] ); }
function get_post_thumbnail_id( $id ) { return has_post_thumbnail( $id ) ? $GLOBALS['thumbnails'][ $id ] : 0; }
function set_post_thumbnail( $id, $attachment ) {
    if ( ! empty( $GLOBALS['assign_thumbnail_error'] ) ) { return false; }
    $GLOBALS['thumbnails'][ $id ] = $attachment;
    return true;
}
function sanitize_file_name( $name ) { return $name; }
function wp_generate_attachment_metadata( $id, $file ) { return array(); }
function wp_delete_file( $file ) { if ( file_exists( $file ) ) { unlink( $file ); } }
function wp_delete_attachment( $id, $force = false ) { unset( $GLOBALS['attachments'][ $id ] ); }
function download_url( $url, $timeout ) {
    $GLOBALS['downloads'][] = array( 'url' => $url, 'timeout' => $timeout );
    $filename = basename( parse_url( $url, PHP_URL_PATH ) );
    $result = isset( $GLOBALS['download_results'][ $filename ] ) ? $GLOBALS['download_results'][ $filename ] : new WP_Error( 'http_404', 'Not Found' );
    if ( is_wp_error( $result ) ) { return $result; }
    $file = tempnam( sys_get_temp_dir(), 'ytaibp-image-test-' );
    $GLOBALS['test_files'][] = $file;
    if ( is_array( $result ) ) {
        // A valid GIF with a configurable logical canvas and a single pixel.
        $gif = base64_decode( 'R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==' );
        file_put_contents( $file, substr_replace( $gif, pack( 'vv', $result[0], $result[1] ), 6, 4 ) );
    } else {
        file_put_contents( $file, $result );
    }
    return $file;
}
function media_handle_sideload( $file, $parent, $title ) {
    if ( isset( $GLOBALS['media_error'] ) ) { return $GLOBALS['media_error']; }
    $id = count( $GLOBALS['attachments'] ) + 100;
    $GLOBALS['attachments'][ $id ] = array( 'parent' => $parent, 'file' => $file['name'] );
    wp_delete_file( $file['tmp_name'] ); // Emulate WordPress moving the temp file.
    return $id;
}
function get_preview_post_link( $id ) { return 'https://example.test/?p=' . $id . '&preview=true'; }
function get_edit_post_link( $id, $context ) { return admin_url( 'post.php?post=' . $id . '&action=edit' ); }

define( 'CHANNEL', 'UC' . str_repeat( 'a', 22 ) );
function reset_fixture() {
    foreach ( array( 'options', 'cache', 'cron', 'posts', 'hooks', 'requests', 'feed_urls', 'post_meta', 'thumbnails', 'attachments', 'downloads', 'download_results', 'test_files', 'feed_results', 'feed_configs', 'retry_delays' ) as $key ) { $GLOBALS[ $key ] = array(); }
    foreach ( array( 'before_cas', 'on_request', 'on_feed', 'transcript_error', 'missing_transcript', 'provider_thumbnail', 'media_error', 'assign_thumbnail_error' ) as $key ) { unset( $GLOBALS[ $key ] ); }
    $GLOBALS['uuid'] = 0;
    $GLOBALS['schedule_calls'] = 0;
    $GLOBALS['next_post_id'] = 1;
    $GLOBALS['current_user'] = 1;
    $GLOBALS['authors'] = array( 1 );
    $GLOBALS['authorized'] = true;
    $GLOBALS['nonce'] = true;
    $GLOBALS['feed'] = array();
    $GLOBALS['wpdb'] = new TestDatabase();
    $_POST = array();
    foreach ( array( 'channel_id' => CHANNEL, 'post_author' => 1, 'transcript_api_key' => 'transcript-secret', 'openai_api_key' => 'openai-secret' ) as $key => $value ) { update_option( 'ytaibp_' . $key, $value ); }
    return new Ytaibp_Main();
}
function assert_true( $condition, $message ) { if ( ! $condition ) { throw new RuntimeException( $message ); } }
function state() { return get_option( 'ytaibp_automation_state', array() ); }
function enable( $app ) {
    update_option( 'ytaibp_plugin_enabled', '1' );
    $app->schedule_cron();
    $state = state();
    $state['started_at'] = time() - 100;
    update_option( 'ytaibp_automation_state', $state );
}
function entry( $id, $offset = -10 ) { return array( 'id' => $id, 'published' => time() + $offset ); }
function save_settings( $app, $values ) {
    $_POST = $values;
    try { $app->save_settings(); } catch ( TestRedirect $result ) { return; }
    throw new RuntimeException( 'Expected settings redirect' );
}
function manual( $app, $id ) {
    $_POST = array( 'ytaibp_video_url' => 'https://www.youtube.com/watch?v=' . $id );
    try { $app->create_draft(); } catch ( TestJson $result ) { return $result; }
    throw new RuntimeException( 'Expected AJAX result' );
}

// Loading registers hooks only; each scenario creates a fresh instance and state.
$GLOBALS['hooks'] = array();
require dirname( __DIR__ ) . '/youtube-to-ai-blog-post.php';
$tests = array();
$tests['schedule once; disable resets only plugin work'] = function () {
    $app = reset_fixture(); enable( $app ); $session = state()['session'];
    $app->schedule_cron();
    assert_true( 1 === $GLOBALS['schedule_calls'] && 'hourly' === $GLOBALS['cron']['ytaibp_check_youtube']['interval'], 'Expected one hourly event' );
    assert_true( $session === state()['session'], 'Repeated scheduling reset the session' );
    $GLOBALS['cron']['other_plugin'] = array( 'time' => time() );
    update_option( 'ytaibp_plugin_enabled', '0' ); $app->schedule_cron();
    assert_true( ! state() && ! wp_next_scheduled( 'ytaibp_check_youtube' ) && wp_next_scheduled( 'other_plugin' ), 'Disable cleanup affected unrelated work or retained state' );
};
$tests['future only; latest only; no backfill or repeated requests'] = function () {
    $app = reset_fixture(); enable( $app );
    $GLOBALS['feed'] = array( entry( 'old_video_1', -200 ), entry( 'new_video_1', -20 ), entry( 'new_video_2', -10 ) );
    $GLOBALS['current_user'] = 0; // Cron has no logged-in author.
    $app->check_youtube();
    assert_true( 1 === count( $GLOBALS['posts'] ) && 'new_video_2' === $GLOBALS['requested_video'], 'Did not select newest future upload' );
    assert_true( 'draft' === $GLOBALS['posts'][1]['post_status'] && 1 === $GLOBALS['posts'][1]['post_author'], 'Draft status or explicit author lost' );
    assert_true( 300 === $GLOBALS['feed_cache'] && empty( $GLOBALS['hooks']['wp_feed_cache_transient_lifetime'] ), 'Feed caching was not scoped and cleaned up' );
    $app->check_youtube();
    assert_true( 2 === count( $GLOBALS['requests'] ) && 1 === count( $GLOBALS['posts'] ), 'Repeated feed triggered paid calls or older backfill' );
};
$tests['existing uploads and empty feed do not generate drafts'] = function () {
    $app = reset_fixture(); enable( $app ); $GLOBALS['feed'] = array( entry( 'old_video_1', -200 ) );
    $app->check_youtube(); $GLOBALS['feed'] = array(); $app->check_youtube();
    assert_true( ! $GLOBALS['requests'] && state()['last_success'], 'Baseline/empty-feed behavior is incorrect' );
};
$tests['missing transcript retries and then recovers'] = function () {
    $app = reset_fixture(); enable( $app ); $GLOBALS['feed'] = array( entry( 'new_video_1' ) ); $GLOBALS['missing_transcript'] = true;
    $app->check_youtube();
    assert_true( 1 === state()['pending']['attempts'] && ! $GLOBALS['posts'], 'Missing transcript was not retained for retry' );
    $app->check_youtube(); assert_true( 1 === count( $GLOBALS['requests'] ), 'Retried before backoff elapsed' );
    $state = state(); $state['pending']['next_attempt'] = 0; update_option( 'ytaibp_automation_state', $state ); unset( $GLOBALS['missing_transcript'] );
    $app->check_youtube();
    assert_true( ! state()['pending'] && 1 === count( $GLOBALS['posts'] ), 'Transcript retry did not recover' );
};
$tests['retry exhaustion and credentials errors stop paid retries'] = function () {
    $app = reset_fixture(); enable( $app ); $GLOBALS['feed'] = array( entry( 'new_video_1' ) ); $GLOBALS['missing_transcript'] = true;
    for ( $i = 0; $i < 6; ++$i ) {
        $state = state(); if ( $state['pending'] ) { $state['pending']['next_attempt'] = 0; update_option( 'ytaibp_automation_state', $state ); } $app->check_youtube();
    }
    $app->check_youtube();
    assert_true( ! state()['pending'] && 6 === count( $GLOBALS['requests'] ), 'Retry limit not enforced' );
    $app = reset_fixture(); enable( $app ); $GLOBALS['feed'] = array( entry( 'new_video_1' ) );
    $GLOBALS['transcript_error'] = array( 'status' => 401, 'body' => json_encode( array( 'error' => array( 'message' => 'Invalid transcript-secret' ) ) ) );
    $app->check_youtube(); $app->check_youtube();
    assert_true( ! state()['pending'] && 1 === count( $GLOBALS['requests'] ), '401 was retried' );
    assert_true( false === strpos( serialize( state() ), 'transcript-secret' ), 'Credential leaked into activity' );
};
$tests['feed failure retains pending work and history'] = function () {
    $app = reset_fixture(); enable( $app ); $GLOBALS['feed'] = array( entry( 'new_video_1' ) ); $GLOBALS['missing_transcript'] = true; $app->check_youtube();
    $seen = state()['seen']; $GLOBALS['feed'] = new WP_Error( 'network', 'Feed unavailable' ); $app->check_youtube();
    assert_true( $seen === state()['seen'] && state()['pending'] && state()['error'], 'Feed error destroyed pending state' );
};
$tests['newer upload replaces pending video'] = function () {
    $app = reset_fixture(); enable( $app ); $GLOBALS['feed'] = array( entry( 'new_video_1' ) ); $GLOBALS['missing_transcript'] = true; $app->check_youtube();
    $GLOBALS['feed'] = array( entry( 'new_video_1', -20 ), entry( 'new_video_2' ) ); unset( $GLOBALS['missing_transcript'] ); $app->check_youtube();
    assert_true( 'new_video_2' === $GLOBALS['requested_video'] && 1 === count( $GLOBALS['posts'] ), 'Older pending upload was processed instead of latest' );
};
$tests['overlapping worker and video claims prevent duplicate requests'] = function () {
    $app = reset_fixture(); enable( $app ); $GLOBALS['feed'] = array( entry( 'new_video_1' ) );
    update_option( 'ytaibp_poll_lock', 'other-owner|' . ( time() + 300 ) ); $app->check_youtube();
    assert_true( ! $GLOBALS['feed_urls'], 'Poll ran while another worker owned the lock' );
    update_option( 'ytaibp_poll_lock', 'expired|' . ( time() - 1 ) );
    update_option( 'ytaibp_video_lock_new_video_1', 'other-owner|' . ( time() + 300 ) ); $app->check_youtube();
    assert_true( ! $GLOBALS['requests'] && state()['pending'], 'Concurrent video owner was ignored' );
};
$tests['disable during transcript or summary prevents saving'] = function () {
    foreach ( array( 'youtube-transcript', 'api.openai' ) as $stage ) {
        $app = reset_fixture(); enable( $app ); $GLOBALS['feed'] = array( entry( 'new_video_1' ) );
        $GLOBALS['on_request'] = function ( $url ) use ( $app, $stage ) {
            if ( false !== strpos( $url, $stage ) ) { $GLOBALS['options']['ytaibp_plugin_enabled'] = '0'; unset( $GLOBALS['options']['ytaibp_automation_state'] ); }
        };
        $app->check_youtube();
        assert_true( ! $GLOBALS['posts'] && ! state(), 'Canceled session created a post or revived monitoring' );
    }
};
$tests['settings validation; save preserves session; channel change resets'] = function () {
    $app = reset_fixture();
    save_settings( $app, array( 'ytaibp_plugin_enabled' => '1', 'ytaibp_channel_id' => 'https://www.youtube.com/channel/' . CHANNEL ) );
    $session = state()['session'];
    save_settings( $app, array( 'ytaibp_plugin_enabled' => '1', 'ytaibp_channel_id' => CHANNEL, 'ytaibp_summary_prompt' => 'Updated prompt' ) );
    assert_true( $session === state()['session'], 'Ordinary settings save reset monitoring' );
    try { save_settings( $app, array( 'ytaibp_plugin_enabled' => '1', 'ytaibp_channel_id' => 'https://evil.test/channel/' . CHANNEL, 'ytaibp_openai_api_key' => 'changed' ) ); throw new RuntimeException( 'Accepted invalid channel' ); } catch ( TestDie $expected ) {}
    assert_true( 'openai-secret' === get_option( 'ytaibp_openai_api_key' ), 'Invalid settings partially saved' );
    save_settings( $app, array( 'ytaibp_plugin_enabled' => '1', 'ytaibp_channel_id' => 'UC' . str_repeat( 'b', 22 ) ) );
    assert_true( $session !== state()['session'], 'Channel change did not reset monitoring' );
    save_settings( $app, array( 'ytaibp_channel_id' => CHANNEL ) ); assert_true( ! state() && ! wp_next_scheduled( 'ytaibp_check_youtube' ), 'Unchecked setting did not stop imports' );
};
$tests['manual imports share duplicate protection and source recovery'] = function () {
    $app = reset_fixture(); assert_true( manual( $app, 'new_video_1' )->success, 'Manual generation failed' );
    $GLOBALS['posts'][1]['post_status'] = 'publish'; unset( $GLOBALS['posts'][1]['meta_input'] ); // Simulate a crash before metadata insertion.
    assert_true( manual( $app, 'new_video_1' )->success && 2 === count( $GLOBALS['requests'] ), 'GUID recovery duplicated provider calls' );
    enable( $app ); $GLOBALS['feed'] = array( entry( 'new_video_1' ) ); $app->check_youtube();
    assert_true( 1 === count( $GLOBALS['posts'] ) && 2 === count( $GLOBALS['requests'] ), 'Cron duplicated a manual post' );
};
$tests['trash permits regeneration and active replacement remains deduplicated'] = function () {
    $app = reset_fixture(); assert_true( manual( $app, 'new_video_1' )->success, 'Initial generation failed' );
    $GLOBALS['posts'][1]['post_status'] = 'trash';
    $result = manual( $app, 'new_video_1' );
    assert_true( $result->success && 2 === count( $GLOBALS['posts'] ) && 4 === count( $GLOBALS['requests'] ), 'Trashed post blocked fresh generation' );
    assert_true( 'trash' === $GLOBALS['posts'][1]['post_status'] && 'draft' === $GLOBALS['posts'][2]['post_status'], 'Regeneration modified the trashed post' );
    $replacement_url = get_edit_post_link( 2, 'raw' );
    assert_true( $replacement_url === $result->data['edit_url'] && $replacement_url === manual( $app, 'new_video_1' )->data['edit_url'], 'Duplicate lookup selected the trashed original instead of the replacement' );
    enable( $app ); $GLOBALS['feed'] = array( entry( 'new_video_1' ) ); $app->check_youtube();
    assert_true( 2 === count( $GLOBALS['posts'] ) && 4 === count( $GLOBALS['requests'] ), 'Cron duplicated the active replacement' );
};
$tests['permanent deletion permits generation even with older trashed matches'] = function () {
    $app = reset_fixture(); manual( $app, 'new_video_1' );
    unset( $GLOBALS['posts'][1] );
    assert_true( manual( $app, 'new_video_1' )->success && isset( $GLOBALS['posts'][2] ), 'Permanent deletion blocked generation' );
    $GLOBALS['posts'][2]['post_status'] = 'trash';
    assert_true( manual( $app, 'new_video_1' )->success, 'Second regeneration failed' );
    unset( $GLOBALS['posts'][3] );
    assert_true( manual( $app, 'new_video_1' )->success && isset( $GLOBALS['posts'][4] ), 'An older trashed match blocked generation after permanent deletion' );
};
$tests['cron ignores a trashed source post'] = function () {
    $app = reset_fixture(); manual( $app, 'new_video_1' ); $GLOBALS['posts'][1]['post_status'] = 'trash';
    enable( $app ); $GLOBALS['feed'] = array( entry( 'new_video_1' ) ); $app->check_youtube();
    assert_true( 2 === count( $GLOBALS['posts'] ) && 'draft' === $GLOBALS['posts'][2]['post_status'] && ! state()['pending'], 'Trashed source post blocked automatic generation' );
};
$tests['invalid author and permissions prevent provider calls'] = function () {
    $app = reset_fixture(); enable( $app ); $GLOBALS['authors'] = array(); $GLOBALS['feed'] = array( entry( 'new_video_1' ) ); $app->check_youtube();
    assert_true( ! $GLOBALS['requests'] && ! state()['pending'], 'Invalid author triggered providers or retries' );
    $app = reset_fixture(); $GLOBALS['authorized'] = false; assert_true( ! manual( $app, 'new_video_1' )->success, 'Unauthorized AJAX allowed' );
    $GLOBALS['authorized'] = true; $GLOBALS['nonce'] = false; assert_true( ! manual( $app, 'new_video_1' )->success && ! $GLOBALS['requests'], 'Invalid nonce allowed' );
};
$tests['reset racing a state write cannot revive monitoring'] = function () {
    $app = reset_fixture(); enable( $app );
    $GLOBALS['before_cas'] = function () { unset( $GLOBALS['options']['ytaibp_automation_state'] ); $GLOBALS['options']['ytaibp_plugin_enabled'] = '0'; };
    $app->check_youtube(); assert_true( ! state() && ! $GLOBALS['posts'], 'Compare-and-swap revived a canceled session' );
};
$tests['deactivation and uninstall preserve posts and unrelated options'] = function () {
    $app = reset_fixture(); manual( $app, 'new_video_1' ); enable( $app ); call_user_func( $GLOBALS['deactivate'] );
    assert_true( ! state() && ! wp_next_scheduled( 'ytaibp_check_youtube' ) && $GLOBALS['posts'], 'Deactivation cleanup failed' );
    $app->schedule_cron(); update_option( 'unrelated_option', 'keep' ); update_option( 'ytaibp_video_lock_abandoned01', 'expired|0' );
    define( 'WP_UNINSTALL_PLUGIN', true ); require dirname( __DIR__ ) . '/uninstall.php';
    assert_true( array( 'unrelated_option' ) === array_keys( $GLOBALS['options'] ) && $GLOBALS['posts'] && ! wp_next_scheduled( 'ytaibp_check_youtube' ), 'Uninstall removed content/unrelated data or retained plugin state' );
};

$tests['future timestamps remain eligible; mismatched channel fails safely'] = function () {
    $app = reset_fixture(); enable( $app ); $GLOBALS['feed'] = array( entry( 'new_video_1', 100 ) ); $app->check_youtube();
    assert_true( ! $GLOBALS['requests'] && ! in_array( 'new_video_1', state()['seen'], true ), 'Upcoming video was imported or permanently skipped' );
    $GLOBALS['feed'] = array( entry( 'new_video_1' ) ); $app->check_youtube();
    assert_true( 1 === count( $GLOBALS['posts'] ), 'Video was not eligible after publication' );
    $app = reset_fixture(); enable( $app ); $GLOBALS['feed'] = array( array_merge( entry( 'new_video_1' ), array( 'channel' => 'UC' . str_repeat( 'b', 22 ) ) ) ); $app->check_youtube();
    assert_true( ! $GLOBALS['requests'] && state()['error'] && ! state()['seen'], 'Mismatched feed channel was trusted' );
};
$tests['re-enabling skips stopped-period uploads and does not resume old jobs'] = function () {
    $app = reset_fixture(); enable( $app ); $GLOBALS['missing_transcript'] = true; $GLOBALS['feed'] = array( entry( 'new_video_1' ) ); $app->check_youtube();
    save_settings( $app, array( 'ytaibp_channel_id' => CHANNEL ) );
    save_settings( $app, array( 'ytaibp_plugin_enabled' => '1', 'ytaibp_channel_id' => CHANNEL ) );
    unset( $GLOBALS['missing_transcript'] ); $app->check_youtube();
    assert_true( ! state()['pending'] && ! $GLOBALS['posts'] && 1 === count( $GLOBALS['requests'] ), 'Restart resumed old work or imported stopped-period video' );
};
$tests['author removed during provider work cannot become anonymous author'] = function () {
    $app = reset_fixture(); enable( $app ); $GLOBALS['feed'] = array( entry( 'new_video_1' ) );
    $GLOBALS['on_request'] = function ( $url ) { if ( false !== strpos( $url, 'api.openai' ) ) { $GLOBALS['authors'] = array(); } };
    $app->check_youtube(); assert_true( ! $GLOBALS['posts'] && ! state()['pending'], 'Draft was assigned to an invalid/anonymous author' );
};

$tests['missing metadata uses direct CDN and repairs existing draft without AI'] = function () {
    $app = reset_fixture();
    $result = manual( $app, 'new_video_1' );
    assert_true( $result->success && $result->data['thumbnail_warning'] && ! has_post_thumbnail( 1 ), 'Thumbnail failure was hidden or blocked draft creation' );
    $GLOBALS['download_results']['maxresdefault.jpg'] = array( 1280, 720 );
    $result = manual( $app, 'new_video_1' );
    assert_true( $result->success && has_post_thumbnail( 1 ) && '' === $result->data['thumbnail_warning'], 'Existing draft thumbnail was not repaired' );
    assert_true( 1 === count( $GLOBALS['posts'] ) && 2 === count( $GLOBALS['requests'] ), 'Thumbnail repair regenerated the post or called paid providers' );
    $downloads = count( $GLOBALS['downloads'] ); manual( $app, 'new_video_1' );
    assert_true( $downloads === count( $GLOBALS['downloads'] ) && 1 === count( $GLOBALS['attachments'] ), 'Existing featured image was replaced or downloaded again' );
};
$tests['unavailable high resolution falls back to standard image'] = function () {
    $app = reset_fixture(); $GLOBALS['download_results']['hqdefault.jpg'] = array( 480, 360 );
    $result = manual( $app, 'new_video_1' );
    assert_true( $result->success && has_post_thumbnail( 1 ) && '' === $result->data['thumbnail_warning'], '404 fallback failed' );
    assert_true( 'https://i.ytimg.com/vi/new_video_1/maxresdefault.jpg' === $GLOBALS['downloads'][0]['url'] && false !== strpos( $GLOBALS['downloads'][1]['url'], '/hqdefault.jpg' ), 'CDN URL order is wrong' );
    foreach ( $GLOBALS['downloads'] as $download ) { assert_true( $download['timeout'] <= 5, 'Image request exceeds bounded timeout' ); }
};
$tests['placeholder and non-image responses fall back and clean temporary files'] = function () {
    foreach ( array( array( 120, 90 ), '<html>Not an image</html>' ) as $invalid ) {
        $app = reset_fixture(); $GLOBALS['download_results']['maxresdefault.jpg'] = $invalid; $GLOBALS['download_results']['hqdefault.jpg'] = array( 480, 360 );
        $result = manual( $app, 'new_video_1' );
        assert_true( $result->success && has_post_thumbnail( 1 ) && 2 === count( $GLOBALS['downloads'] ), 'Invalid image prevented usable fallback' );
        foreach ( $GLOBALS['test_files'] as $file ) { assert_true( ! file_exists( $file ), 'Thumbnail temp file leaked' ); }
    }
};
$tests['untrusted provider URL is skipped while safe CDN fallback succeeds'] = function () {
    $app = reset_fixture(); $GLOBALS['provider_thumbnail'] = 'https://evil.test/private.jpg'; $GLOBALS['download_results']['maxresdefault.jpg'] = array( 1280, 720 );
    manual( $app, 'new_video_1' );
    assert_true( has_post_thumbnail( 1 ) && 1 === count( $GLOBALS['downloads'] ) && false === strpos( $GLOBALS['downloads'][0]['url'], 'evil.test' ), 'Untrusted URL was downloaded or safe fallback skipped' );
};
$tests['valid provider metadata remains supported with correct file extension'] = function () {
    $app = reset_fixture(); $GLOBALS['provider_thumbnail'] = 'https://i.ytimg.com/vi/new_video_1/custom.jpg'; $GLOBALS['download_results']['custom.jpg'] = array( 640, 480 );
    manual( $app, 'new_video_1' );
    assert_true( has_post_thumbnail( 1 ) && 1 === count( $GLOBALS['downloads'] ) && 'new_video_1.gif' === $GLOBALS['attachments'][100]['file'], 'Provider image or detected image extension was lost' );
};
$tests['media save and assignment errors are visible and nonfatal'] = function () {
    foreach ( array( 'media', 'assignment' ) as $stage ) {
        $app = reset_fixture(); $GLOBALS['download_results']['maxresdefault.jpg'] = array( 1280, 720 );
        if ( 'media' === $stage ) { $GLOBALS['media_error'] = new WP_Error( 'upload', 'Uploads directory is not writable' ); } else { $GLOBALS['assign_thumbnail_error'] = true; }
        $result = manual( $app, 'new_video_1' );
        assert_true( $result->success && ! has_post_thumbnail( 1 ) && $result->data['thumbnail_warning'], 'Media failure was fatal or invisible' );
        assert_true( ! $GLOBALS['attachments'], 'Failed featured image left an unused attachment' );
        foreach ( $GLOBALS['test_files'] as $file ) { assert_true( ! file_exists( $file ), 'Failed media upload leaked a temp file' ); }
    }
};
$tests['cron activity reports thumbnail failure and still completes the draft'] = function () {
    $app = reset_fixture(); enable( $app ); $GLOBALS['feed'] = array( entry( 'new_video_1' ) ); $app->check_youtube();
    assert_true( 1 === count( $GLOBALS['posts'] ) && ! state()['pending'] && false !== strpos( state()['activity'][0]['message'], 'without a featured image' ), 'Cron hid the thumbnail failure or retried paid generation' );
};

$tests['feed recovers on fifth attempt with fresh retry requests'] = function () {
    $app = reset_fixture(); enable( $app );
    $GLOBALS['feed_results'] = array(
        new WP_Error( 'http', 'HTTP 404' ), new WP_Error( 'http', 'HTTP 503' ),
        new WP_Error( 'network', 'Connection timed out' ), new WP_Error( 'http', 'HTTP 404' ),
        array( entry( 'new_video_1' ) ),
    );
    $app->check_youtube();
    assert_true( 5 === count( $GLOBALS['feed_urls'] ) && 1 === count( $GLOBALS['posts'] ) && ! state()['error'], 'Feed did not recover on fifth attempt' );
    assert_true( array( 1, 2, 4, 8 ) === $GLOBALS['retry_delays'], 'Retry backoff is incorrect' );
    assert_true( true === $GLOBALS['feed_configs'][0]['cache'], 'Normal feed cache was disabled' );
    foreach ( array_slice( $GLOBALS['feed_configs'], 1 ) as $config ) {
        assert_true( false === $config['cache'] && 5 === $config['timeout'], 'Retries reused cache or had unbounded timeouts' );
    }
    assert_true( empty( $GLOBALS['hooks']['wp_feed_options'] ) && empty( $GLOBALS['hooks']['wp_feed_cache_transient_lifetime'] ), 'Retry filters leaked' );
};
$tests['five feed failures preserve state and reset the budget next hour'] = function () {
    $app = reset_fixture(); enable( $app ); $initial = state(); $initial['seen'] = array( 'old_video_1' ); $initial['last_success'] = 123; update_option( 'ytaibp_automation_state', $initial );
    $GLOBALS['feed'] = new WP_Error( 'http', 'HTTP 404' ); $app->check_youtube();
    assert_true( 5 === count( $GLOBALS['feed_urls'] ) && false !== strpos( state()['error'], '5 attempts' ) && false !== strpos( state()['error'], 'HTTP 404' ), 'Exhaustion count or error details missing' );
    assert_true( $initial['session'] === state()['session'] && $initial['seen'] === state()['seen'] && 123 === state()['last_success'] && wp_next_scheduled( 'ytaibp_check_youtube' ), 'Feed failures reset monitoring or cron' );
    assert_true( ! $GLOBALS['requests'] && ! $GLOBALS['posts'], 'Failed discovery triggered paid requests' );
    $app->check_youtube(); assert_true( 10 === count( $GLOBALS['feed_urls'] ), 'Next hourly invocation did not get five fresh attempts' );
    $GLOBALS['feed'] = array( entry( 'new_video_1' ) ); $app->check_youtube();
    assert_true( 11 === count( $GLOBALS['feed_urls'] ) && 1 === count( $GLOBALS['posts'] ) && ! state()['error'] && true === $GLOBALS['feed_configs'][10]['cache'], 'Later recovery failed or retry cache setting leaked' );
};
$tests['pending generation continues after feed retries are exhausted'] = function () {
    $app = reset_fixture(); enable( $app ); $GLOBALS['feed'] = array( entry( 'new_video_1' ) ); $GLOBALS['missing_transcript'] = true; $app->check_youtube();
    $state = state(); $state['pending']['next_attempt'] = 0; update_option( 'ytaibp_automation_state', $state );
    unset( $GLOBALS['missing_transcript'] ); $GLOBALS['feed'] = new WP_Error( 'http', 'HTTP 500' ); $app->check_youtube();
    assert_true( 6 === count( $GLOBALS['feed_urls'] ) && 1 === count( $GLOBALS['posts'] ) && ! state()['pending'] && state()['error'], 'Feed exhaustion discarded or blocked pending work' );
};
$tests['disabling during a failed feed request stops further attempts'] = function () {
    $app = reset_fixture(); enable( $app ); $GLOBALS['feed'] = new WP_Error( 'http', 'HTTP 404' );
    $GLOBALS['on_feed'] = function ( $url ) use ( $app ) { update_option( 'ytaibp_plugin_enabled', '0' ); $app->stop_automation(); };
    $app->check_youtube();
    assert_true( 1 === count( $GLOBALS['feed_urls'] ) && ! state() && ! $GLOBALS['requests'] && empty( $GLOBALS['hooks']['wp_feed_options'] ), 'Disabled monitor kept retrying or revived state' );
};

$failed = 0;
foreach ( $tests as $name => $test ) {
    try { $test(); echo 'PASS ' . $name . PHP_EOL; }
    catch ( Throwable $error ) { ++$failed; fwrite( STDERR, 'FAIL ' . $name . ': ' . $error->getMessage() . ' (' . $error->getFile() . ':' . $error->getLine() . ')' . PHP_EOL ); }
}
echo count( $tests ) . ' scenarios, ' . $failed . ' failed.' . PHP_EOL;
exit( $failed ? 1 : 0 );
