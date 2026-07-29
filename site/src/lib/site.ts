export const SITE = {
  name: "CartConsent",
  url: "https://cart.consentresolve.com",
  description:
    "Free WooCommerce abandoned-cart recovery, cookie consent, and auto-updating legal pages — one plugin, no monthly fee. Pay only for visitor resolution when you want it.",
  defaultOgImage: "/og-default.png",
  twitter: "@cartconsent",
  // NAP — single source of truth (same company as Consent Resolve).
  email: "hello@cartconsent.com",
  phone: "(727) 202-5996",
  phoneE164: "+17272025996",
  address: {
    street: "1907 Gulf Way #1",
    city: "St Pete Beach",
    region: "FL",
    postalCode: "33706",
    country: "US",
  },
  hours: "Mon–Fri 9a–6p Eastern",
  // Where the live demo store lives (WordPress on the droplet).
  demoUrl: "https://cart.consentresolve.com/demo-store/",
  github: "https://github.com/aaronconsent/cartconsent",
} as const;

export type NavItem = {
  label: string;
  href: string;
  /** Optional second line in dropdown panels. */
  desc?: string;
  /** When present, this item renders as a dropdown; `href` is the hub link. */
  children?: NavItem[];
};

export const PRIMARY_NAV: NavItem[] = [
  {
    label: "Features",
    href: "/features/",
    children: [
      { label: "Abandoned cart recovery", href: "/features/cart-recovery/", desc: "Win back checkouts, on a lawful basis" },
      { label: "Cookie consent banner", href: "/features/cookie-consent/", desc: "GDPR/CCPA banner + script blocking" },
      { label: "Legal documents", href: "/features/legal-documents/", desc: "Auto-updating privacy, cookie & terms pages" },
      { label: "Visitor resolution", href: "/features/visitor-resolution/", desc: "Name the shoppers who don't buy" },
      { label: "All features →", href: "/features/" },
    ],
  },
  { label: "Demo", href: "/demo/" },
  { label: "Resources", href: "/resources/" },
  { label: "Pricing", href: "/pricing/" },
];

/** The four feature pages, in order — reused by the Features hub, nav, and footer. */
export const FEATURES = [
  {
    slug: "cart-recovery",
    name: "Abandoned cart recovery",
    tagline: "Win back the checkouts you already earned.",
    icon: "cart",
    free: true,
  },
  {
    slug: "cookie-consent",
    name: "Cookie consent banner",
    tagline: "A fast, accessible GDPR/CCPA banner that actually blocks trackers.",
    icon: "shield",
    free: true,
  },
  {
    slug: "legal-documents",
    name: "Legal documents",
    tagline: "Privacy, cookie, and terms pages that keep themselves current.",
    icon: "doc",
    free: true,
  },
  {
    slug: "visitor-resolution",
    name: "Visitor resolution",
    tagline: "Put a name and email to the shoppers who leave without buying.",
    icon: "user",
    free: false,
  },
] as const;

/** Visitor-resolution plans — monthly subscription, tiered per-resolution price. */
export const PLANS = [
  { resolutions: 500, perUnit: 0.8, monthly: 400 },
  { resolutions: 1000, perUnit: 0.7, monthly: 700, popular: true },
  { resolutions: 2500, perUnit: 0.6, monthly: 1500 },
] as const;

export const FOOTER_NAV = {
  product: [
    { label: "Abandoned cart recovery", href: "/features/cart-recovery/" },
    { label: "Cookie consent banner", href: "/features/cookie-consent/" },
    { label: "Legal documents", href: "/features/legal-documents/" },
    { label: "Visitor resolution", href: "/features/visitor-resolution/" },
    { label: "Live demo", href: "/demo/" },
    { label: "Pricing", href: "/pricing/" },
  ],
  developers: [
    { label: "Documentation", href: "/resources/docs/" },
    { label: "Quick start", href: "/resources/docs/quick-start/" },
    { label: "Hooks & filters", href: "/resources/docs/hooks/" },
    { label: "FAQ", href: "/resources/faq/" },
    { label: "GitHub", href: "https://github.com/aaronconsent/cartconsent" },
    { label: "Changelog", href: "/resources/changelog/" },
  ],
  company: [
    { label: "How it works", href: "/#how-it-works" },
    { label: "Resources", href: "/resources/" },
    { label: "Consent Resolve", href: "https://consentresolve.com" },
    { label: "Contact", href: "/resources/support/" },
  ],
  legal: [
    { label: "Privacy Policy", href: "/privacy-policy/" },
    { label: "Terms of Service", href: "/terms/" },
    { label: "Disclaimer", href: "/disclaimer/" },
    { label: "Cookie Policy", href: "/cookie-policy/" },
  ],
} as const;
