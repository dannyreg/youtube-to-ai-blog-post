# YouTube channel automation: research and implementation

Research date: October 19, 2026. Implemented in plugin version 6.1.0.

## Agreed behavior

- Exactly one channel, configured on the existing settings page.
- Native WordPress cron using `wp_next_scheduled()`, `wp_schedule_event( time(), 'hourly', 'ytaibp_check_youtube' )`, and `wp_clear_scheduled_hook()` when disabled.
- Future uploads only, using a UTC timestamp recorded when monitoring starts. No initial or historical backfill.
- Select the latest new eligible upload found in each check. Other observed uploads are skipped. A newer upload replaces an older pending retry.
- Always create drafts for manual review, using the saved credentials, model, prompts, template, author, and category.
- Regular videos are the eventual target; strict Shorts/live filtering is deferred by user choice.
- Disabling removes the cron event, cancels pending work, resets monitoring, and invalidates in-flight sessions. Enabling again starts fresh and skips uploads made while stopped. Existing posts and media remain.

## Research findings

The polling URL is:

```text
https://www.youtube.com/feeds/videos.xml?channel_id=UC...
```

The feed is Atom XML. Google's [push notification guide](https://developers.google.com/youtube/data/guides/push_notifications) shows channel/video identifiers and published/updated timestamps. Its subscription examples currently use the alternate `/xml/feeds/videos.xml` path. Direct feed retrieval through the research browser failed; endpoint availability, the current feed retention window, and live provider processing still need validation on the target WordPress site.

The feed supplies discovery metadata, not a transcript or a complete channel archive. The implementation validates channel/video IDs, sorts by the Atom `published` timestamp, and remembers observed IDs. Editing a title, description, or `updated` timestamp should not trigger another import. RSS delays and finite history prevent a strict upload-to-post timing or no-missed-video guarantee.

The first version accepts a channel ID or HTTPS `/channel/UC...` URL. It rejects arbitrary hosts and `@handle` URLs. Google's [channels.list API](https://developers.google.com/youtube/v3/docs/channels/list) documents `forHandle`, but adding API-based handle resolution is outside this version.

