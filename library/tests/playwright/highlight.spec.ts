import { test, expect } from '@playwright/test';
import { sanitizeHighlight } from '../../lib/highlight';

test('ne garde que les balises <mark>', () => {
  expect(sanitizeHighlight('La <mark>décentralisation</mark> fiscale')).toBe('La <mark>décentralisation</mark> fiscale');
  expect(sanitizeHighlight('<img src=x onerror=alert(1)><mark>a</mark>')).toBe('&lt;img src=x onerror=alert(1)&gt;<mark>a</mark>');
  expect(sanitizeHighlight('&lt;script&gt; <mark class="x">b</mark>')).toBe('&lt;script&gt; &lt;mark class="x"&gt;b</mark>');
});

test('conserve les entités produites par htmlspecialchars (ENT_QUOTES)', () => {
  expect(sanitizeHighlight('l&#039;<mark>impôt</mark>')).toBe('l&#039;<mark>impôt</mark>');
  expect(sanitizeHighlight('l&#x27;impôt &apos; &quot;budget&quot;')).toBe('l&#x27;impôt &apos; &quot;budget&quot;');
  expect(sanitizeHighlight('R&D & co')).toBe('R&amp;D &amp; co');
});

test('est idempotent', () => {
  for (const input of ['&amp;', 'R&D', '<b>x</b>', 'l&#039;impôt <mark>tva</mark>']) {
    const once = sanitizeHighlight(input);
    expect(sanitizeHighlight(once)).toBe(once);
  }
  expect(sanitizeHighlight('&amp;')).toBe('&amp;');
});

test('balises <mark> orphelines, imbriquées ou détournées : aucun attribut rétabli', () => {
  const cases = [
    '<mark>non fermée',
    '<mark><mark>a</mark>',
    '</mark>b<mark>',
    '<<mark>>x',
    '<mark onclick=alert(1)>c</mark>',
    '<MARK>d</MARK>',
    '<mark/onmouseover=alert(1)>e',
    '&lt;mark onclick=x&gt;f',
  ];
  for (const input of cases) {
    const out = sanitizeHighlight(input);
    // Toute balise restante est exactement <mark> ou </mark>.
    const tags = out.match(/<[^>]*>/g) ?? [];
    for (const tag of tags) expect(['<mark>', '</mark>']).toContain(tag);
    expect(out).not.toMatch(/<(?!\/?mark>)/);
  }
});
