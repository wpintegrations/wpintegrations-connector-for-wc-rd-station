=== WPIntegrations Connector for WooCommerce and RD Station ===
Contributors: wpintegrations
Tags: rd station, woocommerce, leads, orders, conversions
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Send WooCommerce orders to RD Station Marketing as conversion events, with field mapping and lead attribution.

== Description ==

**WPIntegrations Connector** sends your WooCommerce orders to RD Station Marketing as conversion events — automatically when an order reaches the status you choose, and with the lead source attribution intact.

= Why this plugin =

* **You choose the moment** — each integration listens to one order status. Processing (a paid order) is the default; Completed, On hold or any other status works too. An order is sent once per status, however often it moves back and forth.
* **Attribution preserved** — the customer's RD Station tracking cookies (`rdtrk`, `__trf.src`) are saved with the order at checkout and forwarded with the conversion, even when the order is marked paid or completed hours later in the admin.
* **Field mapping UI** — map billing and shipping details, order total, products, coupons, payment method and WooCommerce's own UTM attribution to RD Station fields, custom fields from your own account included. Custom checkout fields, such as CPF or CNPJ added by a Brazilian checkout plugin, are discovered from your recent orders, so nothing has to be typed.
* **Doesn't hold up checkout** — on hosts running PHP-FPM or LiteSpeed (most of them), delivery happens after the customer already has their confirmation, so a slow RD Station doesn't slow your checkout down.
* **LGPD ready** — map your checkout's consent checkbox and the plugin sends the corresponding legal basis with each conversion.
* **Full delivery log** — see every request, response and failure. Nothing is silent.
* **Secure by design** — your OAuth tokens are stored encrypted, every admin endpoint requires administrator capability, and nothing is ever sent anywhere except RD Station's API.
* **Modern WooCommerce** — compatible with High-Performance Order Storage (HPOS) and the block-based checkout.

= What this free version includes =

* Unlimited integrations, each sending orders that reach a status to RD Station Marketing
* Unlimited mapped fields per integration
* Your own conversion identifier per integration
* Order fields, your custom checkout fields and your account's real custom fields offered in the mapping screen
* LGPD consent mapping and RD Station attribution cookies forwarded with every conversion
* A delivery log with the full request and response for every send

= What the Pro version adds =

* **RD Station CRM as a destination** — every order becomes a contact and a deal in your CRM, with the order's products and value, matched on email so a returning customer updates the record they already have
* Deals marked won or lost as the order completes, is cancelled or refunded
* Tags, funnel stage, lead owner and the opportunity flag
* A delivery queue that retries failed sends automatically

