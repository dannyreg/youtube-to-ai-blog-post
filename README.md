# YouTube To AI Blog Post

A WordPress plugin that turns a public YouTube video into a blog draft with an AI summary, video embed, description, and optional featured image. Review and publish the draft yourself.

## Quick start — no coding required

You need a WordPress administrator account, a [YouTube Transcript API token](https://www.youtube-transcript.io/api), and an [OpenAI API key](https://platform.openai.com/api-keys). Provider charges and limits apply separately; YouTube Transcript's [free plan is for non-commercial use](https://www.youtube-transcript.io/terms-of-service).

1. [Download the project](https://github.com/dannyreg/youtube-to-ai-blog-post) using **Code → Download ZIP**.
2. In WordPress, open **Plugins → Add New → Upload Plugin**, upload the ZIP, and activate it.
3. Open **Settings → YouTube to Blog**, enter both credentials, and click **Save settings**. Keep the other defaults for your first post.
4. Paste a public YouTube video link and click **Create draft**. Keep the page open while it processes.
5. Click **Preview post** or **Edit draft**, review the content, then publish in WordPress.

Watch links, `youtu.be` links, Shorts, live, and embed URLs are supported for manual imports. Playlists are not supported.

## Automatic channel imports

In **Settings → YouTube to Blog**, enter one channel's `UC...` ID or `https://www.youtube.com/channel/UC...` URL, enable **Check this channel hourly**, and save. Handle URLs such as `youtube.com/@name` are not supported yet. Both API credentials and a valid post author are required.

The plugin uses native WP-Cron to check the public channel feed hourly and generate drafts with your saved settings. You can close the settings page. Only videos published after enabling are eligible; each check selects the latest new upload and skips older uploads found during that check. Strict Shorts/live filtering is deferred.

WordPress cron depends on site visits, so checks can run late on quiet sites. A hosting cron job is optional. The settings page shows the next scheduled check, last check, feed errors, pending video, and ten recent activity entries.

Each hourly check makes up to five feed attempts before giving up for that hour. Failed requests retry after 1, 2, 4, and 8 seconds, with five-second request timeouts and an overall 45-second retry budget. Retries bypass the WordPress feed cache; successful first attempts keep the normal five-minute cache. Exhaustion preserves monitoring history and pending work, shows the attempt count and last error, and leaves the next hourly check scheduled. This feed retry budget is separate from the video-processing retry limit below.

Missing transcripts and temporary failures retry on later hourly checks, up to six attempts with delays of 1, 2, 4, 8, and 8 hours. A newer upload replaces an older pending video. Invalid author or HTTP 400/401/403 failures stop that video's retries. Correct the settings and import it manually if needed.

Disabling removes the channel cron event and resets monitoring and pending work. Re-enabling or changing channels starts fresh and skips videos published while stopped. Existing posts and media remain. A provider request already in progress cannot be recalled, but a canceled session is checked again before saving a draft.

## Settings and troubleshooting

Choose the post author/category and customize the model, writing prompts, or HTML template. The default model is `gpt-4o-mini`.

- **AI prompt:** `{title}`, `{transcript}`, `{description}`.
- **Post template:** `{summary}`, `{video_embed}`, `{description}`, `{title}`, `{video_url}`, `{video_id}`, `{channel_url}`, `{published_date}`, `{transcript}`.

If the button is disabled, save both credentials. For errors, check the named provider's key, quota, model access, and transcript availability. Newly generated posts record their video IDs: repeating an import opens the existing active post, including posts already published. Moving that post to Trash or permanently deleting it lets you manually import the video again and create a fresh draft. The trashed post is left untouched. Posts created before version 6.1 do not have this duplicate protection. A missing thumbnail does not prevent draft creation.

### Featured images

Thumbnail retrieval first tries a valid YouTube image URL returned by the transcript service, then tries `https://i.ytimg.com/vi/VIDEO_ID/maxresdefault.jpg` and `hqdefault.jpg`. No extra API key is required. Unavailable sizes, non-image responses, and tiny placeholders fall through to the next candidate. WordPress saves the successful image in the Media Library and assigns it as the featured image.

Image work gets a separate 20-second budget after draft generation. If it fails, the draft remains and the result or automatic-import activity shows the reason. Submit the same video again to retry a missing featured image on an existing post without transcript/AI requests or text changes. Existing featured images are preserved. Media Library permission/storage failures still require fixing the hosting environment; changing the image source cannot bypass them.

## For developers

Version **6.1.3** declares WordPress **5.0+** and PHP **7.0+**; test compatibility on your target versions. There are no build dependencies. A standalone mocked WordPress regression harness covers channel automation and generation.

| File | Purpose |
| --- | --- |
| `youtube-to-ai-blog-post.php` | Settings UI, AJAX handler, API requests, and draft/media creation. |
| `includes/trait-ytaibp-automation.php` | Channel settings, native cron, feed discovery, retries, monitoring, and processing leases. |
| `uninstall.php` | Removes plugin options, leases, and the cron event; keeps posts and media. |
| `tests/automation-test.php` | Regression scenarios with mocked WordPress and provider responses. |
| `readme.txt` | Draft WordPress directory listing. |

Place the project in `wp-content/plugins/youtube-to-ai-blog-post/`, activate it, and configure credentials. Syntax checks:

```sh
php -l youtube-to-ai-blog-post.php
php -l uninstall.php
php -l includes/trait-ytaibp-automation.php
php tests/automation-test.php
```

Manual generation requires `manage_options`, `edit_posts`, and a valid nonce. Automatic generation uses a trusted internal cron callback and validates the saved author. Both paths share transcript retrieval, summary generation, draft insertion, video leases, and duplicate detection. The AI prompt uses at most 12,000 transcript words, generation caps at 5,000 completion tokens, and generation has a 120-second budget plus up to 20 seconds for optional media work. The browser request allows 150 seconds; host limits may be shorter. Monitoring stores one pending video and bounded activity in a non-autoloaded WordPress option; no queue library or custom database table is required. Source metadata and a deterministic post GUID allow a retry to recover a post after interrupted insertion. Provider billing cannot be guaranteed exactly once after a network timeout.

Before releasing, test successful generation, invalid links/credentials, permissions, timeouts, and uninstall on a staging site. Run [Plugin Check](https://wordpress.org/plugins/plugin-check/). Package one plugin folder containing the PHP files and documentation, excluding Git metadata and credentials.

## Privacy

Your server sends the video ID and token to YouTube Transcript, and configured prompts/source content and your API key to OpenAI. It may download a YouTube thumbnail; embedded players can connect visitors to YouTube. Credentials are stored in WordPress options without application-level encryption. See [readme.txt](readme.txt) for service terms, privacy links, and deletion behavior.

## Easier setup and distribution

For non-developers, prioritize a ready-to-upload release ZIP, guided setup with credential tests, and an **Advanced** section for models, prompts, and templates. These are proposed improvements.

**Start with the WordPress.org Plugin Directory.** It simplifies discovery and installation, but users still need API credentials. Before submitting, change the current slug: [directory naming rules](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/#17-plugins-must-respect-trademarks-copyrights-and-project-names) restrict third-party trademarks at the beginning. Consider `video-to-blog-drafts-for-youtube`, subject to availability and review.

Add your verified WordPress.org contributor username and actual **Tested up to** version to `readme.txt`, include the license text, validate the listing, and [submit for review](https://wordpress.org/plugins/developers/add/).

## License

GPL v2 or later, as declared in the plugin header.
