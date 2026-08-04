=== Chatwoot WooCommerce Sync ===
Contributors: chatwootwoosync
Tags: chatwoot, woocommerce, live chat, crm, support
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connects WooCommerce to a Chatwoot install: identified live chat, background contact sync, and contact-form conversations.

== Description ==

Chatwoot's own WordPress plugin embeds the chat widget and stops there. This plugin adds the parts a shop actually needs:

* **Identified live chat.** Logged-in visitors are authenticated to Chatwoot with `setUser()` and an HMAC identity hash, so agents see who they are talking to instead of a stream of anonymous visitors.
* **Background contact sync.** Registration, profile changes, address changes and order status changes push the customer to Chatwoot — name, phone, locale, country, order count, lifetime value and last order. Queued through Action Scheduler, so no request ever waits on the network.
* **Contact-form conversations.** Any form can open a real conversation in a Chatwoot inbox with one `do_action()`.

= Design notes =

**One canonical identifier.** Every path — widget, user sync, contact form — identifies a person by their e-mail address, lower-cased. Mixing identifier schemes across paths is the usual cause of duplicated and unlinkable contacts.

**Identity is never printed into the page.** The widget fetches it from a REST endpoint on each session. Writing per-user data into the HTML breaks on any site with full-page caching, where one visitor's cached page is served to the next.

**Secrets stay out of the database.** Every credential can be supplied through an environment variable, which always wins over the stored option.

**TLS verification is always on.** The API token travels in request headers.

== Configuration ==

Settings live under **Settings → Chatwoot Sync**. Each credential can instead be set in the environment:

* `CHATWOOT_BASE_URL` — e.g. `https://chat.example.com`
* `CHATWOOT_ACCOUNT_ID`
* `CHATWOOT_API_ACCESS_TOKEN` — Profile → Access Token in Chatwoot
* `CHATWOOT_WEBSITE_TOKEN` — website token of the web widget inbox
* `CHATWOOT_HMAC_TOKEN` — Inbox → Settings → Configuration → Identity Validation
* `CHATWOOT_EMAIL_INBOX_ID`

If the inbox has identity validation set to mandatory, the HMAC token is required or logged-in visitors will not be identified.

== Hooks ==

Create a conversation from any form:

`do_action( 'cws_create_conversation', [
    'email'   => 'customer@example.com',
    'name'    => 'Customer Name',
    'phone'   => '+34600000000',
    'subject' => 'Question about my order',
    'message' => 'The message body',
    'labels'  => [ 'contact-form' ],
] );`

Filters:

* `cws_render_widget` (bool) — whether to output the widget on this request.
* `cws_autoload_widget` (bool) — whether to open the widget automatically; defaults to checkout pages only.
* `cws_log_successes` (bool) — log successful API calls too; failures are always logged.

== Changelog ==

= 1.0.0 =
* Initial release.
