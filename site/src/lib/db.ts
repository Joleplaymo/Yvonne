import { DatabaseSync } from 'node:sqlite';
import { mkdirSync } from 'node:fs';
import path from 'node:path';
import { randomBytes } from 'node:crypto';
import type { OrderInput } from './validation';
import type { Totals } from './pricing';

export const STATUSES = ['attente_paiement', 'payee', 'expediee', 'annulee'] as const;
export type Status = (typeof STATUSES)[number];

export const STATUS_LABELS: Record<Status, string> = {
  attente_paiement: 'En attente de paiement',
  payee: 'Payée — à expédier',
  expediee: 'Expédiée',
  annulee: 'Annulée',
};

export type Order = {
  id: number;
  ref: string;
  token: string;
  created_at: string;
  status: Status;
  qty: number;
  unit_cents: number;
  shipping_cents: number;
  total_cents: number;
  first_name: string;
  last_name: string;
  email: string;
  phone: string;
  relay_id: string;
  relay_name: string;
  relay_address: string;
  relay_postcode: string;
  relay_city: string;
  relay_country: string;
  note: string;
  tracking: string;
  admin_note: string;
  paid_at: string | null;
  shipped_at: string | null;
};

const SCHEMA = `
CREATE TABLE IF NOT EXISTS orders (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  ref TEXT NOT NULL UNIQUE,
  token TEXT NOT NULL,
  created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ','now')),
  status TEXT NOT NULL DEFAULT 'attente_paiement',
  qty INTEGER NOT NULL,
  unit_cents INTEGER NOT NULL,
  shipping_cents INTEGER NOT NULL,
  total_cents INTEGER NOT NULL,
  first_name TEXT NOT NULL,
  last_name TEXT NOT NULL,
  email TEXT NOT NULL,
  phone TEXT NOT NULL,
  relay_id TEXT NOT NULL DEFAULT '',
  relay_name TEXT NOT NULL,
  relay_address TEXT NOT NULL,
  relay_postcode TEXT NOT NULL,
  relay_city TEXT NOT NULL,
  relay_country TEXT NOT NULL DEFAULT 'FR',
  note TEXT NOT NULL DEFAULT '',
  tracking TEXT NOT NULL DEFAULT '',
  admin_note TEXT NOT NULL DEFAULT '',
  paid_at TEXT,
  shipped_at TEXT
);
CREATE INDEX IF NOT EXISTS orders_status ON orders(status);
`;

export class OrderStore {
  private db: DatabaseSync;

  constructor(file: string) {
    if (file !== ':memory:') mkdirSync(path.dirname(file), { recursive: true });
    this.db = new DatabaseSync(file);
    this.db.exec('PRAGMA journal_mode = WAL; PRAGMA busy_timeout = 5000;');
    this.db.exec(SCHEMA);
  }

  create(input: OrderInput, totals: Totals): Order {
    const token = randomBytes(16).toString('hex');
    // Référence provisoire unique, remplacée par YV-0001… dérivée de l'id.
    const tmpRef = `tmp-${token}`;
    const insert = this.db.prepare(`
      INSERT INTO orders (ref, token, qty, unit_cents, shipping_cents, total_cents,
        first_name, last_name, email, phone,
        relay_id, relay_name, relay_address, relay_postcode, relay_city, relay_country, note)
      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`);
    const { lastInsertRowid } = insert.run(
      tmpRef, token, totals.qty, totals.unitCents, totals.shippingCents, totals.totalCents,
      input.firstName, input.lastName, input.email, input.phone,
      input.relayId, input.relayName, input.relayAddress, input.relayPostcode, input.relayCity,
      input.relayCountry, input.note,
    );
    const id = Number(lastInsertRowid);
    const ref = `YV-${String(id).padStart(4, '0')}`;
    this.db.prepare('UPDATE orders SET ref = ? WHERE id = ?').run(ref, id);
    return this.byId(id)!;
  }

  byId(id: number): Order | undefined {
    return this.db.prepare('SELECT * FROM orders WHERE id = ?').get(id) as Order | undefined;
  }

  byRef(ref: string): Order | undefined {
    return this.db.prepare('SELECT * FROM orders WHERE ref = ?').get(ref) as Order | undefined;
  }

  list(status?: Status): Order[] {
    return (status
      ? this.db.prepare('SELECT * FROM orders WHERE status = ? ORDER BY id DESC').all(status)
      : this.db.prepare('SELECT * FROM orders ORDER BY id DESC').all()) as Order[];
  }

  counts(): Record<Status, number> {
    const rows = this.db.prepare('SELECT status, COUNT(*) AS n FROM orders GROUP BY status').all() as {
      status: Status;
      n: number;
    }[];
    const out = Object.fromEntries(STATUSES.map((s) => [s, 0])) as Record<Status, number>;
    for (const r of rows) out[r.status] = r.n;
    return out;
  }

  setStatus(ref: string, status: Status, tracking?: string): Order | undefined {
    const now = new Date().toISOString().slice(0, 19) + 'Z';
    if (status === 'payee') {
      this.db.prepare('UPDATE orders SET status = ?, paid_at = COALESCE(paid_at, ?) WHERE ref = ?').run(status, now, ref);
    } else if (status === 'expediee') {
      this.db
        .prepare('UPDATE orders SET status = ?, shipped_at = ?, tracking = ?, paid_at = COALESCE(paid_at, ?) WHERE ref = ?')
        .run(status, now, tracking ?? '', now, ref);
    } else {
      this.db.prepare('UPDATE orders SET status = ? WHERE ref = ?').run(status, ref);
    }
    return this.byRef(ref);
  }

  setAdminNote(ref: string, note: string): void {
    this.db.prepare('UPDATE orders SET admin_note = ? WHERE ref = ?').run(note.slice(0, 2000), ref);
  }
}
