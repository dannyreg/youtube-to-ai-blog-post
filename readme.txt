=== YouTube To AI Blog Post ===
Tags: youtube, ai, blog, drafts, content
Requires at least: 5.0
Requires PHP: 7.0
Stable tag: 6.0.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Turn public YouTube videos into WordPress drafts with AI summaries, video embeds, descriptions, and optional featured images.

== Description ==

Paste a public video link, create a draft, then review and publish in WordPress. Customize the author, category, model, prompts, and post template.

Requires a YouTube Transcript API token and an OpenAI API key. Provider charges and limits apply separately. Settings and generation require administrator capabilities; generation also requires permission to edit posts. Nothing is published automatically.

== Installation ==

1. Upload the plugin ZIP through Plugins > Add New > Upload Plugin and activate it.
2. Open Settings > YouTube to Blog.
3. Enter your YouTube Transcript token and OpenAI API key, then save settings.
4. Paste a public video URL and click Create draft. Keep the page open.
5. Preview or edit the draft, then publish when ready.

== Frequently Asked Questions ==

= Which videos work? =

Public videos with a usable transcript returned by the transcript service. Watch, short-link, Shorts, live, and embed URLs are supported; playlists and channel imports are not.

= Why did generation fail? =

Check provider credentials, quotas, model access, and transcript availability. Processing has a two-minute budget, but hosting limits may be shorter. Check existing posts before retrying; there is no duplicate detection or automatic retry.

= What are the length limits? =

The AI prompt uses at most the first 12,000 transcript words. Generation caps at 5,000 completion tokens, including reasoning tokens where applicable.

= What happens when I delete the plugin? =

WordPress deletion removes eight plugin settings on the current site, including credentials. Posts and media remain. Deactivation retains settings. Uninstall does not iterate over multisite sites.

== External services and data ==

Service requests occur when an authorized user clicks Create draft. Provider billing applies separately. YouTube Transcript's free plan is restricted to non-commercial use.

* YouTube Transcript: your server sends the video ID and API token to https://www.youtube-transcript.io/api/transcripts to retrieve the transcript and metadata. [API](https://www.youtube-transcript.io/api), [terms](https://www.youtube-transcript.io/terms-of-service), [privacy](https://www.youtube-transcript.io/privacy-policy).
* OpenAI: your server sends the API key, model, system prompt, and expanded AI prompt to https://api.openai.com/v1/chat/completions. The default prompt includes the title and transcript; custom prompts can include the description. [Terms](https://openai.com/policies/services-agreement/), [privacy](https://openai.com/policies/privacy-policy/).
* YouTube / Google: your server may download a thumbnail from i.ytimg.com, img.youtube.com, or i1.ytimg.com through i4.ytimg.com. WordPress may resolve the embed through YouTube; displaying the player can connect visitors to YouTube. [Terms](https://www.youtube.com/static?template=terms), [privacy](https://policies.google.com/privacy).

Credentials and settings are stored in WordPress options without application-level encryption and are accessible to administrators through settings. Generated drafts and thumbnails are stored locally. The full transcript is stored in post content only when the template includes {transcript}. There is no persistent job queue or transcript cache.
