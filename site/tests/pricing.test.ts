import { describe, expect, it } from 'vitest';
import { computeTotals, parseShippingTable, shippingFor, formatEur } from '../src/lib/pricing';

// Mêmes règles que php/_prive/lib.php (le serveur recalcule toujours le total).
const shipping = parseShippingTable('1:4.90,2:5.90,4:7.50');
const cfg = { unitCents: 1000, maxQty: 6, shipping };

describe('pricing (affichage navigateur)', () => {
  it('parses and sorts shipping tiers', () => {
    expect(parseShippingTable('4:7.5, 1:4.9')).toEqual([
      { minQty: 1, cents: 490 },
      { minQty: 4, cents: 750 },
    ]);
  });

  it('rejects tables without a qty-1 tier or with garbage', () => {
    expect(() => parseShippingTable('2:5')).toThrow();
    expect(() => parseShippingTable('1:abc')).toThrow();
    expect(() => parseShippingTable('')).toThrow();
  });

  it('picks the highest tier reached', () => {
    expect(shippingFor(1, shipping)).toBe(490);
    expect(shippingFor(3, shipping)).toBe(590);
    expect(shippingFor(6, shipping)).toBe(750);
  });

  it('computes totals in cents', () => {
    expect(computeTotals(3, cfg)).toEqual({
      qty: 3, unitCents: 1000, subtotalCents: 3000, shippingCents: 590, totalCents: 3590,
    });
  });

  it('refuses quantities outside 1..max', () => {
    expect(() => computeTotals(0, cfg)).toThrow();
    expect(() => computeTotals(7, cfg)).toThrow();
  });

  it('formats euros the French way', () => {
    expect(formatEur(3590).replace(/\s/g, ' ')).toBe('35,90 €');
  });
});
