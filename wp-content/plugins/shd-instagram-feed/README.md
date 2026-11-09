# SHD Instagram Feed

Shows the latest Instagram posts in a fast, accessible slider, as an Elementor widget. Posts and images are synced in the background and stored on your own server, so visitors never load anything from Instagram.

Built to replace the hand-maintained Loop Carousel ("instagram-slider" posts) at the bottom of egligartenbau.com: the widget's defaults reproduce that look (3 / 2 / 1 posts, 35 px gap, square images, round grey arrows with a dark-green icon) and update automatically.

## Requirements

- WordPress 6.4 or newer, PHP 7.4 or newer
- Elementor 3.5 or newer (tested with Elementor 4.3.6 and Elementor Pro 4.3.3; Pro is not required)
- An Instagram **professional account** (Business or Creator) and a Meta developer app (free)

## Setup

1. Install the plugin: *Plugins → Add New → Upload Plugin* with `shd-instagram-feed-<version>.zip`, then activate it.
2. Set up the Instagram app (once; one app can serve all your client sites):
   1. On [developers.facebook.com/apps](https://developers.facebook.com/apps), create an app of the type **Business** with the Instagram use case.
   2. In the app, open *Instagram → API setup with Instagram business login → Set up Instagram business login → Business login settings* and add the site's **OAuth redirect URI** under *OAuth redirect URIs*. The plugin shows the exact address under *Settings → Instagram Feed* (for egligartenbau.com: `https://egligartenbau.com/wp-json/shd-igf/v1/oauth/`).
   3. Copy the **Instagram app ID** and **Instagram app secret** from the same page into *Settings → Instagram Feed → Instagram app*, or define them in `wp-config.php` so the secret stays out of the database:

      ```php
      define( 'SHD_IGF_APP_ID', '123456789012345' );
      define( 'SHD_IGF_APP_SECRET', 'your-app-secret' );
      ```
   4. Add the client's Instagram account to the app (*Generate access tokens → Add account*). Accounts that are not added can only log in after Meta has granted the app **Advanced Access** through App Review.

   The Instagram account must be a professional account (Business or Creator; switch in the Instagram app under Settings → *Account type and tools*).
3. In *Settings → Instagram Feed*, click **Log in with Instagram**, log in on Instagram and allow access. You come back to WordPress connected; the newest posts are imported right away (6 images per run, the rest follows in the background within a few minutes).
4. In Elementor, search for the **Instagram Feed** widget and drag it onto the page. On egligartenbau.com it replaces the Loop Carousel in the "Gartenbau, Poolbau und Gartenunterhalt aus Leidenschaft." section.

You only log in once: the plugin renews the token every week (Instagram tokens expire after 60 days without renewal). *Reconnect with Instagram* switches to another account.

Without the app setup you can still connect by pasting a token generated in the App Dashboard (*Connect with an access token instead*).

Meta's guides: [Business Login for Instagram](https://developers.facebook.com/docs/instagram-platform/instagram-api-with-instagram-login/business-login), [Instagram API with Instagram Login](https://developers.facebook.com/docs/instagram-platform/instagram-api-with-instagram-login/get-started).

### If the login doesn't work

- **"Invalid redirect URI" on Instagram:** the redirect URI in the Meta app must match the one on the settings page exactly, including `https` and the trailing slash.
- **The site isn't on https:** Instagram only redirects to https addresses.
- **You end up on an error page from `/wp-json/`:** a security plugin is blocking the REST API for logged-out visitors. Allow the route `shd-igf/v1/oauth`. The route is safe to allow: it only accepts a single-use login state created by an administrator in the last 15 minutes.
- **"Advanced Access" or "insufficient developer role" on Instagram:** add the account to the app (step 2.4) or get Advanced Access through App Review.

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

Visitors' browsers only load images from your own domain. The plugin never sends visitor data to Meta. The access token and the app secret are stored in the WordPress database (not autoloaded) and never printed back into pages. Use the `wp-config.php` constants to keep the app secret out of the database. The login only asks for `instagram_business_basic` (read profile and posts).

## For developers

Filters:

- `shd_igf_api_version` – Graph API version (default `v27.0`).
- `shd_igf_convert_to_webp` – return `false` to keep the original JPEGs.
- `shd_igf_webp_quality` – WebP quality (default `82`).
- `shd_igf_feed_items` – change or filter the posts before they are rendered, e.g. to hide a post by its `id`.
- `shd_igf_redirect_uri` – the OAuth redirect URI (default `rest_url( 'shd-igf/v1/oauth/' )`).

Constants: `SHD_IGF_APP_ID`, `SHD_IGF_APP_SECRET` (override the app settings).

Login flow: the button posts to `admin-post.php` (nonce, `manage_options`), which stores a random 40-character state for 15 minutes and redirects to `instagram.com/oauth/authorize` with scope `instagram_business_basic`. Instagram returns to the REST route `GET /wp-json/shd-igf/v1/oauth/`, which consumes the state (single use), exchanges the code for a short-lived token (`POST api.instagram.com/oauth/access_token`) and that for a 60-day token (`GET graph.instagram.com/access_token`), then saves it and runs the first sync. The app secret is only sent server-to-server.

Sync manually with WP-CLI: `wp cron event run shd_igf_sync`.

Stored data: options `shd_igf_settings`, `shd_igf_connection`, `shd_igf_feed`, `shd_igf_status`, `shd_igf_app` (all non-autoloaded) and attachments with the meta key `_shd_igf_media_id`. Deactivating stops the background sync; deleting the plugin removes the options and the imported images.

Code layout:

```
shd-instagram-feed.php          Plugin header and bootstrap
includes/class-plugin.php       Hooks, assets, Elementor registration
includes/class-admin.php        Settings page, connect / sync / disconnect, notices
includes/class-connector.php    Log in with Instagram (OAuth), connecting a token
includes/class-api-client.php   Instagram Graph API and OAuth endpoints (Instagram Login)
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

Not included yet: hiding single posts from the admin screen (possible with `shd_igf_feed_items`), showing captions, dragging the slider with the mouse (arrows, keyboard, touch and trackpad work), and a central connect service that would let sites log in without their own Meta app (like Smash Balloon's).
