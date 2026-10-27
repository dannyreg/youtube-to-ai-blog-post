<?php
/** Native WP-Cron channel monitoring. No external scheduler is required. */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

trait Ytaibp_Automation {

    private $feed_retrying = false;

    private function channel_id_from_input( $input ) {
        if ( preg_match( '/^UC[A-Za-z0-9_-]{22}$/D', $input ) ) {
            return $input;
        }
        $parts = wp_parse_url( $input );
        if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || 'https' !== strtolower( $parts['scheme'] ) || empty( $parts['host'] ) || ! in_array( strtolower( $parts['host'] ), array( 'youtube.com', 'www.youtube.com', 'm.youtube.com' ), true ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['port'] ) || isset( $parts['query'] ) || isset( $parts['fragment'] ) ) {
            return false;
        }
        return isset( $parts['path'] ) && preg_match( '#^/channel/(UC[A-Za-z0-9_-]{22})/?$#D', $parts['path'], $matches ) ? $matches[1] : false;
    }

    public function schedule_cron() {
        if ( '1' === get_option( $this->prefix . 'plugin_enabled', '0' ) && $this->channel_id_from_input( get_option( $this->prefix . 'channel_id', '' ) ) ) {
            if ( ! get_option( $this->prefix . 'automation_state' ) ) {
                add_option( $this->prefix . 'automation_state', array(
                    'session' => wp_generate_uuid4(),
                    'started_at' => time(),
                    'seen' => array(),
                    'pending' => array(),
                    'activity' => array(),
                    'last_check' => 0,
                    'last_success' => 0,
                    'error' => '',
                ), '', false );
            }
            if ( ! wp_next_scheduled( 'ytaibp_check_youtube' ) ) {
                wp_schedule_event( time(), 'hourly', 'ytaibp_check_youtube' );
            }
        } else {
            $this->stop_automation();
        }
    }

    public function stop_automation() {
        wp_clear_scheduled_hook( 'ytaibp_check_youtube' );
        // Deleting the session also invalidates a worker already awaiting an API response.
        delete_option( $this->prefix . 'automation_state' );
    }

    private function automation_is_current( $session ) {
        // Another request may disable or replace the channel while HTTP is in flight.
        wp_cache_delete( $this->prefix . 'automation_state', 'options' );
        wp_cache_delete( $this->prefix . 'plugin_enabled', 'options' );
        $state = get_option( $this->prefix . 'automation_state', array() );
        return '1' === get_option( $this->prefix . 'plugin_enabled', '0' ) && isset( $state['session'] ) && $session === $state['session'];
    }

    private function save_automation_state( $state ) {
        if ( $this->automation_is_current( $state['session'] ) ) {
            global $wpdb;
            $current = get_option( $this->prefix . 'automation_state' );
            if ( ! is_array( $current ) || ! isset( $current['session'] ) || $state['session'] !== $current['session'] ) {
                return false;
            }
            if ( $current === $state ) {
                return true;
            }
            // Compare-and-swap prevents a reset racing this write from reviving a session.
            $updated = $wpdb->query( $wpdb->prepare(
                "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
                maybe_serialize( $state ), $this->prefix . 'automation_state', maybe_serialize( $current )
            ) );
            wp_cache_delete( $this->prefix . 'automation_state', 'options' );
            return 1 === $updated;
        }
        return false;
    }

    /** Atomic option insertion, with compare-and-delete for expired/released leases. */
    private function acquire_lock( $name ) {
        $value = wp_generate_uuid4() . '|' . ( time() + 300 );
        if ( add_option( $name, $value, '', false ) ) {
            return $value;
        }
        $old = get_option( $name, '' );
        $parts = explode( '|', (string) $old );
        if ( 2 === count( $parts ) && (int) $parts[1] < time() ) {
            $this->release_lock( $name, $old );
            if ( add_option( $name, $value, '', false ) ) {
                return $value;
            }
        }
        return false;
    }

    private function release_lock( $name, $value ) {
        global $wpdb;
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $name, $value ) );
        wp_cache_delete( $name, 'options' );
    }

    public function feed_cache_lifetime( $lifetime, $url ) {
        $expected = 'https://www.youtube.com/feeds/videos.xml?channel_id=' . get_option( $this->prefix . 'channel_id', '' );
        return $url === $expected ? 300 : $lifetime;
    }

    public function feed_options( $feed, $url ) {
        $expected = 'https://www.youtube.com/feeds/videos.xml?channel_id=' . get_option( $this->prefix . 'channel_id', '' );
        if ( $url === $expected ) {
            $feed->set_timeout( 5 );
            if ( $this->feed_retrying ) {
                // A retry must reach YouTube instead of reusing a cached failure.
                $feed->enable_cache( false );
            }
        }
    }

    private function fetch_channel_entries( $channel_id, $session ) {
        require_once ABSPATH . WPINC . '/feed.php';
        $url = 'https://www.youtube.com/feeds/videos.xml?channel_id=' . $channel_id;
        $deadline = microtime( true ) + 45;
        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 50 );
        }
        $attempts = 0;
        $feed = new WP_Error( 'ytaibp_feed_failed', __( 'The feed request did not complete.', 'youtube-to-ai-blog-post' ) );
        add_filter( 'wp_feed_cache_transient_lifetime', array( $this, 'feed_cache_lifetime' ), 10, 2 );
        add_action( 'wp_feed_options', array( $this, 'feed_options' ), 10, 2 );
        try {
            for ( $attempt = 1; $attempt <= 5; ++$attempt ) {
                if ( ! $this->automation_is_current( $session ) ) {
                    return new WP_Error( 'ytaibp_import_stopped', __( 'Automatic imports were stopped.', 'youtube-to-ai-blog-post' ) );
                }
                if ( microtime( true ) >= $deadline ) {
                    break;
                }
                $this->feed_retrying = $attempt > 1;
                ++$attempts;
                $feed = fetch_feed( $url );
                if ( ! is_wp_error( $feed ) || 5 === $attempt ) {
                    break;
                }
                // Short bounded pauses, never a tight loop of refresh requests.
                $delay = min( 8, max( 0, (int) apply_filters( 'ytaibp_feed_retry_delay', pow( 2, $attempt - 1 ), $attempt ) ) );
                if ( microtime( true ) + $delay + 5 > $deadline ) {
                    break;
                }
                if ( $delay ) {
                    sleep( $delay );
                }
            }
        } finally {
            $this->feed_retrying = false;
            remove_filter( 'wp_feed_cache_transient_lifetime', array( $this, 'feed_cache_lifetime' ), 10 );
            remove_action( 'wp_feed_options', array( $this, 'feed_options' ), 10 );
        }
        if ( is_wp_error( $feed ) ) {
            return new WP_Error( 'ytaibp_feed_failed', sprintf(
                __( 'Could not read the YouTube channel feed after %1$d attempts. Last error: %2$s. The next hourly check will try again.', 'youtube-to-ai-blog-post' ),
                $attempts, $this->redact_diagnostic( $feed->get_error_message() )
            ), array( 'attempts' => $attempts ) );
        }
        $entries = array();
        foreach ( $feed->get_items( 0, 100 ) as $item ) {
            $ids = $item->get_item_tags( 'http://www.youtube.com/xml/schemas/2015', 'videoId' );
            $channels = $item->get_item_tags( 'http://www.youtube.com/xml/schemas/2015', 'channelId' );
            $published = $item->get_item_tags( 'http://www.w3.org/2005/Atom', 'published' );
            $id = isset( $ids[0]['data'] ) ? $ids[0]['data'] : '';
            $date = isset( $published[0]['data'] ) ? strtotime( $published[0]['data'] ) : false;
            if ( ! preg_match( '/^[A-Za-z0-9_-]{11}$/D', $id ) || ! isset( $channels[0]['data'] ) || $channel_id !== $channels[0]['data'] || false === $date ) {
                return new WP_Error( 'ytaibp_invalid_feed', __( 'The channel feed contains invalid video metadata. No videos were imported.', 'youtube-to-ai-blog-post' ) );
            }
            $entries[] = array( 'id' => $id, 'published' => $date, 'title' => sanitize_text_field( $item->get_title() ) );
        }
        usort( $entries, function ( $a, $b ) { return $b['published'] <=> $a['published']; } );
        return $entries;
    }

    private function automation_activity( &$state, $id, $message, $post_id = 0 ) {
        array_unshift( $state['activity'], array( 'time' => time(), 'video_id' => $id, 'message' => $message, 'post_id' => $post_id ) );
        $state['activity'] = array_slice( $state['activity'], 0, 10 );
    }

    public function check_youtube() {
        $state = get_option( $this->prefix . 'automation_state', array() );
        if ( empty( $state['session'] ) || ! $this->automation_is_current( $state['session'] ) ) {
            return;
        }
        $lock_name = $this->prefix . 'poll_lock';
        $lock = $this->acquire_lock( $lock_name );
        if ( ! $lock ) {
            return;
        }
        try {
            if ( ! $this->automation_is_current( $state['session'] ) ) {
                return;
            }
            $state = get_option( $this->prefix . 'automation_state' );
            $settings = $this->settings();
            $channel_id = $this->channel_id_from_input( $settings['channel_id'] );
            if ( ! $channel_id ) {
                return;
            }
            $state['last_check'] = time();
            $entries = $this->fetch_channel_entries( $channel_id, $state['session'] );
            if ( ! $this->automation_is_current( $state['session'] ) ) {
                return;
            }
            if ( is_wp_error( $entries ) ) {
                $state['error'] = $entries->get_error_message();
            } else {
                $state['error'] = '';
                $state['last_success'] = time();
                $latest = null;
                foreach ( $entries as $entry ) {
                    if ( null === $latest && $entry['published'] > $state['started_at'] && $entry['published'] <= time() && ! in_array( $entry['id'], $state['seen'], true ) ) {
                        $latest = $entry;
                    }
                }
                // Remember the whole observed feed so older entries never become a backfill.
                $observed = array();
                foreach ( $entries as $entry ) {
                    if ( $entry['published'] <= time() ) {
                        $observed[] = $entry['id'];
                    }
                }
                $state['seen'] = array_slice( array_values( array_unique( array_merge( $observed, $state['seen'] ) ) ), 0, 100 );
                if ( $latest ) {
                    if ( ! empty( $state['pending'] ) ) {
                        $this->automation_activity( $state, $state['pending']['id'], __( 'Superseded by a newer upload.', 'youtube-to-ai-blog-post' ) );
                    }
                    $state['pending'] = array( 'id' => $latest['id'], 'attempts' => 0, 'next_attempt' => time() );
                    $this->automation_activity( $state, $latest['id'], __( 'New upload found.', 'youtube-to-ai-blog-post' ) );
                }
            }
            if ( ! $this->save_automation_state( $state ) ) {
                return;
            }
            if ( empty( $state['pending'] ) || $state['pending']['next_attempt'] > time() ) {
                return;
            }
            $job = $state['pending'];
            ++$job['attempts'];
            // Persist before paid work so hard timeouts also count toward the retry limit.
            if ( $job['attempts'] > 6 ) {
                $state['pending'] = array();
                $this->automation_activity( $state, $job['id'], __( 'Stopped after six processing attempts.', 'youtube-to-ai-blog-post' ) );
                $this->save_automation_state( $state );
                return;
            }
            $job['next_attempt'] = time() + min( 8, pow( 2, $job['attempts'] - 1 ) ) * HOUR_IN_SECONDS;
            $state['pending'] = $job;
            if ( ! $this->save_automation_state( $state ) ) {
                return;
            }
            $result = $this->generate_draft( $job['id'], $settings, $state['session'] );
            if ( ! $this->automation_is_current( $state['session'] ) ) {
                return;
            }
            if ( is_wp_error( $result ) ) {
                $data = $result->get_error_data();
                $status = is_array( $data ) && isset( $data['http_status'] ) ? (int) $data['http_status'] : 0;
                $retry = $job['attempts'] < 6 && ! in_array( $status, array( 400, 401, 403 ), true ) && 'ytaibp_invalid_author' !== $result->get_error_code();
                $message = $this->redact_diagnostic( $result->get_error_message() );
                $message .= $retry ? __( ' Will retry on a later hourly check.', 'youtube-to-ai-blog-post' ) : __( ' Automatic retries stopped; check your settings or import this video manually.', 'youtube-to-ai-blog-post' );
                $this->automation_activity( $state, $job['id'], $message );
                if ( ! $retry ) {
                    $state['pending'] = array();
                }
            } else {
                $state['pending'] = array();
                $warning = get_post_meta( $result, '_ytaibp_thumbnail_error', true );
                $this->automation_activity( $state, $job['id'], __( 'Draft ready for review.', 'youtube-to-ai-blog-post' ) . ( $warning ? ' ' . $warning : '' ), $result );
            }
            $this->save_automation_state( $state );
        } catch ( Throwable $error ) {
            if ( $this->automation_is_current( $state['session'] ) ) {
                $state['error'] = __( 'An unexpected error interrupted the channel check. Pending work will be retried on a later hourly check.', 'youtube-to-ai-blog-post' );
                $this->save_automation_state( $state );
            }
        } finally {
            $this->release_lock( $lock_name, $lock );
        }
    }

    private function automation_settings_fields( $settings ) {
        $state = get_option( $this->prefix . 'automation_state', array() );
        $next = wp_next_scheduled( 'ytaibp_check_youtube' );
        ?>
        <h2><?php esc_html_e( 'Automatic channel imports', 'youtube-to-ai-blog-post' ); ?></h2>
        <p><label><input type="checkbox" name="ytaibp_plugin_enabled" value="1" <?php checked( '1', $settings['plugin_enabled'] ); ?>> <?php esc_html_e( 'Check this channel hourly and create drafts for new uploads', 'youtube-to-ai-blog-post' ); ?></label></p>
        <p><label for="ytaibp-channel-id"><?php esc_html_e( 'YouTube channel ID or channel URL', 'youtube-to-ai-blog-post' ); ?></label></p>
        <input type="text" id="ytaibp-channel-id" name="ytaibp_channel_id" class="large-text" value="<?php echo esc_attr( $settings['channel_id'] ); ?>" placeholder="https://www.youtube.com/channel/UC...">
        <p class="description"><?php esc_html_e( 'One channel only. Use a UC... channel ID or /channel/UC... URL; @handle URLs are not supported. Imports use the saved author, category, model, prompts, and template above.', 'youtube-to-ai-blog-post' ); ?></p>
        <p class="description"><?php esc_html_e( 'Only uploads published after enabling are eligible. Each check selects the latest new video; a newer upload replaces any older video waiting to retry. Shorts and livestream filtering is not enforced yet. Disabling resets monitoring and pending work; existing drafts remain.', 'youtube-to-ai-blog-post' ); ?></p>
        <p class="description"><?php esc_html_e( 'Checks use WordPress cron and may run late when the site receives no visits. Automatic imports run in the background; you can close this page.', 'youtube-to-ai-blog-post' ); ?></p>
        <?php if ( '1' === $settings['plugin_enabled'] ) : ?>
            <p><?php echo esc_html( $next ? sprintf( __( 'Next scheduled check: %s', 'youtube-to-ai-blog-post' ), get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $next ), 'Y-m-d H:i:s' ) ) : __( 'No channel check is scheduled. Save settings to schedule it again.', 'youtube-to-ai-blog-post' ) ); ?></p>
            <?php foreach ( array( 'last_check' => __( 'Last check', 'youtube-to-ai-blog-post' ), 'last_success' => __( 'Last successful feed check', 'youtube-to-ai-blog-post' ) ) as $key => $label ) : ?>
                <?php if ( ! empty( $state[ $key ] ) ) : ?><p><?php echo esc_html( $label . ': ' . get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $state[ $key ] ), 'Y-m-d H:i:s' ) ); ?></p><?php endif; ?>
            <?php endforeach; ?>
            <?php if ( ! empty( $state['error'] ) ) : ?><p role="status"><?php echo esc_html( $state['error'] ); ?></p><?php endif; ?>
            <?php if ( ! empty( $state['pending'] ) ) : ?><p><?php echo esc_html( sprintf( __( 'Waiting to process video: %s', 'youtube-to-ai-blog-post' ), $state['pending']['id'] ) ); ?></p><?php endif; ?>
            <?php if ( ! empty( $state['activity'] ) ) : ?>
                <ul>
                <?php foreach ( $state['activity'] as $entry ) : ?>
                    <li><?php echo esc_html( get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $entry['time'] ), 'Y-m-d H:i:s' ) . ' - ' . $entry['video_id'] . ': ' . $entry['message'] ); ?>
                    <?php if ( ! empty( $entry['post_id'] ) && get_post( $entry['post_id'] ) ) : ?><a href="<?php echo esc_url( get_edit_post_link( $entry['post_id'], 'raw' ) ); ?>"><?php esc_html_e( 'Edit post', 'youtube-to-ai-blog-post' ); ?></a><?php endif; ?></li>
                <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        <?php endif;
    }
}
