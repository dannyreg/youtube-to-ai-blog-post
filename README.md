# YouTube To AI Blog Post

A WordPress plugin that turns a public YouTube video into a blog draft with an AI summary, video embed, description, and optional featured image. Review and publish the draft yourself.

## Quick start — no coding required

You need a WordPress administrator account, a [YouTube Transcript API token](https://www.youtube-transcript.io/api), and an [OpenAI API key](https://platform.openai.com/api-keys). Provider charges and limits apply separately; YouTube Transcript's [free plan is for non-commercial use](https://www.youtube-transcript.io/terms-of-service).

1. [Download the project](https://github.com/dannyreg/youtube-to-ai-blog-post) using **Code → Download ZIP**.
2. In WordPress, open **Plugins → Add New → Upload Plugin**, upload the ZIP, and activate it.
3. Open **Settings → YouTube to Blog**, enter both credentials, and click **Save settings**. Keep the other defaults for your first post.
4. Paste a public YouTube video link and click **Create draft**. Keep the page open while it processes.
5. Click **Preview post** or **Edit draft**, review the content, then publish in WordPress.

Watch links, `youtu.be` links, Shorts, live, and embed URLs are supported. Playlists and channel imports are not.

## Settings and troubleshooting

Choose the post author/category and customize the model, writing prompts, or HTML template. The default model is `gpt-4o-mini`.

- **AI prompt:** `{title}`, `{transcript}`, `{description}`.
- **Post template:** `{summary}`, `{video_embed}`, `{description}`, `{title}`, `{video_url}`, `{video_id}`, `{channel_url}`, `{published_date}`, `{transcript}`.

If the button is disabled, save both credentials. For errors, check the named provider's key, quota, model access, and transcript availability. After a timeout, check **Posts → All Posts** before retrying: resubmitting can create another draft and use the APIs again. A missing thumbnail does not prevent draft creation.

## For developers

Version **6.0.3** declares WordPress **5.0+** and PHP **7.0+**; test compatibility on your target versions. There are no build dependencies or bundled automated tests.

| File | Purpose |
| --- | --- |
| `youtube-to-ai-blog-post.php` | Settings UI, AJAX handler, API requests, and draft/media creation. |
| `uninstall.php` | Removes eight settings options; keeps posts and media. |
| `readme.txt` | Draft WordPress directory listing. |

Place the project in `wp-content/plugins/youtube-to-ai-blog-post/`, activate it, and configure credentials. Syntax checks:

```sh
php -l youtube-to-ai-blog-post.php
php -l uninstall.php
```

Generation requires `manage_options`, `edit_posts`, and a valid nonce. It calls YouTube Transcript, then OpenAI Chat Completions, then saves a draft and optionally downloads a thumbnail. The AI prompt uses at most 12,000 transcript words, generation caps at 5,000 completion tokens, and processing has a 120-second budget. There is no background queue, automatic retry, or duplicate detection.

Before releasing, test successful generation, invalid links/credentials, permissions, timeouts, and uninstall on a staging site. Run [Plugin Check](https://wordpress.org/plugins/plugin-check/). Package one plugin folder containing the PHP files and documentation, excluding Git metadata and credentials.

## Privacy

Your server sends the video ID and token to YouTube Transcript, and configured prompts/source content and your API key to OpenAI. It may download a YouTube thumbnail; embedded players can connect visitors to YouTube. Credentials are stored in WordPress options without application-level encryption. See [readme.txt](readme.txt) for service terms, privacy links, and deletion behavior.

## Easier setup and distribution

For non-developers, prioritize a ready-to-upload release ZIP, guided setup with credential tests, and an **Advanced** section for models, prompts, and templates. These are proposed improvements.

**Start with the WordPress.org Plugin Directory.** It simplifies discovery and installation, but users still need API credentials. Before submitting, change the current slug: [directory naming rules](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/#17-plugins-must-respect-trademarks-copyrights-and-project-names) restrict third-party trademarks at the beginning. Consider `video-to-blog-drafts-for-youtube`, subject to availability and review.

Add your verified WordPress.org contributor username and actual **Tested up to** version to `readme.txt`, include the license text, validate the listing, and [submit for review](https://wordpress.org/plugins/developers/add/).

## License

GPL v2 or later, as declared in the plugin header.
