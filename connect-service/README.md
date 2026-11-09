# Instagram connect service

Makes the **Login to Instagram** button of the SHD Instagram Feed plugin work on every client site, with nothing to set up on the sites. It plays the role of Smash Balloon's connect server: one Meta app owned by SH Digital, one small script that holds the app secret.

```
WordPress site ──► connect service ──► Instagram login ──► connect service ──► WordPress site
  (button)          (adds app ID)       (client allows)     (code → 60-day      (saves token,
                                                             token, secret       imports posts)
                                                             stays here)
```

The service is only used while logging in. Afterwards each site renews its token itself every week, so the service can be offline without affecting any feed. It stores nothing.

## Set up once

1. **Meta app:** on [developers.facebook.com/apps](https://developers.facebook.com/apps) create an app of the type **Business** with the Instagram use case ("Manage messaging & content on Instagram"). This is SH Digital's app; clients never see the dashboard.
2. **Host the service** on an https address, for example `https://connect.shdigital.ch/instagram/`: upload this folder (PHP 7.4+ with curl; Apache uses the included `.htaccess`, on nginx deny access to `config.php`).
3. **Register the address:** in the app open *Instagram → API setup with Instagram business login → Set up Instagram business login → Business login settings* and add the service address under **OAuth redirect URIs**, exactly as it will be used, with the trailing slash.
4. **Configure:** copy `config.sample.php` to `config.php` and enter the *Instagram app ID*, *Instagram app secret* (same settings page) and the service address. Environment variables (`SHD_IGF_APP_ID`, `SHD_IGF_APP_SECRET`, `SHD_IGF_REDIRECT_URI`, `SHD_IGF_ALLOWED_HOSTS`) work too and take priority.
5. **Check:** `https://connect.shdigital.ch/instagram/?health=1` should return `{"ok":true,…}`.

If you host the service anywhere else, set that address in the plugin: change `Connector::DEFAULT_CONNECT_URL` before building the zip, or define `SHD_IGF_CONNECT_URL` in a site's `wp-config.php`.

## Which accounts can log in

- While the Meta app is in development, only Instagram accounts that were added to the app can log in (*Instagram → API setup with Instagram business login → Generate access tokens → Add account*, or as Instagram testers under *App roles*). That is enough for testing and for a few clients.
- To let any client log in without adding the account first, request **Advanced Access** for `instagram_business_basic` in *App Review* and switch the app to *Live*. Meta asks for business verification, a privacy policy URL and a short screencast of the login, which you can record with any test site.

The account must be an Instagram professional account (Business or Creator).

## Security

- The app secret is only in `config.php` (or the environment) on this server and is only sent to Instagram.
- The token reaches the WordPress site in a POST body (auto-submitting form), never in an address, so it doesn't end up in browser history or server logs.
- Only return addresses that point to the plugin's route (`…/wp-json/shd-igf/v1/oauth/`) on https are accepted. The WordPress site accepts a token only together with the single-use nonce it created for the administrator who clicked the button, within 15 minutes.
- Optional `allowed_hosts` limits the service to your client domains. Without it, any site running the plugin can use your app to connect its own account. The access granted is read-only (`instagram_business_basic`).
- Web server access logs contain Instagram's one-time authorization codes (valid for one hour, single use, worthless without the secret). Turn off access logging for this folder if you prefer.
