// Prépare deploy/hostinger.zip pour une « application Node.js » Hostinger (upload via hPanel).
// Contenu : dist/ (déjà construit), package.json, package-lock.json. Hostinger installe les dépendances.
// Usage : npm run build && npm run package:hostinger
import { execFileSync } from 'node:child_process';
import { existsSync, mkdirSync, rmSync } from 'node:fs';

if (!existsSync('dist/server/entry.mjs')) {
  console.error('dist/ absent : lancer "npm run build" avant.');
  process.exit(1);
}
mkdirSync('deploy', { recursive: true });
rmSync('deploy/hostinger.zip', { force: true });
const files = ['dist', 'package.json', 'package-lock.json'];
if (process.platform === 'win32') {
  // tar.exe de Windows (bsdtar) écrit des chemins "/" ; Compress-Archive écrirait des "\" illisibles sous Linux.
  // Chemin explicite : le "tar" de Git for Windows (GNU) ne sait pas produire de zip.
  const tar = `${process.env.SystemRoot ?? 'C:\\Windows'}\\System32\\tar.exe`;
  execFileSync(tar, ['-a', '-cf', 'deploy/hostinger.zip', ...files], { stdio: 'inherit' });
} else {
  execFileSync('zip', ['-qr', 'deploy/hostinger.zip', ...files], { stdio: 'inherit' });
}
console.log('OK : deploy/hostinger.zip');
