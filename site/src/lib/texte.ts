// Mise en forme d'un texte modifiable depuis l'admin (/admin/textes).
// Doit rester identique à text_render() dans php/_prive/textes.php.
const esc = (s: string) =>
  s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');

/** Texte brut → HTML : échappé, **gras**, retours à la ligne. */
export function renderText(raw: string): string {
  return esc(raw.replace(/\r\n?/g, '\n'))
    .replace(/\*\*(.+?)\*\*/gs, '<b>$1</b>')
    .replace(/\n/g, '<br />');
}
