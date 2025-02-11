## ⚠️ Warning

This package is a work-in-progress. I'm not a PHP developer and since I do not know much about Mautic or its ecosystem this package should not be used publicly.
I implemented Postmark support for my own mautic instance because Mautic v5 does not support Postmark anymore and v4 is deprecated.
Since Mautic is a big frustration for me since minute 1, I'm probably abandoning this package soon.

### Mautic Postmark Plugin

This plugin enable Mautic 5 to run Postmark as an email transport. Features:

- API transport.
- Bounce webhook handling. This plugin will unsubscribe contacts in Mautic based on the hard bounces while Postmark will take care of the soft bounce retries.

#### Mautic Mailer DSN Scheme

`mautic+postmark+api`

#### Mautic Mailer DSN Example

`'mailer_dsn' => 'mautic+postmark+api://:<api_key>@default?messageStream=<messageStream>',`

- api_key: Get Postmark API key from your postmark server setting
- options:
  - messageStream: the postmark message stream

<img width="1105" alt="postmark-email-dsn-example" src="Assets/img/postmark-email-dsn-example.png">

### Testing

To run all tests `composer phpunit`

To run unit tests `composer unit`

To run functional tests `composer functional`

### Static analysis tools

To run fixes by friendsofphp/php-cs-fixer `composer fixcs`

To run phpstan `composer phpstan`
