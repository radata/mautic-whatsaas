# Mautic WhatSaaS WhatsApp Plugin

Mautic 7.x plugin for sending and receiving WhatsApp messages via [WhatSaaS](https://whatsaas.com) / Evolution API. Supports multiple WhatsApp channels (phone numbers), incoming message tracking, read receipts, and campaign automation.

## Features

- **Multi-channel**: Configure multiple WhatsApp numbers, each with its own API key and instance
- **Manual send**: "Send WhatsApp" button on contact pages with channel selector and media support
- **Campaign automation**: Registers as SMS transport — use WhatsApp in Mautic campaign flows
- **Incoming messages**: Webhook receiver logs incoming WhatsApp messages on contact timeline
- **Read receipts**: Track message delivery and read status for engagement scoring
- **Media support**: Send text, image, video, document, and audio messages
- **REST API**: Send WhatsApp messages programmatically via Mautic API
- **Contact matching**: Incoming messages matched to contacts by phone number (whatsapp/mobile/phone fields)
- Works with any WhatSaaS / Evolution API instance (no hardcoded domains)

## Requirements

- Mautic 7.x (Docker FPM image)
- PHP 8.0+
- A WhatSaaS or Evolution API instance with API access

## Installation

### Via Composer (Docker)

Ensure the composer and npm directories exist with correct permissions:

```bash
docker exec --user root mautic_web mkdir -p /var/www/.composer/cache
docker exec --user root mautic_web chown -R www-data:www-data /var/www/.composer
docker exec --user root mautic_web mkdir -p /var/www/.npm
docker exec --user root mautic_web chown -R www-data:www-data /var/www/.npm
```

Allow dev packages (only needed once per Mautic installation):

```bash
docker exec --user www-data --workdir /var/www/html mautic_web \
  composer config minimum-stability dev
docker exec --user www-data --workdir /var/www/html mautic_web \
  composer config prefer-stable true
```

Add the GitHub repository and install the plugin:

```bash
docker exec --user www-data --workdir /var/www/html mautic_web \
  composer config repositories.mautic-whatsaas vcs \
  https://github.com/radata/mautic-whatsaas --no-interaction
docker exec --user www-data --workdir /var/www/html mautic_web \
  composer require radata/mautic-whatsaas:dev-main \
  -W --no-interaction --ignore-platform-req=ext-gd
```

### Post-Installation

Clear cache, reload plugins, then enable in UI:

```bash
docker exec --user www-data mautic_web rm -rf /var/www/html/var/cache/prod
docker exec --user www-data --workdir /var/www/html mautic_web php bin/console cache:warmup --env=prod
docker exec --user www-data --workdir /var/www/html mautic_web php bin/console mautic:plugins:reload
```

1. Go to **Settings > Plugins > WhatSaaS WhatsApp**
2. Set **Published** to **Yes**
3. Configure features (see Configuration below)
4. Custom fields are created automatically on install (see Custom Fields below)

### Update

```bash
docker exec --user www-data --workdir /var/www/html mautic_web \
  composer clear-cache && \
docker exec --user www-data --workdir /var/www/html mautic_web \
  composer update radata/mautic-whatsaas -W --no-interaction --ignore-platform-req=ext-gd
docker exec --user www-data mautic_web rm -rf /var/www/html/var/cache/prod
docker exec --user www-data --workdir /var/www/html mautic_web php bin/console cache:warmup --env=prod
docker exec --user www-data --workdir /var/www/html mautic_web php bin/console mautic:plugins:reload
```

## Configuration

In the plugin settings (Features tab):

| Field | Description | Default |
|---|---|---|
| **API URL** | Your WhatSaaS / Evolution API base URL | `https://wa.hollandworx.nl` |
| **Channels (JSON)** | JSON array of WhatsApp channels (see below) | Example JSON |
| **Webhook Secret** | Optional shared secret for securing the webhook endpoint | Empty |

### Channel Configuration

Channels are defined as a JSON array. Each channel represents a WhatsApp number/instance:

```json
[
  {
    "name": "Main Business",
    "apiKey": "sk_live_your_api_key_here",
    "instanceName": "my-instance-name",
    "default": true
  },
  {
    "name": "Support Line",
    "apiKey": "sk_live_another_key",
    "instanceName": "support-instance",
    "default": false
  }
]
```

| Field | Required | Description |
|---|---|---|
| `name` | Yes | Display name shown in dropdowns |
| `apiKey` | Yes | Bearer token for API authentication (`sk_live_...`) |
| `instanceName` | Yes | WhatSaaS instance identifier |
| `default` | No | Mark one channel as default for campaign sends |

The channel marked `"default": true` is used for automated campaign sends. If no channel is marked default, the first one is used.

## Custom Fields

The plugin creates two custom contact fields on install/update:

| Field | Alias | Type | Description |
|---|---|---|---|
| **Whatsapp** | `whatsapp` | Phone (tel) | WhatsApp phone number — used as **primary** number for sending |
| **Contact Pref WhatsApp** | `contact_pref_whatsapp` | Boolean | Reflects end-user WhatsApp preference (informational) |

### Phone Number Priority

When sending a WhatsApp message, the plugin looks for a phone number in this order:

1. `whatsapp` custom field (primary)
2. `mobile` core field
3. `phone` core field

### Do Not Contact (DNC)

The plugin registers **`whatsapp`** as a native Mautic DNC channel, alongside `email` and `sms`. This means:

- Contacts can be added to the WhatsApp DNC list via the standard Mautic DNC interface
- Both manual sends and campaign sends check the WhatsApp DNC list before sending
- DNC entries are managed in the same way as email/sms (manual, unsubscribed, bounced)
- The `contact_pref_whatsapp` field is **informational only** — it reflects end-user preferences but does **not** block sending. Use the DNC system to block contacts.

## Usage

### Manual Send (Contact Page)

A **"Send WhatsApp"** button appears on contact detail pages. Click it to open a modal with:

- **Channel selector** — pick which WhatsApp number to send from (pre-selected to default)
- **Message type** — Text, Image, Video, Document, or Audio
- **Message** — Supports contact field tokens: `{contactfield=firstname}`, `{contactfield=lastname}`, `{contactfield=email}`, `{contactfield=phone}`, `{contactfield=mobile}`
- **Media URL** — For non-text messages, provide a public URL to the media file

### Campaign Automation (SMS Transport)

The plugin registers as an SMS transport called **WhatSaaS**. To use it in campaigns:

1. Go to **Settings > Configuration > Text Message Settings**
2. Select **WhatSaaS** as the SMS transport
3. Create SMS messages and use them in campaign flows
4. Campaign sends always use the default channel

### REST API

Send a WhatsApp message to a contact using an SMS template:

```bash
curl -X GET "https://your-mautic.com/api/whatsaas/{smsId}/contact/{contactId}/send?channel=instance-name" \
  -H "Authorization: Bearer YOUR_MAUTIC_TOKEN"
```

The `channel` query parameter is optional — omit it to use the default channel.

### WhatSaaS Send API

The plugin calls the WhatSaaS API with:

```bash
POST {api_url}/api/v1/send
Authorization: Bearer {channel.apiKey}
Content-Type: application/json

{
  "instanceName": "my-instance",
  "number": "5511999999999",
  "type": "text",
  "message": "Hello!"
}
```

Supported types: `text`, `image`, `video`, `document`, `audio`. For non-text types, include `mediaUrl` with a public URL.

## Webhook (Incoming Messages & Read Receipts)

The plugin exposes a webhook endpoint to receive events from WhatSaaS:

```
POST https://your-mautic.com/whatsaas/webhook
```

### Setup in WhatSaaS

Configure your WhatSaaS instance to send webhook events to the URL above. If you set a **Webhook Secret** in the plugin settings, also configure the same secret as the `X-Webhook-Secret` header in WhatSaaS.

### Events Handled

| Event | What it does |
|---|---|
| `messages.upsert` | Incoming WhatsApp messages are logged on the matched Mautic contact's timeline |
| `messages.update` | Delivery and read receipts update the status of sent messages |
| `connection.update` | Instance connection state changes are logged |

### Contact Matching

Incoming messages are matched to Mautic contacts by phone number:

1. The WhatsApp JID (e.g. `31612345678@s.whatsapp.net`) is converted to E.164 format (`+31612345678`)
2. The plugin searches the contact's **whatsapp** field first, then **mobile**, then **phone**
3. Multiple number formats are checked: `+31612345678`, `31612345678`, `0612345678`, `06-12345678`
4. Group messages (`@g.us`) are ignored
5. Outgoing messages (sent by you) are skipped for incoming logging

### Activity Timeline

Incoming WhatsApp messages appear on the contact's timeline as SMS stats with:

- Direction: incoming
- Channel: whatsapp
- Instance name
- Sender's WhatsApp display name (pushName)
- Message content (text, captions, or media type indicators like `[Image]`, `[Voice message]`)

### Read Receipt Tracking

When a sent message is delivered or read, the plugin updates the corresponding stat entry with:

- `whatsapp_status`: `sent` → `delivered` → `read`
- `status_updated`: timestamp of the status change

This data can be used for engagement scoring in Mautic segments and campaigns.

## Plugin Structure

```
plugins/WhatSaasBundle/
├── Config/config.php                       # Routes, services, plugin metadata
├── Integration/
│   └── WhatSaasIntegration.php            # Settings UI (API URL, channels, webhook secret)
├── Transport/
│   ├── WhatSaasTransport.php              # Core transport (sends via WhatSaaS API)
│   ├── Configuration.php                   # Loads/parses channels from settings
│   └── ConfigurationException.php          # Custom exception
├── Controller/
│   ├── WhatsappController.php             # Modal send dialog
│   └── Api/
│       ├── WhatsappApiController.php      # REST API for sending
│       └── WebhookController.php          # Receives WhatSaaS webhook events
├── Event/
│   └── WebhookEvent.php                   # Webhook event object
├── EventListener/
│   ├── ButtonSubscriber.php               # "Send WhatsApp" button on contacts
│   ├── ChannelSubscriber.php              # Registers 'whatsapp' DNC channel
│   ├── PluginSubscriber.php               # Creates custom fields on install/update
│   └── WebhookSubscriber.php              # Processes incoming messages & read receipts
├── Helper/
│   └── FieldInstaller.php                 # Creates whatsapp & contact_pref custom fields
├── Form/Type/
│   └── SendWhatsappType.php               # Send form with channel dropdown
├── Resources/views/SendWhatsapp/
│   └── form.html.twig                     # Modal template
├── Translations/en_US/messages.ini
├── WhatSaasBundle.php                     # Bundle class
├── WhatSaasEvents.php                     # Event constants
└── composer.json
```

## Phone Number Normalization

All phone numbers are normalized to E.164 format:

- Numbers starting with `+` are kept as-is
- Numbers starting with `0` (Dutch local format) get `+31` prepended: `0612345678` → `+31612345678`
- All other numbers get `+` prepended

For incoming webhook matching, the plugin also searches formatted variants (`06-12345678`, `06 123 45678`) to handle different contact data formats.

## Uninstall

```bash
docker exec --user www-data --workdir /var/www/html mautic_web \
  composer remove radata/mautic-whatsaas -W --no-interaction
docker exec --user www-data --workdir /var/www/html mautic_web \
  composer config --unset repositories.mautic-whatsaas
docker exec --user www-data mautic_web rm -rf /var/www/html/var/cache/prod
docker exec --user www-data --workdir /var/www/html mautic_web php bin/console cache:warmup --env=prod
docker exec --user www-data --workdir /var/www/html mautic_web php bin/console mautic:plugins:reload
```

## Troubleshooting

### Log Files

Check for WhatSaaS entries in Mautic logs:

```bash
docker exec mautic_web grep -i whatsaas /var/www/html/var/logs/mautic_prod-$(date +%Y-%m-%d).php
```

### Plugin enabled but not sending

1. **Save feature settings**: Go to Settings > Plugins > WhatSaaS WhatsApp > **Features** tab and click **Save**. Publishing alone does not persist feature settings.

2. **Clear cache** after any plugin file changes:
   ```bash
   docker exec --user www-data mautic_web rm -rf /var/www/html/var/cache/prod
   docker exec --user www-data --workdir /var/www/html mautic_web php bin/console cache:warmup --env=prod
   ```

3. **Verify channels JSON** is valid: paste it into a JSON validator.

4. **Test the API directly**:
   ```bash
   curl -X POST "https://your-whatsaas-instance.com/api/v1/send" \
     -H "Authorization: Bearer sk_live_your_key" \
     -H "Content-Type: application/json" \
     -d '{"instanceName":"my-instance","number":"31612345678","type":"text","message":"Test from Mautic"}'
   ```

### Webhook not receiving events

1. Verify the webhook URL is accessible from WhatSaaS: `https://your-mautic.com/whatsaas/webhook`
2. Check that the `X-Webhook-Secret` header matches the configured secret
3. Verify the contact has a matching phone number in the mobile or phone field

## License

MIT - see [LICENSE](LICENSE) for details.
