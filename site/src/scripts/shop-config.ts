import type { PricingConfig } from '../lib/pricing';

export type ShopConfig = PricingConfig & { mrBrand: string };

/** Prix, frais de port et code Mondial Relay, servis par le backend PHP (php/api/config.php). */
export async function loadShopConfig(): Promise<ShopConfig> {
  const res = await fetch('/api/config', { headers: { Accept: 'application/json' } });
  if (!res.ok) throw new Error(`config ${res.status}`);
  return res.json();
}
