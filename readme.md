=== Network Merchants (NMI) Payment Gateway For WooCommerce (Pro) ===  
Contributors: Pledged Plugins  
Tags: woocommerce Network Merchants (NMI), Network Merchants (NMI), payment gateway, woocommerce, woocommerce payment gateway  
Plugin URI: https://pledgedplugins.com/products/nmi-network-merchants-payment-gateway-woocommerce/  
Requires at least: 4.0  
Tested up to: 5.4  
License: GPLv2 or later  
License URI: http://www.gnu.org/licenses/gpl-2.0.html  

This Payment Gateway For WooCommerce extends the functionality of WooCommerce to accept payments from credit/debit cards using the Network Merchants (NMI) payment gateway. Since customers will be entering credit cards directly on your store you should sure that your checkout pages are protected by SSL.

== Description ==

`Network Merchants (NMI) Payment Gateway for WooCommerce` allows you to accept credit cards directly on your WooCommerce store by utilizing the Network Merchants (NMI) payment gateway.

= Features =

1. Accept Credit Cards directly on your website by using the Network Merchants (NMI) gateway.
2. No redirecting your customer back and forth.
3. Very easy to install and configure. Ready in Minutes!
4. Safe and secure method to process credit cards using the Network Merchants (NMI) payment gateway.
5. Internally processes credit cards, safer, quicker, and more secure!

If you need any assistance with this or any of our other plugins, please visit our support portal:  
https://pledgedplugins.com/support

== Installation ==

Easy steps to install the plugin:

1. Upload `nmi-payment-woocommerce` folder/directory to the `/wp-content/plugins/` directory.
2. Activate the plugin (WordPress -> Plugins).
3. Go to the WooCommerce settings page (WordPress -> WooCommerce -> Settings) and select the Payments tab.
4. Under the Payments tab, you will find all the available payment methods. Find the 'Network Merchants (NMI)' link in the list and click it.
5. On this page you will find all of the configuration options for this payment gateway.
6. Enable the method by using the checkbox.
7. Enter the Network Merchants (NMI) account details (Username, Password)

That's it! You are ready to accept credit cards with your Network Merchants (NMI) payment gateway now connected to WooCommerce.

== Frequently Asked Questions ==

`Is SSL Required to use this plugin?`  
A valid SSL certificate is required to ensure your customer credit card details are safe and make your site PCI DSS compliant. This plugin does not store the customer credit card numbers or sensitive information on your website.

`Does the plugin support direct updates from the WP dashboard?`  
Yes. You can navigate to WordPress -> Tools -> WooCommerce NMI License page and activate the license key you received with your order. Once that is done you will be able to directly update the plugin to the latest version from the WordPress dashboard itself.

== Changelog ==

5.0.0  
Modified nmi_request() method to use wp_remote_post() to make it fully GPL compatible  
Removed NMI SDK files  
Added filters for NMI request parameters and transaction POST URL  
Updated "WC tested up to" header to 4.0  

4.0.13  
Updated "WC tested up to" header to 3.9  

4.0.12  
Made compatible with WooCommerce Sequential Order Numbers Pro  
Fixed order status not changing to Failed on decline  
Updated "WC tested up to" header to 3.8  

4.0.11  
Updated "WC tested up to" header to 3.7  

4.0.10  
Removed currency restrictions  

4.0.9  
Fixed customer sites crashing on update  

4.0.8  
Removed $_POST fields from being sent in gateway requests  

4.0.7  
Replaced deprecated function "reduce_order_stock" with "wc_reduce_stock_levels"  

4.0.6  
Updated "WC tested up to" header to 3.6  

4.0.5  
Fixed PHP notices  
Changed logging method  
Removed deprecated script code  
Updated post meta saving method  
Prevented the "state" parameter from being sent in "capture" or "void" transactions  

4.0.4  
Integrated auto-update API  

4.0.3  
Added GDPR retention setting and logic  

4.0.2  
Added GDPR privacy support  
Added "minimum required" and "tested upto" headers  

4.0.1  
Added JCB, Maestro and Diners Club as options for allowed card types  
Made state field default to "NA" to support countries without a state  
Convert card expiry date to MM/YY before processing when customer enters it in MM/YYYY format  
Passed billing details to "Pay for Order" page  

4.0.0  
Added "authorize only" option  
Added logging option  
Added option to restrict card types  
Added test mode option and made HTTPS mandatory for live mode  
Complete overhaul of the plugin with massive improvements to the code base  

3.5.1  
Made "Order Received" link dynamic  
Included customer IP in the data sent to the gateway  
Added POT file for translation  

3.5  
Fixed compatibility issues with other payment gateway plugins  

3.2.1  
Compatible to WooCommerce 2.3.x  
Compatible to WordPress 4.x  

3.0  
Compatible to WooCommerce 2.2.2  
Compatible to WordPress 4.0  

2.0  
Compatible to WooCommerce 2.1.1