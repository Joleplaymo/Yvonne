// Point d'accès unique à la config runtime et à la base, pour les pages SSR et les endpoints.
import {
  BOOK_PRICE_EUR,
  MAX_QTY,
  SHIPPING_TABLE,
  REVOLUT_LINK_TEMPLATE,
  MR_BRAND,
  DATA_DIR,
  PUBLIC_BASE_URL,
} from 'astro:env/server';
import path from 'node:path';
import { OrderStore } from './db';
import { eurosToCents, parseShippingTable, type PricingConfig } from './pricing';

export const pricing: PricingConfig = {
  unitCents: eurosToCents(BOOK_PRICE_EUR ?? 10),
  maxQty: MAX_QTY ?? 6,
  shipping: parseShippingTable(SHIPPING_TABLE ?? '1:4.90'),
};

export const revolutTemplate = REVOLUT_LINK_TEMPLATE ?? 'https://revolut.me/v_gandino';
export const mrBrand = (MR_BRAND ?? '').trim() ? (MR_BRAND as string) : '';
export const baseUrl = (PUBLIC_BASE_URL ?? 'http://localhost:4321').replace(/\/$/, '');

let store: OrderStore | undefined;
export function orders(): OrderStore {
  store ??= new OrderStore(path.resolve(DATA_DIR ?? './data', 'commandes.sqlite'));
  return store;
}

/** Limite simple en mémoire : n requêtes par fenêtre et par IP. */
const hits = new Map<string, number[]>();
export function rateLimited(key: string, max = 8, windowMs = 60 * 60 * 1000): boolean {
  const now = Date.now();
  const recent = (hits.get(key) ?? []).filter((t) => now - t < windowMs);
  recent.push(now);
  hits.set(key, recent);
  if (hits.size > 5000) hits.clear();
  return recent.length > max;
}
