# ziplogger.dev demo shop

A pretend WooCommerce shop ("Northwind Coffee") on ziplogger.dev that shows visitors what the ZipLogger plugin
records. Nothing is for sale. This folder holds the sources of the parts that are not plain WordPress settings.

## What is on the site

| Piece | Where |
| --- | --- |
| Tour page | `/demo/` (page 50), in the header menu as "Demo shop" |
| Shop | `/shop/`, six virtual products, WooCommerce 11.x |
| Payment | Cash on delivery, renamed "Demo payment (no money is taken)"; no other gateway |
| Consent banner | Custom HTML block in the footer template part; "Privacy choices" link in the footer |
| Live panels | Snippet "ZipLogger demo shop: live panels" (`ziplogger-demo-live.php`), shown on the tour page |
| Checkout trimming and order cleanup | Snippet "ZipLogger demo shop helpers" (`ziplogger-demo-shop.php`) |
| Styles | Additional CSS in the global styles (sections "Demo shop", "Consent banner", "Live panels") |

Snippets run through the Code Snippets plugin.

## Settings that keep it a safe demo

- Shipping, coupons, taxes, reviews, account creation and every WooCommerce email are off.
- Order attribution (sourcebuster cookies) is off.
- Checkout asks only for email, name and country (helpers snippet).
- Demo orders are set to "completed"; Accounts & Privacy anonymises completed orders after 1 day and trashes
  pending, failed and cancelled ones after 1 day.
- The WooCommerce store notice is restyled and hidden on Home, Plugin, Docs and Pricing.

## ZipLogger plugin settings

Workspace `ziplogger-wp`, Production environment, site label `ziplogger-dev`.

| Module | Setting |
| --- | --- |
| Server logs | on, plus outbound HTTP failures |
| Browser monitoring | on; Web Vitals and page timing at 100% |
| Analytics | on, consent required |
| Session replay | on, consent required, 100% of consenting visitors, all text masked, 10 min / 10 MB per session |
| Tracing | on, 50%, browser-to-server tracing on |
| WooCommerce | on, all events |

The consent banner grants `analytics` and `replay` through `ZipLoggerWP.consent.grant()`; the plugin keeps the
choice in its `ziplogger_consent` cookie. Browser monitoring needs no consent (no identifier).

## Live panels

`GET /wp-json/ziplogger-demo/v1/live` is public and read-only. It calls the plugin's own dashboard panels
(`Panels::render`), so the read key stays on the server; links into the ZipLogger app and the administrator's own
requests are removed, and the answer is cached for five minutes. SiteGround caches REST answers per URL, so the
tour page adds a five-minute window number to the URL.

The read interface has no query for product analytics or replays, so the tour shows those as "in the workspace".

## Rebuilding the product images

```
docker run --rm -v "<repo>/wporg-assets:/wporg" -v ziplogger-e2e_pwmodules:/e2e/browser/node_modules \
  -w /e2e/browser mcr.microsoft.com/playwright:v1.63.0-noble node /wporg/demo-shop/render-products.mjs
```

Output goes to `out/` (not committed); the images on the site are in the media library.
