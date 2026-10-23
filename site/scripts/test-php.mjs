// Tests du backend PHP dans le même PHP que l'hébergement (8.3), via Docker.
import { execFileSync } from 'node:child_process';

execFileSync(
  'docker',
  ['run', '--rm', '-v', `${process.cwd()}:/app`, '-w', '/app', 'php:8.3-cli', 'php', 'tests/php/lib_test.php'],
  { stdio: 'inherit', env: { ...process.env, MSYS_NO_PATHCONV: '1' } },
);
