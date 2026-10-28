<?php
declare(strict_types=1);
// Tests de l'en-tête et du pied de page modifiables. Lancement : npm run test:php.

$data = sys_get_temp_dir() . '/yv-entete-' . getmypid();
putenv("DATA_DIR=$data");
putenv('LOG_FILE=stderr');
require __DIR__ . '/../../php/_prive/lib.php';
require __DIR__ . '/../../php/_prive/entete.php';

$fails = 0;
$count = 0;
function check(string $name, bool $cond): void
{
    global $fails, $count;
    $count++;
    if (!$cond) {
        $fails++;
        echo "ÉCHEC : $name\n";
    }
}

$page = '<html><head></head><body><!--chrome:header--><nav class="navlite">ORIGINE</nav><!--/chrome:header-->'
    . '<main>M</main><!--chrome:footer--><footer class="footer on-olive">PIED</footer><!--/chrome:footer--></body></html>';

$db = db();
check('rien d’enregistré au départ', chrome_load($db, 'header') === null && chrome_load($db, 'footer') === null);
check('sans configuration : gabarit d’origine conservé', str_contains(chrome_apply($page, $db), 'ORIGINE') && str_contains(chrome_apply($page, $db), 'PIED'));
$final = site_finish($page);
check('repères retirés', !str_contains($final, '<!--chrome:') && !str_contains($final, '<!--/chrome:'));
check('gabarit conservé après site_finish', str_contains($final, 'ORIGINE') && str_contains($final, 'PIED'));

// Valeurs par défaut rendues : même structure que le gabarit Astro.
$hd = chrome_render_header(chrome_header_default());
check('en-tête par défaut : nom, liens, panier', str_contains($hd, 'class="brand" href="/">vanessa gandino</a>') && str_contains($hd, 'class="push hide-sm" href="/#livre">le livre</a>')
    && str_contains($hd, 'class="hide-sm" href="/#autrice"') && str_contains($hd, 'class="order" href="/panier">je commande <span class="cart-badge" data-cart-badge hidden></span>'));
check('en-tête : barre fixe par défaut', !str_contains($hd, 'navlite static'));
$fd = chrome_render_footer(chrome_footer_default());
check('pied par défaut : texte, lien mail, icônes', str_contains($fd, '<a href="mailto:contact@vanessagandino.fr">contact@vanessagandino.fr</a>') && str_contains($fd, '/instagram.webp') && str_contains($fd, 'aria-label="E-mail"'));
check('pied : lien externe s’ouvre à part', str_contains($fd, 'href="https://www.instagram.com/leshormonesdyvonne/" target="_blank" rel="noopener"'));

// Enregistrer un en-tête.
chrome_save($db, 'header', [
    'brand' => 'Yvonne <3', 'brand_url' => 'javascript:x',
    'links' => [['label' => 'Le livre', 'url' => '/#livre', 'mobile' => '1'], ['label' => 'Sans lien', 'url' => ''], ['label' => '', 'url' => '/x'], ['label' => 'Pirate', 'url' => 'javascript:alert(1)'], ['label' => 'Blog', 'url' => 'https://example.org']],
    'cart' => '', 'cart_label' => 'Panier', 'bg' => '#112233', 'fg' => '', 'accent' => 'inconnu', 'sticky' => '', 'pad' => '999',
]);
$h = chrome_load($db, 'header');
check('lien du nom invalide → accueil', $h['brand_url'] === '/');
check('seuls les liens complets et valides sont gardés', count($h['links']) === 2 && $h['links'][0]['label'] === 'Le livre' && $h['links'][1]['url'] === 'https://example.org');
check('lien visible sur téléphone mémorisé', $h['links'][0]['mobile'] === true && $h['links'][1]['mobile'] === false);
check('valeurs invalides ramenées aux valeurs permises', $h['accent'] === 'peach' && $h['pad'] === 22 && $h['cart'] === false && $h['sticky'] === false);
$out = chrome_apply($page, $db);
check('en-tête personnalisé en place', !str_contains($out, 'ORIGINE') && str_contains($out, 'Yvonne &lt;3'));
check('pied d’origine toujours en place', str_contains($out, 'PIED'));
check('fond libre et texte automatique', str_contains($out, '--nav-bg:#112233;--nav-fg:var(--cream);'));
check('sans panier ni barre fixe', !str_contains($out, 'cart-badge') && str_contains($out, 'navlite static'));
check('premier lien poussé à droite, visible sur téléphone', str_contains($out, 'class="push" href="/#livre"'));

// Enregistrer un pied de page.
chrome_save($db, 'footer', [
    'text' => "Contact : [écrivez-moi](mailto:a@b.fr) **vite**\n<script>",
    'links' => [['icon' => 'heart', 'url' => 'https://example.org/h', 'label' => ''], ['icon' => 'inconnue', 'url' => 'https://x.org'], ['icon' => 'mail', 'url' => 'javascript:x']],
    'bg' => 'navy', 'fg' => 'peach', 'pad' => '60',
]);
$f = chrome_load($db, 'footer');
check('une seule icône valide gardée, avec un nom par défaut', count($f['links']) === 1 && $f['links'][0]['label'] === 'Cœur');
$out = chrome_apply($page, $db);
check('pied personnalisé en place', !str_contains($out, 'PIED') && str_contains($out, '<a href="mailto:a@b.fr">écrivez-moi</a>') && str_contains($out, '<b>vite</b>'));
check('texte du pied échappé', str_contains($out, '&lt;script&gt;') && !str_contains($out, '<script>'));
check('couleurs du pied', str_contains($out, 'background:var(--navy);color:var(--peach);--foot-fg:var(--peach);padding-top:60px;'));
check('icône du pied : svg', !str_contains($out, 'instagram.webp'));

// Rétablir.
chrome_reset($db, 'header');
chrome_reset($db, 'footer');
check('rétablir : gabarit d’origine', str_contains(chrome_apply($page, $db), 'ORIGINE') && str_contains(chrome_apply($page, $db), 'PIED'));

echo $fails ? "\n$fails/$count test(s) en-tête / pied en échec\n" : "$count tests en-tête / pied OK\n";
exit($fails ? 1 : 0);
