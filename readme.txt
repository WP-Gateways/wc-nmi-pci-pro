=== WP NMI Gateway PCI for WooCommerce ===
Contributors: mohsinoffline
Donate link: https://wpgateways.com/support/send-payment/
Tags: nmi, network merchants, payment gateway, woocommerce, pci, pci-dss, tokenization, woocommerce subscriptions, recurring payments, pre order
Plugin URI: https://bitbucket.org/pledged/wc-nmi-pci-pro
Author URI: https://pledgedplugins.com
Requires at least: 4.4
Tested up to: 5.4.2
Requires PHP: 5.6
Stable tag: 1.0.1
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

This plugin enables you to use the NMI payment gateway and accept credit cards directly on your WooCommerce powered WordPress e-commerce website in a PCI compliant manner without redirecting customers away to the gateway website.

== Description ==

[NMI](https://www.nmi.com/) (Network Merchants) provides all the tools and services for merchants to accept almost any kind of payment online making the perfect solution for accepting credit, debit and electronic payments online.

[WooCommerce](https://woocommerce.com/) is one of the oldest and most powerful e-commerce solutions for WordPress. This platform is very widely supported in the WordPress community which makes it easy for even an entry level e-commerce entrepreneur to learn to use and modify.

#### Features
* **Easy Install**: Like all Pledged Plugins add-ons, this plugin installs with one click. After installing, you will have only a few fields to fill out before you are ready to accept credit cards on your store.
* **Secure Credit Card Processing**: Uses [Collect.js](https://secure.nmi.com/merchants/resources/integration/download.php?document=collectjs) tokenization library to send secure payment data directly to NMI so no worries about certifying with PCI-DSS.
* **Refund via Dashboard**: Process full or partial refunds, directly from your WordPress dashboard! No need to search order in your NMI account.
* **Authorize Now, Capture Later**: Optionally choose only to authorize transactions, and capture at a later date.
* **Restrict Card Types**: Optionally choose to restrict certain card types and the plugin will hide its icon and provide a proper error message on checkout.
* **Gateway Receipts**: Optionally choose to send receipts from your NMI merchant account.
* **Logging**: Enable logging so you can debug issues that arise if any.

#### Requirements
* Active  [NMI](https://www.nmi.com/)  account.
* [**WooCommerce**](https://woocommerce.com/)  version 3.0.0 or later.
* A valid SSL certificate is required to ensure your customer credit card details are safe and make your site PCI DSS compliant. This plugin does not store the customer credit card numbers or sensitive information on your website.
#### Extend, Contribute, Integrate
Contributors are welcome to send pull requests via [Bitbucket repository](https://bitbucket.org/pledged/wc-nmi-pci-pro).

For custom payment gateway integration with your WordPress website, please [contact us here](https://wpgateways.com/support/custom-payment-gateway-integration/).

#### Disclaimer
This plugin is not affiliated with or supported by NMI, WooCommerce.com or Automattic. All logos and trademarks are the property of their respective owners.

== Installation ==

1. Upload `wp-nmi-gateway-pci-woocommerce` folder/directory to the `/wp-content/plugins/` directory
2. Activate the plugin (WordPress -> Plugins).
3. Go to the WooCommerce settings page (WordPress -> WooCommerce -> Settings) and select the Payments tab.
4. Under the Payments tab, you will find all the available payment methods. Find the 'NMI' link in the list and click it.
5. On this page you will find all of the configuration options for this payment gateway.
6. Enable the method by using the checkbox.
7. Enter the NMI API keys (Private Key and Public Key).


That's it! You are ready to accept credit cards with your NMI merchant account now connected to WooCommerce.

== Frequently Asked Questions ==

= Is SSL Required to use this plugin? =
A valid SSL certificate is required to ensure your customer credit card details are safe and make your site PCI DSS compliant. This plugin does not store the customer credit card numbers or sensitive information on your website.

== Changelog ==

= 1.0.1 =
Updated “WC tested up to” header to 4.2

= 1.0.0 =
* Initial release version