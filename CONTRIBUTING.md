# Contributing

Thanks for helping improve Chatwoot WooCommerce Sync.

## Development rules

- Keep the plugin independent of the Networkers theme.
- Put Chatwoot API, identity, widget, synchronization, and logging behavior here.
- Keep site-specific business workflows in the consuming site integration layer.
- Never commit API tokens, HMAC secrets, customer exports, or production logs.
- Preserve WordPress and WooCommerce compatibility documented in the plugin header.
- Use WordPress escaping, nonces, capability checks, and TLS verification.

## Before opening a pull request

- Run `php -l` on changed PHP files.
- Test activation and deactivation on a disposable WordPress installation.
- Test an anonymous widget request and a logged-in identity request.
- Test customer sync through Action Scheduler.
- Test conversation creation and failure handling.
- Describe any Chatwoot API or privacy impact.

## License

Contributions are licensed under GPL-2.0-or-later, consistent with the project.
