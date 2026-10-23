import type { APIRoute } from 'astro';
import { validateOrder } from '../../lib/validation';
import { computeTotals } from '../../lib/pricing';
import { orders, pricing, rateLimited } from '../../lib/server';
import { mailOrderCreated } from '../../lib/mail';

const json = (status: number, data: unknown) =>
  new Response(JSON.stringify(data), { status, headers: { 'Content-Type': 'application/json' } });

export const POST: APIRoute = async ({ request, clientAddress }) => {
  if (!request.headers.get('content-type')?.includes('application/json')) {
    return json(415, { message: 'Format non supporté.' });
  }
  // Derrière Caddy, la dernière entrée de X-Forwarded-For est l'IP vue par le proxy (non falsifiable).
  const ip = request.headers.get('x-forwarded-for')?.split(',').pop()?.trim() || clientAddress;
  if (rateLimited(`order:${ip}`)) {
    return json(429, { message: 'Trop de commandes en peu de temps. Réessayez plus tard ou écrivez-nous.' });
  }

  let body: Record<string, unknown>;
  try {
    body = await request.json();
  } catch {
    return json(400, { message: 'Requête invalide.' });
  }
  // Pot de miel anti-robots : un humain ne voit pas ce champ.
  if (typeof body.website === 'string' && body.website.trim()) {
    return json(400, { message: 'Requête invalide.' });
  }

  const result = validateOrder(body, pricing.maxQty);
  if (!result.ok) return json(422, { errors: result.errors });

  const totals = computeTotals(result.value.qty, pricing);
  const order = orders().create(result.value, totals);
  await mailOrderCreated(order);

  return json(201, { ref: order.ref, url: `/merci?ref=${encodeURIComponent(order.ref)}&t=${order.token}` });
};
