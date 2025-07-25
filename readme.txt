=== BlockBee Cryptocurrency Payment Gateway ===
Contributors: blockbee
Tags: crypto payments, payment gateway, cryptocurrencies, payments,
Requires at least: 5.8
Tested up to: 6.8.2
Stable tag: 1.5.5
Requires PHP: 7.2
WC requires at least: 5.8
WC tested up to: 10.0.2
License: MIT

Accept cryptocurrency payments on your WooCommerce website

== Description ==

Accept payments in Bitcoin, Ethereum, Bitcoin Cash, Litecoin, Solana, BNB, USDT, SHIB, DOGE, and many more directly to your crypto wallet.

= Allow users to pay with crypto directly on your store =

The BlockBee plugin extends WooCommerce, allowing you to get paid in crypto directly on your store, with a simple setup.

= Accepted cryptocurrencies & tokens include: =

* (BTC) Bitcoin
* (ETH) Ethereum
* (BCH) Bitcoin Cash
* (LTC) Litecoin
* (TRX) Tron
* (BNB) Binance Coin
* (USDT) USDT
* (USDC) USDC
* (SHIB) Shiba Inu
* (DOGE) Dogecoin
* (SOL) Solana

among many others, for a full list of the supported cryptocurrencies and tokens, check [this page](https://blockbee.io/fees/).

= Auto-value conversion =

BlockBee will attempt to automatically convert the value you set on your store to the cryptocurrency your customer chose.

Exchange rates are fetched every 5 minutes from CoinGecko.

Supported currencies for automatic exchange rates are:

* (USD) United States Dollar
* (EUR) Euro
* (GBP) Great Britain Pound
* (CAD) Canadian Dollar
* (JPY) Japanese Yen
* (AED) UAE Dollar
* (MYR) Malaysian Ringgit
* (IDR) Indonesian Rupiah
* (THB) Thai Baht
* (CHF) Swiss Franc
* (COP) Colombian Peso
* (SGD) Singapore Dollar
* (RUB) Russian Ruble
* (ZAR) South African Rand
* (TRY) Turkish Lira
* (LKR) Sri Lankan Rupee
* (XAF) CFA Franc
* (RON) Romanian Leu
* (BGN) Bulgarian Lev
* (HUF) Hungarian Forint
* (CZK) Czech Koruna
* (PHP) Philippine Peso
* (PLN) Poland Zloti
* (UGX) Uganda Shillings
* (MXN) Mexican Peso
* (INR) Indian Rupee
* (HKD) Hong Kong Dollar
* (CNY) Chinese Yuan
* (BRL) Brazilian Real
* (DKK) Danish Krone

If your WooCommerce's currency is none of the above, the exchange rates will default to USD.
If you're using WooCommerce in a different currency not listed here and need support, please [contact us](https://blockbee.io/contacts/) via our live chat.

**Note:** BlockBee will not exchange your crypto for FIAT or other crypto, just convert the value

= Why choose BlockBee? =

BlockBee has no setup fees, no monthly fees, no hidden costs.
All you need to do is to register at our dashboard [click here](https://dash.blockbee.io/), set up your addresses, get an API Key, and you're ready to go.
As soon as your customers pay we forward your earnings directly to your own wallet.

BlockBee has a low 1% fee on the transactions processed. No hidden costs.
For more info on our fees [click here](https://blockbee.io/fees/)

== Installation ==

= Using The WordPress Dashboard =

1. Navigate to the 'Add New' in the plugins dashboard
2. Search for 'BlockBee Payment Gateway for WooCommerce'
3. Click 'Install Now'
4. Activate the plugin on the Plugin dashboard

= Uploading in WordPress Dashboard =

1. Navigate to the 'Add New' in the plugins dashboard
2. Navigate to the 'Upload' area
3. Select `woocommerce-blockbee.zip` from your computer
4. Click 'Install Now'
5. Activate the plugin in the Plugin dashboard

= Using FTP =

1. Download `blockbee-cryptocurrency-payment-gateway.zip`
2. Extract the `blockbee-cryptocurrency-payment-gateway` directory to your computer
3. Upload the `blockbee-cryptocurrency-payment-gateway` directory to the `/wp-content/plugins/` directory
4. Activate the plugin in the Plugin dashboard

= Updating =

Automatic updates should work like a charm; as always though, ensure you backup your site just in case.

== Configuration ==

1. Go to WooCommerce settings
2. Select the "Payments" tab
3. Activate the payment method (if inactive)
4. Set the name you wish to show your users on Checkout (for example: "Cryptocurrency")
5. Fill the payment method's description (for example: "Pay with cryptocurrency")
6. Select which cryptocurrencies you wish to accept (control/command + click to select many). Addresses are set in BlockBee dashboard.
7. Paste the API Key you got from your BlockBee dashboard.
8. Click "Save Changes"
9. All done!

== Frequently Asked Questions ==

= Do I need an API key? =

Yes. To use our service you will need to register at our [dashboard](https://dash.blockbee.io/) and create a new API Key.

= How long do payments take before they're confirmed? =

This depends on the cryptocurrency you're using. Bitcoin usually takes up to 11 minutes, Ethereum usually takes less than a minute.

= Is there a minimum for a payment? =

Yes, the minimums change according to the chosen cryptocurrency and can be checked [here](https://blockbee.io/cryptocurrencies/).
If the WooCommerce order total is below the chosen cryptocurrency's minimum, an error is raised to the user.

= Where can I find more documentation on your service? =

You can find more documentation about our service on our [website](https://blockbee.io/), our [technical documentation](https://docs.blockbee.io/get-started/woocommerce) page.
If there's anything else you need that is not covered on those pages, please get in touch with us, we're here to help you!

= Where can I get support? =

The easiest and fastest way is via our live chat on our [website](https://blockbee.io), via our [contact form](https://blockbee.io/contacts/), via [discord](https://discord.gg/pQaJ32SGrR) or via [telegram](https://t.me/blockbee_support).

== Screenshots ==

1. The settings panel used to configure the gateway
2. Set your crypto addresses (part 1)
3. Set your crypto addresses (part 2)
4. Example of payment using Litecoin
5. The QR code can be set to provide only the address or the address with the amount: the user can choose with one click
6. Once the payment is received, the system will wait for network confirmations
7. The payment is confirmed!

== Changelog ==

= 1.0.0 =
* Initial release.

= 1.0.1 =
* Minor fixes.

= 1.0.2 =
* Minor fixes.

= 1.0.3 =
* Minor fixes.
* UI improvements.

= 1.0.4 =
* Minor fixes.

= 1.0.5 =
* Minor fixes.

= 1.0.6 =
* Minor fixes.

= 1.0.7 =
* Minor fixes.
* Improvements on the callback processing algorithm

= 1.0.8 =
* Minor fixes.

= 1.0.9 =
* Minor fixes.

= 1.0.10 =
* Minor fixes.

= 1.0.11 =
* Minor fixes.

= 1.0.12 =
* Minor fixes.

= 1.0.13 =
* Minor fixes.

= 1.0.14 =
* Performance improvements.
* Minor fixes.

= 1.0.15 =
* Minor fixes.

= 1.0.16 =
* Minor fixes.

= 1.0.17 =
* Support for WooCommerce HPOS.
* Minor fixes.

= 1.0.18 =
* Add new choices for order cancellation.

= 1.0.19 =
* Minor fixes and improvements.

= 1.0.20 =
* Minor fixes and improvements.

= 1.1.0 =
* Support for new languages: German, French, Ukrainian, Russian and Chinese.

= 1.1.1 =
* Minor fixes and improvements.

= 1.1.2 =
* Minor fixes and improvements.

= 1.1.3 =
* Minor fixes and improvements.

= 1.1.4 =
* Minor fixes and improvements.

= 1.1.5 =
* Minor fixes and improvements.

= 1.1.6 =
* Minor improvements

= 1.1.7 =
* Minor improvements

= 1.2.0 =
* Support BlockBee Checkout page
* Minor improvements

= 1.2.1 =
* Minor fixes.

= 1.2.2 =
* Minor fixes.

= 1.2.3 =
* Minor fixes.

= 1.2.4 =
* Minor fixes.

= 1.2.5 =
* Minor fixes.

= 1.2.6 =
* Minor improvements.

= 1.2.7 =
* Minor improvements.
* Minor fixes.

= 1.2.8 =
* Minor improvements.
* Minor fixes.

= 1.3.0 =
* Version bump.

= 1.4.0 =
* Now supports WordPress Blocks.
* Bug fixes.

= 1.4.1 =
* Bug fixes.

= 1.4.2 =
* Bug fixes.

= 1.4.3 =
* Bug fixes.

= 1.5.0 =
* Callback improvements
* Minor bugfixes

= 1.5.2 =
* Minor bugfixes

= 1.5.3 =
* New customization options
* Minor bugfixes

= 1.5.4 =
* Minor bugfixes

= 1.5.5 =
* Minor bugfixes

== Upgrade Notice ==
