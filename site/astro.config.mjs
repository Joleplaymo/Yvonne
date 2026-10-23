// @ts-check
import { defineConfig, envField } from 'astro/config';
import node from '@astrojs/node';

/** @param {string} def */
const str = (def) => envField.string({ context: 'server', access: 'secret', optional: true, default: def });
/** @param {number} def */
const num = (def) => envField.number({ context: 'server', access: 'secret', optional: true, default: def });

// Domaines servis derrière le proxy (Caddy) : Astro ne fait confiance à X-Forwarded-Proto/Host que pour eux.
// Fixés au build (variable SITE_DOMAINS, séparés par des virgules). Par défaut : le domaine de prod + localhost.
const domains = (process.env.SITE_DOMAINS || 'leshormonesdyvonne.vanessagandino.fr,localhost')
  .split(',')
  .map((d) => d.trim())
  .filter(Boolean);

export default defineConfig({
  site: `https://${domains[0]}`,
  output: 'server',
  adapter: node({ mode: 'standalone', bodySizeLimit: 64 * 1024 }),
  session: false,
  security: {
    checkOrigin: true,
    allowedDomains: domains.map((hostname) => ({ hostname })),
  },
  env: {
    schema: {
      // Boutique — lus au démarrage du serveur : changer .env + redémarrer suffit.
      BOOK_PRICE_EUR: num(10),
      MAX_QTY: num(6),
      // Frais de port par quantité : "1:4.90,2:5.90,4:7.50" = 4,90 € pour 1, 5,90 € pour 2-3, 7,50 € dès 4.
      SHIPPING_TABLE: str('1:4.90,2:5.90,4:7.50'),
      REVOLUT_LINK_TEMPLATE: str('https://revolut.me/v_gandino'),
      MR_BRAND: str(''),
      // Données et mails
      DATA_DIR: str('./data'),
      SMTP_URL: str(''),
      MAIL_FROM: str('Vanessa Gandino <contact@vanessagandino.fr>'),
      ADMIN_EMAIL: str('contact@vanessagandino.fr'),
      PUBLIC_BASE_URL: str('http://localhost:4321'),
      // Admin
      ADMIN_USER: str('vanessa'),
      ADMIN_PASSWORD: str(''),
    },
  },
});
