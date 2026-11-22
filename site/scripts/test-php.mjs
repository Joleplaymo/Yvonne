// Tests du backend PHP dans l'image PHP du projet (PHP 8.3 + GD, comme l'hébergement), via Docker.
import { execFileSync } from 'node:child_process';

const run = (args) =>
  execFileSync('docker', args, { stdio: 'inherit', env: { ...process.env, MSYS_NO_PATHCONV: '1' } });

const failed = [];
run(['compose', '-f', 'docker-compose.dev.yml', 'build', '-q', 'php']);
for (const test of ['tests/php/lib_test.php', 'tests/php/ebook_test.php', 'tests/php/ebook_admin_test.php', 'tests/php/epub_test.php', 'tests/php/telechargement_test.php', 'tests/php/photos_test.php', 'tests/php/textes_test.php', 'tests/php/couleurs_test.php', 'tests/php/accueil_test.php', 'tests/php/entete_test.php', 'tests/php/seo_test.php', 'tests/php/favicon_test.php']) {
  try {
    run([
      'compose', '-f', 'docker-compose.dev.yml', 'run', '--rm', '--no-deps',
      '-v', `${process.cwd()}:/app`, '-w', '/app', '--entrypoint', 'php', 'php', test,
    ]);
  } catch {
    failed.push(test);
  }
}
if (failed.length) {
  console.error('\n' + failed.length + ' suite(s) en échec :\n  ' + failed.join('\n  '));
  process.exit(1);
}
console.log('\nToutes les suites PHP passent.');
