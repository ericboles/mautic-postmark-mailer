## Warning

This package is a work-in-progress. I'm not a PHP developer and since I do not know much about Mautic or its ecosystem using this package may not be a good idea.
I implemented Postmark support for my own mautic instance because Mautic v5 does not support Postmark anymore and v4 is deprecated.
Since Mautic is a big frustration for me since minute 1, I'm probably abandoning this package soon.

Although, if you want to thank me and want to keep seeing me struggle with PHP and Mautic you can buy me a coffee using the link below:

<a href="https://www.paypal.com/donate/?business=P8YLKWGH3E6XU&no_recurring=1&item_name=If+you+want+to+see+me+keep+struggling+with+PHP+and+Mautic%2C+make+me+happy+and+buy+me+a+coffee+%3A-%29&currency_code=EUR">
  <img src="https://raw.githubusercontent.com/stefan-niedermann/paypal-donate-button/master/paypal-donate-button.png" alt="Donate with PayPal" width="200" />
</a>

### Mautic Postmark Plugin

This plugin enable Mautic 5.x to run Postmark as an email transport. Features:

- API transport with tokenization support for multi-transport setups
- **Multi-transport compatibility**: Works correctly as both primary and secondary transport (compatible with MultipleTransportBundle)
- **Bounce webhook handling** with detailed bounce information. This plugin supports both Postmark webhook types:
  - **Bounce webhook**: Captures detailed bounce information including bounce type, description, SMTP details, and full bounce content for comprehensive debugging
  - **SubscriptionChange webhook**: Handles suppressions, manual unsubscribes, and re-subscribes
- **Campaign statistics tracking**: Bounce and unsubscribe events are properly linked to email campaigns for accurate reporting
- **Suppression list sync**: Automatically re-syncs contacts to Mautic DNC when sending fails due to Postmark suppression list
- **Re-Subscribe support**: The DNC flag will be removed when the webhook sends `SuppressSending: false`

#### Webhook Configuration

To enable full bounce tracking with detailed information, configure **both** webhooks in your Postmark server:

**Webhook URL**: `https://your-mautic-domain.com/mailer/callback`

1. **Bounce webhook** (recommended):
   - Provides detailed bounce information (type, description, SMTP details, full content)
   - Captures hard bounces and spam complaints with complete diagnostic data

2. **SubscriptionChange webhook**:
   - Handles manual suppressions and unsubscribes
   - Processes re-activations (when `SuppressSending: false`)

**Note**: Both webhooks use the same Mautic endpoint (`/mailer/callback`). This is Mautic's standard webhook endpoint for all email transports. The Postmark plugin will process webhooks from Postmark regardless of whether it's configured as the primary or secondary transport, identified by the `RecordType` field in the payload.

#### Multi-Transport Support

This plugin implements `TokenTransportInterface`, making it compatible with multi-transport setups (such as the MultipleTransportBundle). When Postmark is configured as a secondary transport:

- All recipients will receive emails correctly (not just the first recipient)
- Campaign emails and bulk sends work as expected
- Webhook processing works regardless of which transport is configured as primary

The plugin currently sends emails one-by-one for reliability. Future versions may implement Postmark's batch API (up to 500 emails per request) for improved performance.

Be aware that there is a existing symfony postmark bridge, but no recent version is compatible with Mautic 5 and has a webhook support.

### Using a different Message Stream for Transactional and Broadcast Messages

You can you different message streams on a per-email basis. You just have to add the `X-PM-Message-Stream` custom header with the value of your message stream to the mautic email.

#### Mautic Mailer DSN Scheme

`mautic+postmark+api`

#### Mautic Mailer DSN Example

`'mailer_dsn' => 'mautic+postmark+api://:<api_key>@default?messageStream=<messageStream>',`

- api_key: Get Postmark API key from your postmark server setting (`password` in the email configuration ui)
- options:
  - messageStream: the postmark message stream

<img width="1105" alt="configuration-example" src="Assets/img/configuration-example.png">

### Installation in Docker containers

The only "easy" way to use a custom plugin in a docker container is to build a custom image. There are a few things I had to find out the hard way, i.e.

- You have to set the right permissions to the plugin folder, otherwise this plugin won't be visible in the configuration ui
- You need to clear the mautic cache, otherwise - you guessed it - this plugin won't be visible in the configuration ui

This is what I use in my custom docker image

```Dockerfile
FROM mautic/mautic:5.2.3-apache

RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/bin --filename=composer

RUN apt-get update \
    && apt-get install --no-install-recommends -y \
    git \
    nodejs \
    npm


RUN chown www-data:www-data /var/www/html -R && \
    chown www-data:www-data /tmp -R

RUN chown www-data:www-data /var/www


RUN su -s /bin/bash www-data -c "composer require -vvv --working-dir=/var/www/html/ mariotebest/mautic-postmark-mailer:1.0.13"

# run cache clear as www-data otherwise the permissions will be messed up
RUN su -s /bin/bash www-data -c "php /var/www/html/bin/console cache:clear"

```

### Testing

To run all tests `composer phpunit`

To run unit tests `composer unit`

To run functional tests `composer functional`

### Static analysis tools

To run fixes by friendsofphp/php-cs-fixer `composer fixcs`

To run phpstan `composer phpstan`
