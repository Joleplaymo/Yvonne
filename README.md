# Handoff : landing page « Les hormones d'Yvonne » (maquette 3a)

## Overview
Landing page publique de **vanessagandino.com**, site de l'autrice Vanessa Gandino.
La page présente l'autrice et son premier livre, *les hormones d'Yvonne*, et sert de **portail vers l'espace auteur** : trois sous-domaines (blog, actualité, boutique). La vente ne se fait pas sur cette page — elle redirige vers la boutique et vers les points de vente physiques.

Langue : français. Ton : chaleureux, direct, un peu irrévérencieux — la voix du livre.

## About the design files
Les fichiers de ce dossier (`index.html`, `styles.css`) sont des **références de design réalisées en HTML** : ils montrent l'intention visuelle et le comportement attendu, ce n'est pas du code de production à copier tel quel.
La tâche est de **recréer cette maquette dans l'environnement du projet cible** (Next.js / Astro / React, ou autre) en suivant ses conventions établies. Si aucun environnement n'existe encore, choisir le framework le plus adapté — une page essentiellement statique avec quelques liens externes : **Astro ou Next.js en statique** conviennent parfaitement.

Le HTML fourni utilise des styles inline pour la mise en page et une petite feuille `styles.css` pour les primitives réutilisables (`.cta`, `.door`, `.navlite`, `.ic`, `.bigword`). En production, tout doit passer dans des composants et/ou des classes utilitaires — ne pas conserver les styles inline.

## Fidelity
**Haute fidélité (hifi).** Couleurs, typographie, espacements et hiérarchie sont définitifs et doivent être reproduits fidèlement. Le responsive n'est esquissé que par un breakpoint de secours à 860px : c'est au développeur de produire un vrai comportement mobile (voir « Responsive » plus bas).

## Screens / Views

Une seule page, huit blocs empilés, largeur de contenu max **1180px**, centrée. Padding horizontal de section : **60px** (desktop).

### 1. Barre de navigation — `.navlite`
- Fond `--navy` #0E3049, texte `--cream` #F9F0DA. Padding `22px 60px`, flex, `gap: 30px`, `align-items: center`.
- Gauche : marque « vanessa gandino » — 18px / 900 / `letter-spacing:-0.02em`, minuscules volontaires.
- Droite (poussée par `margin-left:auto` sur le premier lien) : « l'univers » (`#univers-3a`), « l'autrice » (`#autrice-3a`), puis « je commande » en `--peach` #F1975C / 800 → doit pointer vers la boutique.
- Liens : 15.5px / 600, `opacity:.85` → `1` au survol.
- La nav n'est pas sticky dans la maquette. La rendre sticky est acceptable si le projet le souhaite.

