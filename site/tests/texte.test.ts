import { describe, expect, it } from 'vitest';
import catalog from '../src/content/textes.json';
import { renderText } from '../src/lib/texte';

describe('renderText', () => {
  it('échappe, met en gras et passe à la ligne (comme text_render en PHP)', () => {
    expect(renderText('a <b> & "c" d\'e\n**LE** livre')).toBe('a &lt;b&gt; &amp; &quot;c&quot; d&#039;e<br /><b>LE</b> livre');
  });
});

describe('catalogue des textes', () => {
  const items = catalog.groups.flatMap((g) => g.items);
  it('a des clés uniques', () => {
    expect(new Set(items.map((i) => i.key)).size).toBe(items.length);
  });
});
