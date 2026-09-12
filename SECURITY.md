# Security policy

## Reporting a vulnerability

Please do not open a public issue for a security vulnerability. Contact Networkers
LLC privately through the organization’s security contact or GitHub private
vulnerability reporting.

Include:

- A description and impact
- Reproduction steps or a proof of concept
- Affected version
- Any suggested mitigation

Do not include production credentials, customer data, or live API tokens.

## Security scope

Pay particular attention to:

- REST endpoints and same-origin identity responses
- HMAC identity validation
- Capability and nonce checks in settings and admin actions
- Chatwoot API token handling
- Customer data sent to Chatwoot
- Action Scheduler retry behavior and log contents

We will acknowledge valid reports and coordinate disclosure and a fix where practical.
