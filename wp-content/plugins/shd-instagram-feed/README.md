# SHD Instagram Feed

Shows the latest Instagram posts in a fast, accessible slider, as an Elementor widget. Posts and images are synced in the background and stored on your own server, so visitors never load anything from Instagram.

Built to replace the hand-maintained Loop Carousel ("instagram-slider" posts) at the bottom of egligartenbau.com: the widget's defaults reproduce that look (3 / 2 / 1 posts, 35 px gap, square images, round grey arrows with a dark-green icon) and update automatically.

## Requirements

- WordPress 6.4 or newer, PHP 7.4 or newer
- Elementor 3.5 or newer (tested with Elementor 4.3.6 and Elementor Pro 4.3.3; Pro is not required)
- An Instagram **professional account** (Business or Creator) and a Meta developer app (free)

## Setup

1. Install the plugin: *Plugins → Add New → Upload Plugin* with `shd-instagram-feed-<version>.zip`, then activate it.
2. Get an access token (one time):
   1. Make sure the Instagram account is a professional account (Instagram app → Settings → *Account type and tools*).
   2. On [developers.facebook.com/apps](https://developers.facebook.com/apps), create an app of the type **Business** with the Instagram use case.
   3. In the app dashboard, open *Instagram → API setup with Instagram business login*, add the Instagram account and click **Generate token**.
   4. Copy the token.

   Meta's guide: [Instagram API with Instagram Login](https://developers.facebook.com/docs/instagram-platform/instagram-api-with-instagram-login/get-started).
3. In WordPress, open *Settings → Instagram Feed*, paste the token and click **Connect account**. The plugin checks the token, then imports the newest posts right away (6 images per run; the rest follows in the background within a few minutes).
4. In Elementor, search for the **Instagram Feed** widget and drag it onto the page. On egligartenbau.com it replaces the Loop Carousel in the "Gartenbau, Poolbau und Gartenunterhalt aus Leidenschaft." section.

You only paste a token once: the plugin renews it every week (Instagram tokens expire after 60 days without renewal).

## Widget settings

**Content → Feed:** number of posts, show/hide videos and reels (cover image), link to Instagram, video/album icon, image format (square, 4:5, 3:4, 16:9, original), lazy loading.

**Content → Slider:** posts visible per device, gap, arrows move by one post or one page, loop, transition duration, autoplay (time per step, pause on hover, pause button), arrows on or next to the images, position indicator (dots, counter, progress bar, none).

**Style:** corner radius, zoom on hover, icon colours, keyboard focus colour; arrow button and icon size per device, distance from the edge, vertical position, colours for normal and hover; indicator spacing, alignment, dot size and colours.

To place the arrows exactly where the old Loop Carousel had them, set *Style → Arrows → Vertical position* to about 58 %. The default (50 %) centres them on the image.

## How it works

- **Background sync:** WP-Cron checks Instagram every hour (or twice a day / daily, see *Settings → Instagram Feed*). Nothing is requested from Instagram while a visitor's page loads.
- **Images:** each post image is downloaded once, converted to WebP when the server supports it, and stored as a normal Media Library attachment, so WordPress generates responsive sizes and image optimisation plugins work as usual. Images of posts that drop out of the newest *Posts to keep* (default 24) are deleted automatically. Images you uploaded yourself are never touched.
- **Videos and albums:** videos and reels show their cover image, albums show their first image, each with a small corner icon.
- **Alt text:** taken from Instagram's alt text when the post has one, otherwise from the caption (without hashtags, mentions and emoji). Editing the alt text in the Media Library keeps your version.
- **When something breaks** (expired token, Instagram outage), the widget keeps showing the last synced posts and administrators get a notice on the Dashboard and the plugin's settings page.

## Performance

- The slider is laid out with CSS only, so it looks final before any JavaScript runs. That also holds while an optimiser like 10Web delays scripts: there is no layout shift when the script starts (measured: 0 for the widget).
- Swiping on phones and trackpads is native scrolling. The script only adds the controls: 3.4 KB gzipped, loaded deferred and only on pages that contain the widget. No jQuery, no Swiper (the Loop Carousel needs Elementor's Swiper, about 40 KB gzipped).
- 1.7 KB of CSS (gzipped) is printed inline once per page, right before the first slider: no extra request, nothing on other pages.
- Images are lazy-loaded with `srcset` and a `sizes` value that matches the number of visible posts per device, so browsers pick an image size that fits the slot (for example the 768 px version for a 450 px slot on a standard screen).

## Accessibility

- All controls are real buttons with labels, at least 44 × 44 px on phones (the old arrows were 20 × 20 px).
- Keyboard: arrows, dots (one tab stop, ←/→, Home/End) and the left/right arrow keys anywhere in the slider. Focus outlines are visible and stay on screen.
- Autoplay has a pause button (WCAG 2.2.2). It also pauses while the mouse is over the slider, while a control or post inside it has focus, while the slider is off-screen and while the tab is in the background, and it never starts for visitors who have reduced motion turned on.
- Screen readers get a labelled carousel ("Instagram-Beiträge von @egligartenbau"), "1 von 12" slide labels and the post date. German translations (de_CH, de_DE) are included.

## Privacy

Visitors' browsers only load images from your own domain. The plugin never sends visitor data to Meta. The access token is stored in the WordPress database (not autoloaded) and never printed back into pages.

## For developers

Filters:

- `shd_igf_api_version` – Graph API version (default `v27.0`).
- `shd_igf_convert_to_webp` – return `false` to keep the original JPEGs.
- `shd_igf_webp_quality` – WebP quality (default `82`).
- `shd_igf_feed_items` – change or filter the posts before they are rendered, e.g. to hide a post by its `id`.

Sync manually with WP-CLI: `wp cron event run shd_igf_sync`.

Stored data: options `shd_igf_settings`, `shd_igf_connection`, `shd_igf_feed`, `shd_igf_status` (all non-autoloaded) and attachments with the meta key `_shd_igf_media_id`. Deactivating stops the background sync; deleting the plugin removes the options and the imported images.

Code layout:

```
shd-instagram-feed.php          Plugin header and bootstrap
includes/class-plugin.php       Hooks, assets, Elementor registration
includes/class-admin.php        Settings page, connect / sync / disconnect, notices
includes/class-api-client.php   Instagram Graph API (Instagram Login)
includes/class-sync.php         Cron sync, token refresh, normalisation, pruning
includes/class-importer.php     Image download, WebP conversion, attachments
includes/class-feed.php         Read access for rendering
includes/class-options.php      Stored data
includes/elementor/class-widget.php  The Elementor widget
assets/css/slider.css           Slider styles (printed inline)
assets/js/slider.js             Slider controls (slider.min.js is generated)
languages/                      POT, de_CH and de_DE translations
```

Build an installable zip (minifies the script, recompiles translations when WP-CLI is available): `scripts/build-plugin.sh` from the repository root.

Not included yet: a "Log in with Instagram" button instead of pasting a token, hiding single posts from the admin screen (possible with `shd_igf_feed_items`), showing captions, and dragging the slider with the mouse (arrows, keyboard, touch and trackpad work).
