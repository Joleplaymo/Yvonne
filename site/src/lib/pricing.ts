// Calculs en centimes pour éviter les erreurs d'arrondi.

export type ShippingTier = { minQty: number; cents: number };

export type PricingConfig = {
  unitCents: number;
  maxQty: number;
  shipping: ShippingTier[];
};

export type Totals = {
  qty: number;
  unitCents: number;
  subtotalCents: number;
  shippingCents: number;
  totalCents: number;
};

export function eurosToCents(eur: number): number {
  return Math.round(eur * 100);
}

/** "1:4.90,2:5.90,4:7.50" -> paliers triés par quantité minimale. */
export function parseShippingTable(raw: string): ShippingTier[] {
  const tiers = raw
    .split(',')
    .map((part) => part.trim())
    .filter(Boolean)
    .map((part) => {
      const [q, eur] = part.split(':');
      const minQty = Number(q);
      const price = Number(eur);
      if (!Number.isInteger(minQty) || minQty < 1 || !Number.isFinite(price) || price < 0) {
        throw new Error(`SHIPPING_TABLE invalide : "${part}"`);
      }
      return { minQty, cents: eurosToCents(price) };
    })
    .sort((a, b) => a.minQty - b.minQty);
  if (tiers.length === 0 || tiers[0].minQty !== 1) {
    throw new Error('SHIPPING_TABLE doit commencer par un palier "1:"');
  }
  return tiers;
}

export function shippingFor(qty: number, tiers: ShippingTier[]): number {
  let cents = tiers[0].cents;
  for (const tier of tiers) if (qty >= tier.minQty) cents = tier.cents;
  return cents;
}

export function computeTotals(qty: number, cfg: PricingConfig): Totals {
  if (!Number.isInteger(qty) || qty < 1 || qty > cfg.maxQty) {
    throw new Error(`Quantité invalide (1 à ${cfg.maxQty})`);
  }
  const subtotalCents = qty * cfg.unitCents;
  const shippingCents = shippingFor(qty, cfg.shipping);
  return { qty, unitCents: cfg.unitCents, subtotalCents, shippingCents, totalCents: subtotalCents + shippingCents };
}

export function formatEur(cents: number): string {
  return (cents / 100).toLocaleString('fr-FR', { style: 'currency', currency: 'EUR' });
}
