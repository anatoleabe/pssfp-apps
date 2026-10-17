import { test, expect } from '@playwright/test';
import { sanitizeHighlight } from '../../lib/highlight';

test('ne garde que les balises <mark>', () => {
  expect(sanitizeHighlight('La <mark>décentralisation</mark> fiscale')).toBe('La <mark>décentralisation</mark> fiscale');
  expect(sanitizeHighlight('<img src=x onerror=alert(1)><mark>a</mark>')).toBe('&lt;img src=x onerror=alert(1)&gt;<mark>a</mark>');
  expect(sanitizeHighlight('&lt;script&gt; <mark class="x">b</mark>')).toBe('&lt;script&gt; &lt;mark class="x"&gt;b</mark>');
});
