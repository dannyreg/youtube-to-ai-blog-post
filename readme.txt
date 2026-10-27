=== YouTube To AI Blog Post ===
Tags: youtube, ai, blog, drafts, content
Requires at least: 5.0
Requires PHP: 7.0
Stable tag: 6.1.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Turn public YouTube videos into WordPress drafts with AI summaries, video embeds, descriptions, and optional featured images.

== Description ==

Paste a public video link, create a draft, then review and publish in WordPress. Optionally monitor one channel hourly and create drafts for future uploads. Customize the author, category, model, prompts, and post template.

Requires a YouTube Transcript API token and an OpenAI API key. Provider charges and limits apply separately. Settings and generation require administrator capabilities; generation also requires permission to edit posts. Nothing is published automatically.

== Installation ==

1. Upload the plugin ZIP through Plugins > Add New > Upload Plugin and activate it.
2. Open Settings > YouTube to Blog.
3. Enter your YouTube Transcript token and OpenAI API key, then save settings.
4. Paste a public video URL and click Create draft. Keep the page open.
5. Preview or edit the draft, then publish when ready.
6. Optionally enter one channel ID or /channel/UC... URL, enable automatic imports, and save settings.

== Frequently Asked Questions ==

= Which videos work? =

Public videos with a usable transcript returned by the transcript service. Watch, short-link, Shorts, live, and embed URLs are supported for manual imports. Automatic imports support one channel by ID or /channel/UC... URL; @handle URLs and playlists are not supported. Strict Shorts/live filtering is not enforced yet.

= How do automatic imports work? =

Enable automatic imports in settings. Native WordPress cron checks the channel feed hourly and selects the latest new video published after enabling. Older videos found in the same check are skipped. Drafts use your saved author, category, model, prompts, and template. Processing happens in the background without an open browser page.

Checks may run late when the site receives no visits. Hosting cron is optional. Missing transcripts and temporary failures retry up to six times with increasing delays; a newer video replaces an older pending retry. The settings page shows recent activity and errors. HTTP 400/401/403 and invalid-author errors stop that video's retries.

Each hourly feed check tries up to five times, stopping on success. Failed feed requests retry after 1, 2, 4, and 8 seconds, bypassing the feed cache, with five-second request timeouts and a 45-second total retry budget. If all attempts fail, monitoring history and pending work remain intact and the next hourly check tries again. Feed errors show the attempt count and last error. These feed attempts are separate from the six-attempt video-processing limit.

Disabling removes the cron event and resets monitoring and pending work; existing posts remain. Re-enabling or changing the channel starts fresh and skips uploads made while stopped. A provider request already in progress cannot be recalled.

= Why did generation fail? =

Check provider credentials, quotas, model access, and transcript availability. Generation has a two-minute budget plus up to 20 seconds for optional image work, but hosting limits may be shorter. New posts record their source video so repeated imports return the existing active post. Moving that post to Trash or permanently deleting it lets you manually import the same video again and create a fresh draft; the trashed post is left untouched. Posts created before version 6.1 do not have this duplicate protection. Automatic imports have bounded retries; manual imports do not retry automatically.

= What if the featured image is missing? =

The plugin tries the transcript service's YouTube image URL, then direct maxresdefault.jpg and hqdefault.jpg URLs on i.ytimg.com. No extra API key is required. Invalid images and small placeholders are skipped. Download, Media Library, and featured-image errors are displayed without discarding the draft. Submit the video again to retry a missing image on the existing post without paid API requests or regenerating text. Existing featured images are preserved.

= What are the length limits? =

The AI prompt uses at most the first 12,000 transcript words. Generation caps at 5,000 completion tokens, including reasoning tokens where applicable.

= What happens when I delete the plugin? =

WordPress deletion removes plugin settings, monitoring state, processing leases, and the channel cron event on the current site. Posts, source metadata, and media remain. Deactivation removes the cron event and monitoring state but retains settings. Uninstall does not iterate over multisite sites.

== External services and data ==

Service requests occur when an authorized user clicks Create draft or when enabled automatic imports run through WordPress cron. Automatic retries can make additional provider requests. Provider billing applies separately. YouTube Transcript's free plan is restricted to non-commercial use.

* YouTube Transcript: your server sends the video ID and API token to https://www.youtube-transcript.io/api/transcripts to retrieve the transcript and metadata. [API](https://www.youtube-transcript.io/api), [terms](https://www.youtube-transcript.io/terms-of-service), [privacy](https://www.youtube-transcript.io/privacy-policy).
* OpenAI: your server sends the API key, model, system prompt, and expanded AI prompt to https://api.openai.com/v1/chat/completions. The default prompt includes the title and transcript; custom prompts can include the description. [Terms](https://openai.com/policies/services-agreement/), [privacy](https://openai.com/policies/privacy-policy/).
* YouTube / Google: when automatic imports are enabled, your server requests the configured channel's public Atom feed at https://www.youtube.com/feeds/videos.xml?channel_id=CHANNEL_ID. It may download a thumbnail from i.ytimg.com, img.youtube.com, or i1.ytimg.com through i4.ytimg.com. WordPress may resolve the embed through YouTube; displaying the player can connect visitors to YouTube. [Terms](https://www.youtube.com/static?template=terms), [privacy](https://policies.google.com/privacy).

Credentials and settings are stored in WordPress options without application-level encryption and are accessible to administrators through settings. Monitoring stores the session start, up to 100 seen video IDs, one pending video, and ten recent activity records in an option; processing leases are temporary options. WordPress caches the channel feed for five minutes. Generated drafts, video source identifiers, thumbnails, and redacted thumbnail-error messages are stored locally. The full transcript is stored in post content only when the template includes {transcript}. There is no transcript cache or external queue service.

== Changelog ==

= 6.1.3 =
* Added up to five feed attempts per hourly check with bounded backoff, fresh retry requests, cancellation checks, and last-error diagnostics.

= 6.1.2 =
* Added direct YouTube image fallbacks and validation of image contents and dimensions.
* Gave thumbnail retrieval a separate time budget, exposed image errors, and added image repair when resubmitting an existing post without a featured image.

= 6.1.1 =
* Fixed deleted or trashed posts blocking fresh imports of the same video. Duplicate protection now matches only active posts.

= 6.1.0 =
* Added optional hourly channel imports using native WordPress cron, future-only discovery, draft output, bounded retries, and monitoring reset on disable.
* Shared manual/automatic generation and added source-video duplicate protection for newly created posts.