[WP-Cron](https://developer.wordpress.org/plugins/cron/) depends on WordPress receiving traffic, so hourly is a target interval. Hosting cron is optional and does not block installation. The [scheduling guide](https://developer.wordpress.org/plugins/cron/scheduling-wp-cron-events/) covers recurring events and deactivation cleanup.

WordPress [fetch_feed()](https://developer.wordpress.org/reference/functions/fetch_feed/) parses Atom with SimplePie but caches feeds for 12 hours by default. This plugin scopes a five-minute cache lifetime and a five-second feed timeout to its own URL; it removes both temporary callbacks after fetching.

Version 6.1.3 tries the feed up to five times during each hourly callback, stopping on success. Pauses after failures are 1, 2, 4, and 8 seconds; the entire retry sequence has a 45-second budget. Retry requests disable SimplePie caching so a prior failure cannot be reused as a fresh attempt. The initial request retains normal feed caching. The monitoring session is checked before each attempt. Exhaustion reports the actual attempt count and redacted last error, preserves seen IDs and the last-success timestamp, and does not consume video-processing retries. Already-discovered pending work may still proceed. The next hourly invocation gets a fresh five-attempt budget. Host execution limits may still shorten a check.

YouTube says [automatic captions may be delayed](https://support.google.com/youtube/answer/6373554), so missing transcripts are retryable. Before adding regular-video filtering, inspect the transcript API's actual response for reliable Shorts/live indicators; the current extraction does not expose them.

[Action Scheduler](https://actionscheduler.org/) is a possible larger-scale queue, but it still uses WP-Cron by default and has a [dependency policy](https://actionscheduler.org/usage/) targeting recent WordPress versions. The agreed implementation uses native cron and WordPress options, without that dependency or a custom job table.

## Implementation

`includes/trait-ytaibp-automation.php` contains channel validation, scheduling, feed parsing, monitoring, retries, and processing leases. The main plugin retains settings persistence and rendering, AJAX authorization, provider calls, and post/media creation.

```text
Hourly event -> fetch feed -> select latest new upload -> persist pending state
             -> shared generation -> transcript -> summary -> draft -> optional thumbnail
```

`generate_draft()` is shared by manual and automatic imports. AJAX checks remain in the manual handler; cron uses an internal callback and validates the explicitly configured author. Neither a saved nonce nor an anonymous author is used for unattended work.

The non-autoloaded `ytaibp_automation_state` option stores a session identifier, enable timestamp, up to 100 seen video IDs, one pending job, ten recent activity records, last-check/last-success timestamps, and a feed error. Only the current monitoring session can update it. Compare-and-swap writes prevent a reset racing a worker from reviving old state. No transcript or generated-summary cache is stored.

Atomic option insertion claims a five-minute poll lease and a per-video lease. Expired leases are reclaimed with compare-and-delete; releasing an old lease cannot delete its replacement. Video leases protect both manual and automatic submissions. Provider work starts only after the pending attempt is persisted.

Automatic work allows six attempts. Delays after the first five failures are 1, 2, 4, 8, and 8 hours; actual execution occurs on a later hourly check. HTTP 400/401/403 and invalid authors stop that video's retries. Missing transcripts, connection failures, 429s, and 5xx responses receive bounded retries. After retries stop, the user can correct settings and import the video manually. An overdue attempt following a hard timeout is also counted. A newly found upload supersedes an older pending video.

Each generated post records `_ytaibp_video_id` and a deterministic GUID in the post row. Before contacting providers, generation checks for an existing active post, including published posts. Version 6.1.1 excludes posts in Trash from duplicate matching: trashing or permanently deleting a post allows manual generation of a fresh draft without modifying the trashed original. The GUID also allows recovery when a process dies after inserting a post but before inserting metadata. Older plugin versions' posts lack these identifiers and are not automatically deduplicated.

Successfully saved drafts survive optional thumbnail failure and browser disconnects. Provider requests cannot be recalled after disabling, but the session is rechecked after requests and immediately before post insertion. These checks cannot make a setting change and post insertion one atomic transaction; a draft already being committed may finish saving. Exactly-once provider billing cannot be guaranteed after a remote timeout.

Version 6.1.2 adds a separate 20-second media budget after the 120-second generation budget. Images are tried in this order: a valid transcript-service image URL, direct `i.ytimg.com/vi/VIDEO_ID/maxresdefault.jpg`, and direct `hqdefault.jpg`. Candidate URLs are deduplicated. Only HTTPS YouTube image hosts are allowed, each download is limited to five seconds, and downloaded files must be recognized images at least 320 by 180 pixels. Tiny placeholders and non-image responses are rejected. The Media Library filename follows the detected image MIME type. Temporary files and failed attachments are cleaned up. A missing image never turns a saved draft into a failed generation.

Image failures are recorded as redacted post metadata and displayed in manual results or automation activity. Resubmitting an existing post with no featured image retries just the direct image URLs, without transcript/AI calls or text regeneration; existing featured images are preserved. This does not add a scheduled image-repair job. Upload-directory, image-processing, and hosting errors remain actionable server issues.

Other researched alternatives: WordPress [registers YouTube's oEmbed endpoint](https://github.com/WordPress/wordpress-develop/blob/trunk/src/wp-includes/class-wp-oembed.php), while the [YouTube Data API](https://developers.google.com/youtube/data/reference/rest/v3/videos) returns `snippet.thumbnails` using additional API configuration. The CDN variants are used in [yt-dlp's YouTube implementation](https://github.com/yt-dlp/yt-dlp/blob/master/yt_dlp/extractor/youtube/_video.py). This version uses direct CDN fallback to avoid requiring another key or metadata request.

Deactivation clears the event and monitoring state while retaining settings and content. Uninstall also removes settings and abandoned lease options, preserving posts, source metadata, and media. Cleanup applies to the current WordPress site, consistent with the existing uninstall implementation.

## Settings and status

The existing settings form now includes the enable checkbox and one channel field. It validates channel input and credentials before saving. Normal prompt/template changes preserve the session; changing channels creates a new one.

Status shows the next scheduled check, last attempt, last successful feed check, feed errors, pending video, and ten recent results with post links. It distinguishes a successful feed read from successful draft generation. No automatic publishing, interval selector, email notifications, or manual retry/check buttons were added.

## Optional hosting cron

Look in the hosting dashboard for **Cron Jobs** or **Scheduled Tasks**. cPanel exposes **Advanced > Cron Jobs**, but providers can disable it. See [cPanel documentation](https://docs.cpanel.net/cpanel/advanced/cron-jobs/).

If unclear, ask support: "Does my plan support running WordPress's wp-cron.php every five minutes? Do you already manage WordPress cron for my site?"

If desired, follow [WordPress's system scheduler instructions](https://developer.wordpress.org/plugins/cron/hooking-wp-cron-into-the-system-task-scheduler/). The host can dispatch cron every five minutes while this plugin still polls hourly. Verify the replacement before disabling visit-triggered spawning. Do not remove a host-level task serving the rest of WordPress when disabling this feature.

[`wp cron test`](https://developer.wordpress.org/cli/commands/cron/test/) tests HTTP spawning, not whether the hosting plan offers system cron. It reports an error when `DISABLE_WP_CRON` is true, including sites correctly using an external scheduler.

## Validation

Run `php -l` on the plugin, automation trait, and uninstall script, then `php tests/automation-test.php`. The standalone harness mocks WordPress persistence, feed responses, and providers; it exercises scheduling, future/latest selection, retries, errors, concurrent claims, cancellation/reset races, settings validation, duplicate recovery, authorization, and lifecycle cleanup.

Live feed parsing in WordPress, real API processing, media sideloads, host time limits, and the declared WordPress 5.0/PHP 7.0 compatibility still need staging validation. No site credentials or production scheduler were changed during implementation.
