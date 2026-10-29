=== Crosspost ===
Tags: woocommerce, products, sync, crosspost, rest api
Requires at least: 6.5
Requires PHP: 7.4
Requires Plugins: woocommerce
Stable tag: 1.0.0

Crossposts WooCommerce products to a remote WooCommerce site via the REST API, with configurable meta, ID translation and data filters.

== Description ==

Crosspost sends WooCommerce products from this site to another WooCommerce site whenever they are saved. It uses the remote site's WooCommerce REST API, so nothing needs to be installed on the remote site beyond WooCommerce itself.

**What gets sent**

* Core product data: name, type, status, SKU, descriptions, purchase note, visibility and menu order
* Pricing, including sale prices and sale dates
* Stock, shipping, dimensions and tax settings
* Downloads and external/affiliate product links
* Attributes and default attributes
* Categories and tags (sent by name)
* Product and gallery images (sent by URL; the remote site downloads them)
* Variations of variable products, each with its own price, stock, image and attributes
* Selected postmeta, or all postmeta minus internal WordPress/WooCommerce keys

**Matching products on the remote site**

The first time a product is sent, Crosspost looks it up on the remote site by SKU. If a match is found it is updated; otherwise a new product is created. The remote ID is then stored on the local product so later saves update the same remote product directly. Variations are matched the same way.

**Product ID translation**

Product IDs differ between the two sites, so any field that points at another product has to be translated before it is sent. Crosspost does this automatically for:

* Upsells and cross-sells (always)
* Any meta keys listed in the "ID Fields to Translate" setting, for example accessory or related product fields added by other plugins

Each local ID is replaced with the remote ID of the same product, found from the stored remote ID or, failing that, by SKU lookup on the remote site. IDs that cannot be matched are removed and a warning is logged. Single IDs, arrays of IDs and comma-separated lists are all supported, and keep their original format.

**Update-only mode**

When enabled, Crosspost only updates products that already exist on the remote site. Products with no SKU match are skipped rather than created, and the name, prices, sale dates and categories are left unchanged on the remote site.

== Installation ==

1. Upload the `crosspost` folder to `/wp-content/plugins/`, or install the zip from Plugins → Add New → Upload Plugin.
2. Activate the plugin. WooCommerce must be active.
3. On the **remote** site, go to WooCommerce → Settings → Advanced → REST API and create a key with **Read/Write** permission.
4. On **this** site, go to Settings → Crosspost and enter the remote site URL, consumer key and consumer secret.
5. Either tick "Crosspost All Products", or tick "Crosspost this product" on individual products (Product data → General).

The remote site should use HTTPS, as the API keys are sent with every request.

== Settings ==

Found under Settings → Crosspost.

* **Remote Site URL** – the base URL of the remote site, e.g. `https://example.com`.
* **Consumer Key / Consumer Secret** – the remote site's WooCommerce REST API credentials.
* **Crosspost All Products** – send every product on save, instead of only products with "Crosspost this product" ticked.
* **Update Only Mode** – only update existing remote products; see the description above.
* **Include All Postmeta** – send all postmeta except internal keys (edit locks, sales counts, ratings and Crosspost's own tracking keys).
* **Postmeta Keys to Include** – space-separated meta keys to send. When "Include All Postmeta" is off, only these keys are sent.
* **ID Fields to Translate** – space-separated meta keys whose values contain product IDs. A key must also be sent (via the list above or "Include All Postmeta") for its value to be translated.

== Usage ==

* Products are sent automatically when created or updated, if crossposting is enabled for them.
* To send a product on demand, hover over it in Products → All Products and click **Crosspost now**.
* The result of the last crosspost, including the remote ID or any error, is shown in the product's General tab.
* Detailed logs are written to WooCommerce → Status → Logs, under the source `crosspost`.

== Developer Filters ==

All filters can be added from a theme's `functions.php` or a small custom plugin.

= Content and pricing =

`crosspost_short_description` and `crosspost_long_description` – change descriptions before sending (for example, to translate the text).

`
add_filter( 'crosspost_long_description', function( $desc, $product ) {
    return my_translate( $desc );
}, 10, 2 );
`

`crosspost_regular_price` and `crosspost_sale_price` – change prices before sending. These also run for variations.

`
add_filter( 'crosspost_regular_price', function( $price, $product ) {
    return (string) ( (float) $price * 1.1 ); // +10%
}, 10, 2 );
`

= Full payloads =

`crosspost_product_data` – modify the complete product payload. Upsell and cross-sell IDs are still local at this point; they are translated afterwards.

`crosspost_variation_data` – modify a single variation's payload. Receives `$data, $variation, $parent`.

= Postmeta =

`crosspost_meta_keys` – add or remove keys from the list of meta keys to send.

`crosspost_meta_exclude_keys` – add keys to exclude when "Include All Postmeta" is enabled.

`crosspost_meta_value` – transform a meta value before it is sent. Receives `$value, $key, $product`.

= ID translation =

`crosspost_translate_keys` – add meta keys whose values hold product IDs.

`
add_filter( 'crosspost_translate_keys', function( $keys ) {
    $keys[] = '_my_accessory_ids';
    return $keys;
} );
`

`crosspost_translate_id` – override how a single local product ID maps to a remote ID. Return `false` to drop the ID. Receives `$remote_id, $local_id, $field`.

= Control =

`crosspost_skip` – return `true` to skip crossposting for a product, for example during bulk imports. Receives `$skip, $product_id`.

== Frequently Asked Questions ==

= Do I need to install anything on the remote site? =

No. The remote site only needs WooCommerce with a REST API key that has Read/Write access.

= Why were some upsells or accessories missing on the remote site? =

A related product can only be translated if it already exists on the remote site, either because it has been crossposted or because a product with the same SKU exists there. Unmatched IDs are removed and logged. Crosspost the related products first, then save the parent product again.

= Why isn't a related variation being translated? =

Remote variations cannot be found by SKU lookup alone. Crosspost the variation's parent product first; its variations will then have stored remote IDs and can be translated.

= Why are my images not appearing on the remote site? =

Images are sent as URLs and downloaded by the remote site, so the image URLs on this site must be publicly reachable.

= Where can I see what was sent? =

WooCommerce → Status → Logs, source `crosspost`. Each send logs the full payload and the remote site's response.

== Changelog ==

= 1.0.0 =
* Initial release.
* Crosspost simple and variable products, images, categories, tags and selected postmeta.
* SKU matching and update-only mode.
* Translation of upsell, cross-sell and configured meta field product IDs to remote IDs.
