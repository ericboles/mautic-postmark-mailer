## Warning

This package is a work-in-progress. I'm not a PHP developer and since I do not know much about Mautic or its ecosystem using this package may not be a good idea.
I implemented Postmark support for my own mautic instance because Mautic v5 does not support Postmark anymore and v4 is deprecated.
Since Mautic is a big frustration for me since minute 1, I'm probably abandoning this package soon.

Although, if you want to thank me and want to keep seeing me struggle with PHP and Mautic you can buy me a coffee using the link below:

[![Donate with PayPal](https://raw.githubusercontent.com/stefan-niedermann/paypal-donate-button/master/paypal-donate-button.png)](https://www.paypal.com/donate/?business=P8YLKWGH3E6XU&no_recurring=1&item_name=If+you+want+to+see+me+keep+struggling+with+PHP+and+Mautic%2C+make+me+happy+and+buy+me+a+coffee+%3A-%29&currency_code=EUR)

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
