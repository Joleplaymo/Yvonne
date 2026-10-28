// Assemble le site déployable dans build/public_html : pages statiques (dist/) + backend PHP (php/).
// Les gabarits HTML générés par Astro sont rangés dans _prive/ (non servis).
// Option --zip : produit aussi deploy/hostinger.zip (contenu de public_html, à décompresser à la racine du site).
import { cpSync, existsSync, mkdirSync, renameSync, rmSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import path from 'node:path';
import sharp from 'sharp';

const out = 'build/public_html';
if (!existsSync('dist/index.html')) {
  console.error('dist/ absent : lancer "npm run build" avant.');
  process.exit(1);
}
rmSync('build', { recursive: true, force: true });
mkdirSync(out, { recursive: true });
cpSync('dist', out, { recursive: true });
cpSync('php', out, { recursive: true });
// L'accueil est servi par php/index.php (textes modifiables) ; le catalogue des textes accompagne le code PHP.
renameSync(`${out}/index.html`, `${out}/_prive/accueil.html`);
// Panier et commande passent aussi par PHP (couleurs modifiables depuis /admin/couleurs).
for (const p of ['panier', 'commande']) renameSync(`${out}/${p}.html`, `${out}/_prive/${p}.html`);
cpSync('src/content/textes.json', `${out}/_prive/textes.json`);
for (const t of ['gabarit', 'gabarit-admin']) renameSync(`${out}/${t}.html`, `${out}/_prive/${t}.html`);

// Photos d'origine (remplaçables ensuite depuis /admin/photos), mêmes largeurs que php/_prive/photos.php.
const photos = {
  couverture: 'src/assets/couverture.png',
  portrait: 'src/assets/vanessa.png',
  extrait: 'src/assets/quatrieme.jpg',
  partenaire: 'src/assets/gardemanger.jpg',
};
mkdirSync(`${out}/_prive/photos`, { recursive: true });
for (const [slot, file] of Object.entries(photos)) {
  for (const width of [480, 960]) {
    await sharp(file)
      .resize({ width, withoutEnlargement: true })
      .flatten({ background: '#F9F0DA' })
      .webp({ quality: 82 })
      .toFile(`${out}/_prive/photos/${slot}-${width}.webp`);
  }
}
console.log(`OK : ${out}`);

if (process.argv.includes('--zip')) {
  mkdirSync('deploy', { recursive: true });
  const zip = path.resolve('deploy/hostinger.zip');
  rmSync(zip, { force: true });
  if (process.platform === 'win32') {
    // tar.exe de Windows (bsdtar) : chemins "/" (Compress-Archive écrirait des "\" illisibles sous Linux).
    const tar = `${process.env.SystemRoot ?? 'C:\\Windows'}\\System32\\tar.exe`;
    execFileSync(tar, ['-a', '-cf', zip, '-C', out, '.'], { stdio: 'inherit' });
  } else {
    execFileSync('zip', ['-qr', zip, '.'], { cwd: out, stdio: 'inherit' });
  }
  console.log('OK : deploy/hostinger.zip');
}
