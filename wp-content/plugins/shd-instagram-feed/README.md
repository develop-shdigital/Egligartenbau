# SHD Instagram Feed

Shows the latest Instagram posts in a fast, accessible slider, as an Elementor widget. Posts and images are synced in the background and stored on your own server, so visitors never load anything from Instagram.

Built to replace the hand-maintained Loop Carousel ("instagram-slider" posts) at the bottom of egligartenbau.com: the widget's defaults reproduce that look (3 / 2 / 1 posts, 35 px gap, square images, round grey arrows with a dark-green icon) and update automatically.

## Requirements

- WordPress 6.4 or newer, PHP 7.4 or newer
- Elementor 3.5 or newer (tested with Elementor 4.3.6 and Elementor Pro 4.3.3; Pro is not required)
- An Instagram **professional account** (Business or Creator)

## Setup

1. Install the plugin: *Plugins → Add New → Upload Plugin* with `shd-instagram-feed-<version>.zip`, then activate it.
2. Open *Settings → Instagram Feed* and click **Login to Instagram**. Log in on Instagram and allow access. You come back to WordPress connected, and the newest posts are imported right away (6 images per run, the rest follows in the background within a few minutes).
3. In Elementor, search for the **Instagram Feed** widget and drag it onto the page. On egligartenbau.com it replaces the Loop Carousel in the "Gartenbau, Poolbau und Gartenunterhalt aus Leidenschaft." section.

That's all on the site. The login is needed once: the plugin renews the token every week (Instagram tokens expire after 60 days without renewal). *Login to Instagram again* switches to another account.

The Instagram account must be a professional account (Business or Creator; switch in the Instagram app under Settings → *Account type and tools*).

### How the button works

Like Smash Balloon, the button logs in through one Meta app that belongs to SH Digital. Its secret must not be in the zip that client sites install, so the same plugin has a **login service mode**: it is switched on only on www.sh-digital.ch, where the app ID and secret are defined in `wp-config.php`. Client sites send the login there (`https://www.sh-digital.ch/wp-json/shd-igf/v1/connect/`) and get the token back. They never need an app, a secret or a redirect URI, and after the login they renew the token themselves.

*Advanced: connect with an access token* on the settings page is a fallback for when the login can't be used, e.g. before the login service is set up.

### Set up the login service (once, on www.sh-digital.ch)

