# Telecom verification API request samples

These files are integration placeholders for the Electoral Commission payment verification module. They do not contain working credentials and are not called by the application.

Before enabling either provider, obtain and confirm:

- Sandbox and production base URLs
- OAuth token URL, client ID, client secret, scopes, and subscription/API key
- The exact account-name or KYC verification endpoint and request/response fields
- Whether mutual TLS is required, and the client certificate, private key, CA chain, formats, and expiry policy
- The public source IP that MTN/Airtel must whitelist
- Rate limits, batch limits, timeout and retry rules, and provider error codes
- Data retention, consent, audit, and masking requirements

Replace every `{{PLACEHOLDER}}` only in protected server configuration. Never commit credentials or private keys to the application directory.

The proposed application mapping is:

| Provider response | System field |
| --- | --- |
| Provider transaction/reference ID | `provider_reference` |
| Registered subscriber/account name | `registered_name` |
| Locally calculated similarity | `match_percentage` |
| Request outcome | `match_status` and `error_message` |

Name similarity should be calculated locally after the provider returns the registered name. The provider should not be assumed to calculate the percentage unless its final API contract explicitly includes it.

