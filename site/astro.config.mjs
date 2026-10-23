// @ts-check
import { defineConfig } from 'astro/config';

// Astro ne sert qu'à générer les pages statiques (landing, panier, commande, gabarits).
// Tout le côté serveur (commandes, paiement, admin) est en PHP : voir php/.
const phpBackend = process.env.PHP_BACKEND || 'http://localhost:8080';

export default defineConfig({
  site: 'https://leshormonesdyvonne.vanessagandino.fr',
  output: 'static',
  build: { format: 'file' }, // panier.html, commande.html… (réécrits sans extension par .htaccess)
  vite: {
    server: {
      // En dev (`npm run dev`), les URL dynamiques sont relayées au conteneur PHP (`npm run php`).
      proxy: Object.fromEntries(['/api', '/merci', '/admin', '/photo'].map((p) => [p, phpBackend])),
    },
  },
});