1. **Meta app:** on [developers.facebook.com/apps](https://developers.facebook.com/apps) create an app of the type **Business** with the Instagram use case ("Manage messaging & content on Instagram"). Clients never see it.
2. **Plugin:** install this plugin on www.sh-digital.ch (Elementor is required anyway; a feed there is optional).
3. **wp-config.php** of www.sh-digital.ch, with the values from the app (*Instagram → API setup with Instagram business login → Set up Instagram business login → Business login settings*):

   ```php
   define( 'SHD_IGF_APP_ID', '123456789012345' );      // "Instagram app ID"
   define( 'SHD_IGF_APP_SECRET', 'your-app-secret' );  // "Instagram app secret"
   // Optional: only these sites may log in, e.g. 'egligartenbau.com, www.egligartenbau.com, *.sh-digital.ch'
   // define( 'SHD_IGF_ALLOWED_HOSTS', '' );
   ```
4. **Redirect URI:** *Settings → Instagram Feed* on www.sh-digital.ch now shows the card *Instagram login service* with the address to enter under **OAuth redirect URIs** in the same Meta settings: `https://www.sh-digital.ch/wp-json/shd-igf/v1/connect/`.
5. **Check:** `https://www.sh-digital.ch/wp-json/shd-igf/v1/connect/?health=1` returns `{"ok":true,…}`.

Which accounts can log in:

- While the Meta app is in development mode, only Instagram accounts added to the app can log in (*Instagram → API setup with Instagram business login → Generate access tokens → Add account*, or as Instagram testers under *App roles*). That is enough for testing and a few clients.
- To let any client log in without adding the account first, request **Advanced Access** for `instagram_business_basic` in *App Review* and switch the app to *Live*. Meta asks for business verification, a privacy policy URL and a short screencast of the login.

To run the login service on another site, define the constants there and point client sites to it with `define( 'SHD_IGF_CONNECT_URL', 'https://…/wp-json/shd-igf/v1/connect/' );`, or change `Connector::DEFAULT_CONNECT_URL` before building the zip.

### If the login doesn't work

- **A page from www.sh-digital.ch says the link is not valid:** start again from *Settings → Instagram Feed*. If it keeps happening, the site is not on https or not in `SHD_IGF_ALLOWED_HOSTS` of the login service.
- **Instagram says the app has no access (e.g. "Insufficient developer role"):** the Meta app is still in development mode. Add the account to the app or finish Meta's App Review (see above).
- **You end up on an error page from `/wp-json/`:** a security plugin blocks the REST API for logged-out visitors. Allow the route `shd-igf/v1/oauth`. It only accepts a login that an administrator of this site started in the last 15 minutes.
- **"The Instagram login link has expired":** the login took longer than 15 minutes or the page was reloaded. Click the button again.

## Choose which posts appear

*Settings → Instagram Feed → Posts on the website* shows all synced posts as thumbnails. Ticked posts appear in the widget; untick a post to hide it, then click **Save selection**.

*New posts from Instagram* decides what happens with posts published later:

- **Show automatically** (default): every new post appears; you only untick the ones you don't want.
- **Only show them once you tick them**: new posts stay hidden until you tick them. Ticked posts also stay on the website when they get older, even after they drop out of the newest *Posts to keep*: the plugin keeps their images and checks once a day that they still exist on Instagram. A ticked post that was deleted on Instagram disappears automatically.

The widget's *Number of posts* applies to the posts that are shown. In Elementor, the panel shows how many posts are shown and links to this page.

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

Visitors' browsers only load images from your own domain. The plugin never sends visitor data to Meta. The access token is stored in the WordPress database (not autoloaded) and never printed back into pages. Client sites never see the Meta app secret: it is only in the `wp-config.php` of the site running the login service, and only sent from there to Instagram. Tokens travel back in a POST body, never in an address. The login only asks for `instagram_business_basic` (read profile and posts).

## For developers

Filters:

- `shd_igf_api_version` – Graph API version (default `v27.0`).
- `shd_igf_convert_to_webp` – return `false` to keep the original JPEGs.
- `shd_igf_webp_quality` – WebP quality (default `82`).
- `shd_igf_feed_items` – change or filter the posts before they are rendered (after the selection on the settings page).
- `shd_igf_connect_url` – address of the login service.
- `shd_igf_service_allowed_hosts`, `shd_igf_service_redirect_uri` – login service mode.

Constants: `SHD_IGF_CONNECT_URL` (address of the login service); on the login service site `SHD_IGF_APP_ID`, `SHD_IGF_APP_SECRET`, optional `SHD_IGF_ALLOWED_HOSTS`.

Login flow: the button posts to `admin-post.php` (nonce, `manage_options`), which stores a random 40-character nonce for 15 minutes and sends the browser to the login service with a state (base64url JSON: return address `…/wp-json/shd-igf/v1/oauth/` and the nonce). The service (`GET …/wp-json/shd-igf/v1/connect/`, only registered when the app constants are defined) checks the return address (plugin route, https, optional allowlist) and sends the browser to `instagram.com/oauth/authorize` (scope `instagram_business_basic`). Instagram returns to the service, which exchanges the code for a short-lived and then a 60-day token with the app secret and posts the token back with an auto-submitting form. The client's REST route `POST …/wp-json/shd-igf/v1/oauth/` consumes the nonce (single use), checks the token with Instagram, saves it and runs the first sync.

Sync manually with WP-CLI: `wp cron event run shd_igf_sync`.

Stored data: options `shd_igf_settings`, `shd_igf_connection`, `shd_igf_feed`, `shd_igf_status`, `shd_igf_moderation` (all non-autoloaded) and attachments with the meta key `_shd_igf_media_id`. Deactivating stops the background sync; deleting the plugin removes the options and the imported images.

Code layout:

```
shd-instagram-feed.php          Plugin header and bootstrap
includes/class-plugin.php       Hooks, assets, Elementor registration
includes/class-admin.php        Settings page, connect / sync / disconnect, notices
includes/class-connector.php    Login to Instagram button and callback, connecting a token
includes/class-connect-service.php  Login service mode (Meta app, code exchange)
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

Not included yet: sorting the selected posts by hand, showing captions, dragging the slider with the mouse (arrows, keyboard, touch and trackpad work).
