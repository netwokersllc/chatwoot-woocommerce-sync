# Chatwoot WooCommerce Sync

A small, self-hosted WordPress integration for **WooCommerce** and **Chatwoot**.
## What it does

- Embeds the Chatwoot web widget from your own Chatwoot instance.
- Identifies logged-in visitors using Chatwoot HMAC identity validation.
- Synchronizes WooCommerce customers and order statistics in the background.
- Creates Chatwoot conversations from forms and other WordPress workflows.
- Keeps API credentials in environment variables when desired.
- Uses Action Scheduler for outbound contact synchronization, avoiding network waits
  in the request that triggered a change.

This plugin does not provide Chatwoot hosting. You need a Chatwoot Cloud account or
an accessible self-hosted Chatwoot instance.

## Requirements

- WordPress 6.0+
- WooCommerce
- PHP 8.0+
- Chatwoot with API access and a web-widget inbox
- HTTPS in production

## Installation

1. Copy `chatwoot-woocommerce-sync` to `wp-content/plugins/`.
2. Activate it in WordPress.
3. Configure the variables below, or use **Settings → Chatwoot Sync**.
4. Confirm the Chatwoot API URL and widget on a staging page before production use.

## Configuration

Environment variables override values saved in WordPress:

| Variable | Purpose |
| --- | --- |
| `CHATWOOT_BASE_URL` | Chatwoot base URL, for example `https://chat.example.com` |
| `CHATWOOT_ACCOUNT_ID` | Chatwoot account ID |
| `CHATWOOT_API_ACCESS_TOKEN` | Chatwoot API access token |
| `CHATWOOT_WEBSITE_TOKEN` | Website token for the widget inbox |
| `CHATWOOT_HMAC_TOKEN` | Identity-validation secret for the widget inbox |
| `CHATWOOT_TOKEN_MAP` | Optional JSON map of per-language widget/HMAC tokens |
| `CHATWOOT_EMAIL_INBOX_ID` | Optional target inbox for created conversations |

Do not commit credentials. Environment variables are recommended for production.
The plugin does not disable TLS certificate verification.

## Public hooks

Create a conversation from another plugin or theme:

```php
do_action( 'cws_create_conversation', array(
    'email'   => 'customer@example.com',
    'name'    => 'Customer Name',
    'phone'   => '+34600000000',
    'subject' => 'Question about my order',
    'message' => 'The message body',
    'labels'  => array( 'contact-form' ),
) );
```

Available filters:

- `cws_render_widget` — whether to render the widget on the current request.
- `cws_autoload_widget` — whether to load it automatically.
- `cws_log_successes` — whether successful API calls are logged.

The plugin's PHP API is namespaced under `ChatwootWooSync`. Site-specific workflows
should call the public API instead of duplicating Chatwoot HTTP requests.

## Privacy and security

The plugin sends configured customer data to the configured Chatwoot instance. This
can include name, email, phone, locale, country, order count, lifetime value, and
last-order information. Configure consent and retention according to your legal and
privacy requirements.

The identity endpoint is same-origin and returns data only for the caller's current
session. Credentials are sent in request headers over TLS and are never intentionally
printed into page HTML.

## License

GPL-2.0-or-later. See [`LICENSE`](LICENSE).

Chatwoot is a separate project with its own license and hosting terms. This plugin
uses Chatwoot's documented API and web widget; it is not affiliated with or endorsed
by Chatwoot.

## Contributing

See [`CONTRIBUTING.md`](CONTRIBUTING.md). Security reports should follow
[`SECURITY.md`](SECURITY.md).
