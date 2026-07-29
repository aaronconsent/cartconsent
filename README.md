# CartConsent

Free abandoned-cart recovery, cookie consent, and auto-updating legal pages for
WooCommerce — with optional, usage-priced **visitor resolution**. CartConsent is
a brand of [Consent Resolve](https://consentresolve.com); WooCommerce is the
first integration.

This monorepo holds both halves of the product:

```
cartconsent/
├── site/     Marketing site — Astro 5 + Tailwind v4, static, deploys to Cloudflare
└── plugin/   The WordPress/WooCommerce plugin (brand wrapper of consent-resolve-woo)
```

## Site (`site/`)

Astro static site, WooCommerce-purple light theme, reusing the Consent Resolve
design system.

```bash
cd site
npm install
npm run dev      # local dev
npm run build    # → site/dist
npx wrangler deploy   # publish to Cloudflare (cartconsent-website)
```

Temp domain: `cart.consentresolve.com`. Final domain: `cartconsent.com`.

## Plugin (`plugin/`)

The WooCommerce plugin, presented under the CartConsent brand. Pricing model:
the plugin (cart recovery + cookie banner + legal docs) is **free**; the only
paid feature is **visitor resolution** (monthly, per-resolution tiers —
500/$400 · 1,000/$700 · 2,500/$1,500).

The live demo is a dedicated WordPress store on the DigitalOcean droplet at
`cart.consentresolve.com`.
