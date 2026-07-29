import { defineConfig } from "astro/config";
import react from "@astrojs/react";
import sitemap from "@astrojs/sitemap";
import tailwindcss from "@tailwindcss/vite";

export default defineConfig({
  site: "https://cart.consentresolve.com",
  output: "static",
  trailingSlash: "always",
  integrations: [
    react(),
    sitemap({
      filter: (page) => !page.includes("/style-guide/"),
    }),
  ],
  vite: { plugins: [tailwindcss()] },
  build: { format: "directory" },
});