### 2. Hero
- Fond `--navy`, padding `76px 60px 68px`. Grille 2 colonnes `1.25fr .75fr`, `gap:56px`, centré verticalement.
- Titre `.bigword` : **82px / 900 / line-height .92 / letter-spacing -0.035em**, en trois lignes forcées : « les » / « hormones » / « d'Yvonne ».
- Sous-titre : 20px / line-height 1.45 / `max-width:34ch`, avec « LE » en 900 : *« **LE** livre qui met enfin les vrais mots (et de l'humour) sur ce que les hormones nous font vivre »*.
- Boutons : `.cta` « Je le veux !!!! » (→ boutique) et `.cta.outline` « Lire un extrait » (couleur crème sur navy).
- Droite : couverture du livre, ratio **400/600**, `box-shadow: 0 24px 60px rgba(0,0,0,.35)`, pas d'arrondi.

### 3. Le livre — `#livre-3a`
- Fond crème (celui du body), padding `72px 60px`, texte navy.
- Titre 52px / 900, trois lignes : « Une vie entière / à négocier avec / nos hormones ! ».
- Trois colonnes égales, `gap:32px`. Chacune : un intitulé 19px / 900 en `--terra` #E4552C, puis un paragraphe 16.5px / line-height 1.6.
  - « À en rire parfois » — *De la puberté à la ménopause, sans jamais de pause. Un corps qui change sans demander votre avis.*
  - « À en pleurer souvent » — *Les silences, les injonctions, les combats invisibles — et ce fameux « c'est hormonal ».*
  - « À en parler enfin » — *Raconter tout haut ce que des millions de femmes vivent tout bas. Un hommage à la force féminine.*

### 4. Citation #balancetesHormones
- Fond `--navy`, padding `68px 60px`.
- Titre `.bigword` 44px en `--peach`, deux lignes : « #balancetes / hormones ».
- Citation alignée à droite (`margin-left:auto`, `max-width:26ch`), 30px / 800 / line-height 1.28 : *« si les hormones étaient un patron, il y aurait déjà eu une plainte collective mondiale pour harcèlement physique et moral. »*
- Bouton `.cta` « Commander le livre », aligné à droite.

### 5. Commander
- Fond crème, padding `68px 60px`. Grille `.55fr 1fr`, `gap:56px`.
- Gauche : couverture, ratio 400/600, `box-shadow: 0 18px 44px rgba(14,48,73,.22)`.
- Droite :
  - Titre 38px / 900, deux lignes : « Il est pour vous. / Il est à vous. »
  - Ligne « expédié avec soin » précédée d'un **picto carton** : carré 42×42, `border-radius:12px`, fond `--olive`, icône Lucide `package` en crème, 23px.
  - Boutons : `.cta` « Je commande le livre » (→ boutique) et `.cta.outline` « Où le trouver près de chez moi » → **ancre interne `#distribution-3a`** (bloc 7).
  - Sous les boutons, en `--olive` / 14.5px / 700 : icône Lucide `truck` + « Expédition rapide ».

### 6. L'autrice — `#autrice-3a`
- Fond `--navy`, padding `68px 60px`. Grille `.5fr 1fr`, `gap:52px`.
- Gauche : portrait, ratio **3/4**, fond crème derrière l'image.
- Droite :
  - `.bigword` 52px : « Vanessa / Gandino ».
  - 18px / line-height 1.5 : *« J'ai la cinquantaine et un certain vécu hormonal ! / J'écris comme je ressens. Naturellement. Sincèrement. »*
  - Citation en italique, 17px, `max-width:46ch`, crème à 86% d'opacité : *« Dans les hormones d'Yvonne, j'ai essayé de raconter tout haut ce que des millions de femmes vivent tout bas. J'ai pensé ce livre comme un hommage à la force féminine. »*
  - Signature 17px en `--peach` : « Hormonalement votre ».

### 7. L'espace auteur — `#univers-3a`
Le cœur fonctionnel de la page : trois portes vers les sous-domaines.
- Fond crème, padding `68px 60px`. Grille 3 colonnes égales, `gap:18px`.
- Chaque porte : `.door` — colonne flex, `gap:10px`, padding 26px, `border-radius:16px`, translation `-4px` + ombre `0 14px 34px rgba(14,48,73,.18)` au survol (transition .18s).
- Contenu d'une porte : pastille `.ic` (52px, ronde) avec icône Lucide → nom en 22px / 900 → description 14.5px / line-height 1.5 → URL en 14px / 800.

| Porte | Lien | Fond | Icône | Pastille | Description |
|---|---|---|---|---|---|
| le blog | `https://blog.vanessagandino.com` | `--navy` | `pen-line` | `rgba(249,240,218,.14)`, icône `--peach` | Chroniques hormonales et coulisses d'écriture. |
| l'actualité | `https://actualite.vanessagandino.com` | `--terra` | `megaphone` | `rgba(249,240,218,.18)`, icône crème | Rencontres, lectures et parutions à venir. |
| la boutique | `https://boutique.vanessagandino.com` | `--olive` | `shopping-bag` | `rgba(249,240,218,.16)`, icône crème | Le livre, et les prochains titres à venir. |

Les trois liens sont **externes** (sous-domaines distincts) — `target="_blank"` au choix du projet, la maquette ne le pose pas.

### 8. Où le trouver — `#distribution-3a`
- Fond `--navy`, padding `68px 60px`.
- En-tête : flex, `align-items:flex-end`, `gap:40px`, wrap.
  - Titre 40px / 900 « Où le trouver » + paragraphe 17px / `max-width:46ch` en #EADFC2 : *Librairies indépendantes, concept stores et boutiques partenaires — la liste complète, ville par ville.*
  - À droite (`margin-left:auto`) : `.cta` « Voir tous les points de vente » → `https://vanessagandino.com/points-de-vente` (page à créer).
- Trois cartes, `gap:18px`, fond `rgba(249,240,218,.1)`, `border-radius:16px`, padding 28px. Pastille `.ic` + titre 22px / 900 + texte 15px en #D8CDB2.
  - `book-open` — **Librairies indépendantes** — *Chez les libraires qui défendent le livre, partout en France.*
  - `store` — **Concept stores** — *Lieux de vie, boutiques bien-être et adresses partenaires.*
  - `globe` — **En ligne** — *La boutique de l'autrice et les grandes plateformes.*
- Ligne de bas de bloc, 15px en #B9AE96 : *Vous êtes libraire ou commerçant et souhaitez référencer le livre ? Écrivez à contact@vanessagandino.com*

### 9. Pied de page
- Fond `--olive`, texte crème, padding `44px 60px`, flex, `gap:36px`, wrap.
- « vanessagandino.com » en 18px / 900 · « Presse, libraires et partenariats — contact@vanessagandino.com » en 15px / opacité .9 · à droite (`margin-left:auto`) deux icônes Lucide (`at-sign`, `mail`) à brancher sur les réseaux réels.

## Interactions & behavior
Page essentiellement statique. Aucun état applicatif, aucun appel réseau.

- **Ancres internes** : « l'univers » → `#univers-3a`, « l'autrice » → `#autrice-3a`, « Où le trouver près de chez moi » → `#distribution-3a`. Prévoir `scroll-behavior: smooth` et un `scroll-margin-top` si la nav devient sticky.
- **Liens sortants** : les trois sous-domaines, la boutique (boutons « Je le veux !!!! », « Commander le livre », « Je commande »), et `/points-de-vente`.
- **Survols** : `.cta` → fond assombri (`--olive-dark` #41531B ; `--terra-dark` #C8451F pour la variante terracotta) + `translateY(-2px)`, transition `.18s ease`. `.door` → `translateY(-4px)` + ombre. Liens de nav → opacité `.85` → `1`.
- **Focus clavier** : `outline: 2px solid var(--terra); outline-offset: 2px` — ne jamais laisser l'anneau bleu par défaut.
- **Pas d'animation d'entrée** dans la maquette. Si le projet en ajoute, respecter `prefers-reduced-motion`.

## Responsive
La maquette est dessinée à **1180px**. Le fichier ne contient qu'un repli grossier sous 860px (grilles en une colonne, padding 24px, titre hero à 52px). À produire pour de vrai :
- **≥1024px** : la maquette telle quelle.
- **640–1024px** : grilles à 2 colonnes (les blocs 3, 7 et 8 passent à 2 puis 1), padding horizontal 40px, hero en une colonne avec la couverture au-dessus ou à droite en plus petit.
- **<640px** : tout en une colonne, padding 24px, hero à ~44px, nav repliée (menu ou simple pile). Cibles tactiles ≥44px — les `.cta` (padding 16/30) le sont déjà.

## Design tokens

### Couleurs
| Token | Hex | Usage |
|---|---|---|
| `--navy` | `#0E3049` | Fonds sombres, texte sur crème |
| `--cream` | `#F9F0DA` | Fond de page, texte sur navy |
| `--cream-2` | `#EADFC2` | Texte secondaire sur navy |
| `--olive` | `#566B25` | Boutons primaires, pied de page, picto carton |
| `--olive-dark` | `#41531B` | Survol des boutons primaires |
| `--terra` | `#E4552C` | Accents, porte « actualité », focus |
| `--terra-dark` | `#C8451F` | Survol terracotta |
| `--peach` | `#F1975C` | Accents sur navy (titres, signature, lien « je commande ») |
| `--sand` | `#EFE0BE` | Fond des emplacements image |
| `--muted-on-navy` | `#D8CDB2` | Texte de carte sur navy |
| `--faint-on-navy` | `#B9AE96` | Mention discrète en bas du bloc distribution |

Voiles utilisés sur navy : `rgba(249,240,218,.1)` (fond de carte), `.14` / `.16` / `.18` (pastilles d'icône).

### Typographie
**Figtree** en famille unique (Google Fonts, poids 400 / 600 / 800 / 900).
| Rôle | Taille | Poids | Détail |
|---|---|---|---|
| Hero | 82px | 900 | `line-height:.92`, `letter-spacing:-0.035em` |
| Titre de section | 52px / 44px / 40px / 38px | 900 | `letter-spacing:-0.025em` |
| Nom de porte / carte | 22px | 900 | `letter-spacing:-0.02em` |
| Intitulé de colonne | 19px | 900 | en `--terra` |
| Chapô | 20px | 400 | `line-height:1.45` |
| Corps | 16.5–17px | 400 | `line-height:1.6` |
| Texte de carte | 14.5–15px | 400 | `line-height:1.5–1.55` |
| Bouton | 17px | 800 | — |
| Nav | 15.5px | 600 | — |

### Espacement, rayons, ombres
- Padding de section : `68–76px` vertical, `60px` horizontal. Gaps de grille : 18 / 32 / 52 / 56px.
- Rayons : `12px` (boutons, picto carton), `16px` (cartes et portes), `999px` (pastilles d'icône). Les visuels (couverture, portrait) sont **à angles vifs**.
- Ombres : `0 24px 60px rgba(0,0,0,.35)` (couverture sur navy) · `0 18px 44px rgba(14,48,73,.22)` (couverture sur crème) · `0 14px 34px rgba(14,48,73,.18)` (survol de porte).

## Assets
- **Couverture du livre** (utilisée deux fois, ratio 400/600) et **portrait de l'autrice** (ratio 3/4) : non fournis. Dans le HTML ce sont des emplacements `<image-slot>` avec un fond sable — les remplacer par de vraies balises `<img>` (ou le composant image du framework) une fois les fichiers reçus.
- **Icônes** : [Lucide](https://lucide.dev), stroke-width 2.75. Utilisées : `package`, `truck`, `pen-line`, `megaphone`, `shopping-bag`, `book-open`, `store`, `globe`, `at-sign`, `mail`. Le HTML charge Lucide par CDN ; en production, importer le paquet et faire du tree-shaking.
- **Police** : Figtree via Google Fonts. À self-hoster si le projet le préfère.

## À confirmer avec la cliente avant implémentation
- URL réelle de la boutique et cible exacte des boutons d'achat.
- Existence et contenu de la page `/points-de-vente`.
- Comptes réseaux sociaux pour les deux icônes du pied de page.
- Fichiers image définitifs (couverture, portrait).

## Files
- `index.html` — la maquette 3a extraite en page autonome.
- `styles.css` — les primitives réutilisables et les tokens.

Source d'origine dans le projet de design : `Landing Yvonne.dc.html`, option **3a** (le fichier contient aussi les options 1a/1b/1c, 2a et 3b, non retenues).
