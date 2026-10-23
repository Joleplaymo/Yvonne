export type OrderInput = {
  qty: number;
  firstName: string;
  lastName: string;
  email: string;
  phone: string;
  relayId: string;
  relayName: string;
  relayAddress: string;
  relayPostcode: string;
  relayCity: string;
  relayCountry: string;
  note: string;
};

export type ValidationResult =
  | { ok: true; value: OrderInput }
  | { ok: false; errors: Record<string, string> };

const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
// Téléphone : chiffres, espaces, points, tirets, + initial. 9 à 15 chiffres.
const PHONE_RE = /^\+?[\d\s.\-()]{9,20}$/;
const COUNTRIES = ['FR', 'BE', 'LU', 'ES', 'NL', 'PT', 'DE', 'AT', 'IT'];

function text(raw: unknown, max: number): string {
  return typeof raw === 'string' ? raw.trim().replace(/\s+/g, ' ').slice(0, max) : '';
}

export function validateOrder(body: Record<string, unknown>, maxQty: number): ValidationResult {
  const errors: Record<string, string> = {};
  const qty = Number(body.qty);
  const v: OrderInput = {
    qty,
    firstName: text(body.firstName, 80),
    lastName: text(body.lastName, 80),
    email: text(body.email, 160).toLowerCase(),
    phone: text(body.phone, 20),
    relayId: text(body.relayId, 20),
    relayName: text(body.relayName, 120),
    relayAddress: text(body.relayAddress, 200),
    relayPostcode: text(body.relayPostcode, 10),
    relayCity: text(body.relayCity, 80),
    relayCountry: text(body.relayCountry, 2).toUpperCase() || 'FR',
    note: typeof body.note === 'string' ? body.note.trim().slice(0, 1000) : '',
  };

  if (!Number.isInteger(qty) || qty < 1 || qty > maxQty) errors.qty = `Entre 1 et ${maxQty} exemplaires.`;
  if (!v.firstName) errors.firstName = 'Prénom requis.';
  if (!v.lastName) errors.lastName = 'Nom requis.';
  if (!EMAIL_RE.test(v.email)) errors.email = 'Adresse e-mail invalide.';
  if (!PHONE_RE.test(v.phone) || v.phone.replace(/\D/g, '').length < 9) {
    errors.phone = 'Numéro de téléphone invalide (Mondial Relay envoie un SMS à l’arrivée du colis).';
  }
  if (!v.relayName) errors.relayName = 'Nom du point relais requis.';
  if (!v.relayAddress) errors.relayAddress = 'Adresse du point relais requise.';
  if (!/^[0-9A-Z\- ]{4,10}$/i.test(v.relayPostcode)) errors.relayPostcode = 'Code postal invalide.';
  if (!v.relayCity) errors.relayCity = 'Ville requise.';
  if (!COUNTRIES.includes(v.relayCountry)) errors.relayCountry = 'Pays non desservi.';
  if (v.relayId && !/^[0-9A-Z\-]{1,20}$/i.test(v.relayId)) errors.relayId = 'Code relais invalide.';

  return Object.keys(errors).length ? { ok: false, errors } : { ok: true, value: v };
}
