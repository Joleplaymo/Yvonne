import { describe, expect, it } from 'vitest';
import { computeTotals, parseShippingTable, shippingFor, formatEur } from '../src/lib/pricing';
import { revolutLink } from '../src/lib/revolut';
import { validateOrder } from '../src/lib/validation';
import { OrderStore } from '../src/lib/db';

const shipping = parseShippingTable('1:4.90,2:5.90,4:7.50');
const cfg = { unitCents: 1000, maxQty: 6, shipping };

describe('pricing', () => {
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
    expect(() => computeTotals(1.5, cfg)).toThrow();
  });

  it('formats euros the French way', () => {
    expect(formatEur(3590).replace(/\s/g, ' ')).toBe('35,90 €');
  });
});

describe('revolutLink', () => {
  it('fills amount and ref placeholders', () => {
    expect(revolutLink('https://revolut.me/x/{amount}EUR?r={ref}', 3590, 'YV-0001')).toBe(
      'https://revolut.me/x/35.90EUR?r=YV-0001',
    );
  });
  it('leaves a plain link untouched', () => {
    expect(revolutLink('https://revolut.me/x', 3590, 'YV-0001')).toBe('https://revolut.me/x');
  });
});

const good = {
  qty: '2', firstName: ' Yvonne ', lastName: 'Dupont', email: 'Y@Example.fr', phone: '06 12 34 56 78',
  relayId: '012345', relayName: 'Tabac du Centre', relayAddress: '12 rue de la Paix',
  relayPostcode: '75002', relayCity: 'Paris', relayCountry: 'fr', note: '',
};

describe('validateOrder', () => {
  it('accepts and normalises a correct order', () => {
    const r = validateOrder(good, 6);
    expect(r.ok).toBe(true);
    if (r.ok) {
      expect(r.value.qty).toBe(2);
      expect(r.value.firstName).toBe('Yvonne');
      expect(r.value.email).toBe('y@example.fr');
      expect(r.value.relayCountry).toBe('FR');
    }
  });

  it('reports every missing field', () => {
    const r = validateOrder({ qty: 9 }, 6);
    expect(r.ok).toBe(false);
    if (!r.ok) {
      expect(Object.keys(r.errors).sort()).toEqual(
        ['email', 'firstName', 'lastName', 'phone', 'qty', 'relayAddress', 'relayCity', 'relayName', 'relayPostcode'].sort(),
      );
    }
  });

  it('relay code is optional (manual entry)', () => {
    expect(validateOrder({ ...good, relayId: '' }, 6).ok).toBe(true);
  });
});

describe('OrderStore', () => {
  it('creates sequential refs, lists and updates status', () => {
    const store = new OrderStore(':memory:');
    const input = validateOrder(good, 6);
    if (!input.ok) throw new Error('fixture invalid');
    const totals = computeTotals(2, cfg);
    const a = store.create(input.value, totals);
    const b = store.create(input.value, totals);
    expect(a.ref).toBe('YV-0001');
    expect(b.ref).toBe('YV-0002');
    expect(a.token).toMatch(/^[0-9a-f]{32}$/);
    expect(a.status).toBe('attente_paiement');
    expect(a.total_cents).toBe(2590);

    store.setStatus('YV-0001', 'payee');
    const shipped = store.setStatus('YV-0001', 'expediee', '12345678');
    expect(shipped?.tracking).toBe('12345678');
    expect(shipped?.paid_at).toBeTruthy();
    expect(store.counts()).toEqual({ attente_paiement: 1, payee: 0, expediee: 1, annulee: 0 });
    expect(store.list('expediee').map((o) => o.ref)).toEqual(['YV-0001']);
  });
});
