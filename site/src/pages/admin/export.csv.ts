import type { APIRoute } from 'astro';
import { orders } from '../../lib/server';
import { STATUS_LABELS } from '../../lib/db';

const COLUMNS = [
  'ref', 'created_at', 'status', 'qty', 'total_cents', 'shipping_cents', 'first_name', 'last_name', 'email', 'phone',
  'relay_id', 'relay_name', 'relay_address', 'relay_postcode', 'relay_city', 'relay_country',
  'paid_at', 'shipped_at', 'tracking', 'note', 'admin_note',
] as const;

function cell(v: unknown): string {
  let s = v == null ? '' : String(v);
  // Neutralise les formules quand le fichier est ouvert dans un tableur.
  if (/^[=+\-@\t\r]/.test(s)) s = `'${s}`;
  return /[";\n\r]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s;
}

export const GET: APIRoute = () => {
  const rows = orders()
    .list()
    .map((o) =>
      COLUMNS.map((c) => {
        if (c === 'status') return cell(STATUS_LABELS[o.status]);
        if (c === 'total_cents' || c === 'shipping_cents') return cell((o[c] / 100).toFixed(2).replace('.', ','));
        return cell(o[c]);
      }).join(';'),
    );
  const header = COLUMNS.map((c) => c.replace('_cents', '_eur')).join(';');
  // BOM pour qu'Excel lise l'UTF-8 ; séparateur ";" pour Excel en français.
  return new Response('﻿' + [header, ...rows].join('\r\n'), {
    headers: {
      'Content-Type': 'text/csv; charset=utf-8',
      'Content-Disposition': `attachment; filename="commandes-${new Date().toISOString().slice(0, 10)}.csv"`,
    },
  });
};