[Learn more about the Pro version](https://wpintegrations.net/plugins/)

= Source code and third-party libraries =

The complete, human-readable source of this plugin is public at [github.com/wpintegrations/wpintegrations-connector-for-wc-rd-station](https://github.com/wpintegrations/wpintegrations-connector-for-wc-rd-station).

The admin screens are a React app. The compiled bundle in `build/assets/` is generated with Vite from the source in `resources/js/`, which ships with the plugin together with `package.json` and `vite.config.mjs`. To rebuild it, install Node.js 18 or later and pnpm, then run `pnpm install` and `pnpm run build` in the plugin folder.

The bundle includes these open-source libraries:

* React and React DOM 18.3.1 (MIT) — [github.com/facebook/react](https://github.com/facebook/react)
* Ant Design 5.29.3 (MIT) — [github.com/ant-design/ant-design](https://github.com/ant-design/ant-design)
* Ant Design Icons 5.6.1 (MIT) — [github.com/ant-design/ant-design-icons](https://github.com/ant-design/ant-design-icons)

This plugin is built by WPIntegrations and is not affiliated with, endorsed by, or sponsored by WooCommerce, Automattic, or RD Station. "WooCommerce" and "RD Station" are the trademarks of their respective owners, used here only to describe what the plugin connects.

= Requirements =

* WooCommerce
* An app registered in the RD Station App Store, with the RD Station Marketing permissions

== External services ==

This plugin connects to the **RD Station APIs** (operated by RD Station, https://www.rdstation.com/). Nothing is sent until you create an integration, and then only when an order reaches the status that integration listens to.

**RD Station Marketing** — delivers your orders as conversion events.

* **What is sent:** the order fields you explicitly map (such as the customer's name, email, phone, address, order total or products), the conversion identifier, and — if present — the customer's RD Station tracking values for lead attribution, saved at checkout: the visitor ID from the `rdtrk` cookie and the traffic source from the `__trf.src` cookie. If you map them, it also sends the optional values the mapping screen offers: the checkout page URL, the order time, the customer account's name and email, the site title and URL, and any fixed text you enter.
* **What is read:** your account's contact fields, so the mapping screen can offer your real custom fields instead of asking you to type identifiers.
* **Connection test:** the Test connection button sends one conversion with the identifier `wpi-connection-test` and no contact data.
* **Where:** `https://api.rd.services/platform/events` and `https://api.rd.services/platform/contacts/fields`, authenticated with the OAuth token from your own RD Station app.

**RD Station authentication** — used only while connecting an account and when an access token is renewed. Your app's Client ID and Client Secret, the authorization code and the refresh token are sent to `https://api.rd.services/auth/dialog` and `https://api.rd.services/auth/token`.

No data is sent to WPIntegrations or any other third party. RD Station terms of use: https://legal.rdstation.com/en/rdstation-mp-services-agreement/ — privacy notice: https://legal.rdstation.com/en/privacy-policy/

== Installation ==

1. Install and activate the plugin.
2. Create an app in the RD Station App Store with the RD Station Marketing permissions, register the callback URL shown on the plugin's Settings tab, then paste the app's Client ID and Client Secret there and connect.
3. Open the **Integrations** tab and create an integration: pick the order status to listen to (Processing by default), check the field mapping — billing email and name come pre-mapped — and save.

== Frequently Asked Questions ==

= When is an order sent? =

When it reaches the status the integration listens to. Processing means the payment was received, so it is the default. Each order is sent at most once per status: an order that goes from Processing to On hold and back is not sent twice. Orders that reached the status before the integration existed are not sent.

= Which order fields can I map? =

Billing and shipping name, email, phone, company and address; order number, date, status, total, subtotal, discount, shipping, tax and currency; the products (with quantities), SKUs, item count, coupons, payment and shipping method and the customer note; and WooCommerce's own order attribution (source type, UTM source, medium and campaign). Custom checkout fields are listed too, read from the meta of your 20 most recent orders — place a test order after adding a new checkout field, then press refresh.

= Can I send the CPF or CNPJ? =

Yes, if your checkout collects it. Plugins such as Brazilian Market on WooCommerce store them as order fields, which appear in the mapping screen as custom fields. Map them to a custom field in your RD Station account.

= Is the attribution right when I complete orders by hand? =

Yes. The tracking cookies are read at checkout, while the customer is on your site, and kept with the order. When the order reaches the status later — in the admin, through a payment gateway's notification or a scheduled task — those saved values are sent.

= Does it work with HPOS and the block checkout? =

Yes. Orders are read only through WooCommerce's order API, and the plugin declares compatibility with High-Performance Order Storage and the Cart and Checkout blocks.

= Which RD Station plans are supported? =

Any RD Station Marketing plan with API access. You only need to register your own app in the App Store and give it the Marketing permissions.

= Can it send orders to RD Station CRM instead? =

No. This plugin delivers conversion events to RD Station Marketing. Sending orders to RD Station CRM as contacts and deals is part of the separate Pro plugin.

= Which fields do I have to map? =

The same one RD Station itself marks as required on its own forms: an **email**. New integrations start with the billing email and name already mapped. Any custom field your account marks mandatory is added to that list automatically. Everything else is optional.

= What happens if RD Station is temporarily unavailable? =

The order is not delivered, and the failure is recorded in the Logs tab with the full response.

= Where is the source code of the admin screens? =

In `resources/js/` inside the plugin, and publicly at [github.com/wpintegrations/wpintegrations-connector-for-wc-rd-station](https://github.com/wpintegrations/wpintegrations-connector-for-wc-rd-station). The compiled files in `build/` are generated from it with Vite: run `pnpm install` and then `pnpm run build` in the plugin folder. See "Source code and third-party libraries" above for the bundled libraries.

= Where do I see errors? =

The **Logs** tab shows every delivery with the full request and response.

== Changelog ==

= 1.0.0 =
* Initial release: WooCommerce orders → RD Station Marketing conversion events when they reach a chosen status, field mapping with order fields, custom checkout fields and your account's custom fields, LGPD consent mapping, RD Station attribution cookies saved at checkout, HPOS and block checkout compatibility, a delivery log, and encrypted credential storage.
