<?php
declare(strict_types=1);
// Tests des textes modifiables de l'accueil. Lancement : npm run test:php.

$data = sys_get_temp_dir() . '/yv-textes-' . getmypid();
putenv("DATA_DIR=$data");
putenv('LOG_FILE=stderr');
require __DIR__ . '/../../php/_prive/lib.php';
require __DIR__ . '/../../php/_prive/textes.php';

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

$db = db();
check('catalogue chargé', count(text_items()) > 20);
check('rendu : échappement, gras, retour à la ligne',
    text_render("a <b> & \"c\" d'e\n**LE** livre") === 'a &lt;b&gt; &amp; &quot;c&quot; d&#039;e<br /><b>LE</b> livre');

$page = 'x<!--t:hero_titre-->les<br />hormones<!--/t:hero_titre-->y<!--t:bouton_principal-->A<!--/t:bouton_principal-->'
    . '<!--t:bouton_principal-->A<!--/t:bouton_principal-->z';
check('sans modification : page inchangée', text_apply($page, []) === $page);

check('enregistrement', text_save($db, 'bouton_principal', "  Commandez-le <vite>\r\n") === null);
$o = text_overrides($db);
check('texte rangé nettoyé', ($o['bouton_principal'] ?? null) === "Commandez-le <vite>");
$out = text_apply($page, $o);
check('texte remplacé aux deux endroits', substr_count($out, 'Commandez-le &lt;vite&gt;') === 2);
check('autres textes intacts', str_contains($out, '<!--t:hero_titre-->les<br />hormones<!--/t:hero_titre-->'));

check('clé inconnue refusée', text_save($db, 'inconnue', 'x') !== null);
check('texte trop long refusé', text_save($db, 'bouton_principal', str_repeat('a', TEXT_MAX_CHARS + 1)) !== null);
check('le refus ne modifie rien', text_overrides($db)['bouton_principal'] === 'Commandez-le <vite>');

check('texte identique à l’original = retour à l’original', text_save($db, 'bouton_principal', text_items()['bouton_principal']['default']) === null);
check('plus de texte personnalisé', text_overrides($db) === []);
text_save($db, 'bouton_principal', 'Oui');
text_save($db, 'bouton_principal', '   ');
check('texte vide = retour à l’original', text_overrides($db) === []);

echo $fails ? "\n$fails/$count test(s) textes en échec\n" : "$count tests textes OK\n";
exit($fails ? 1 : 0);
