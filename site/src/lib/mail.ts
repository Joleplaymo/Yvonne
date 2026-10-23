import nodemailer from 'nodemailer';
import { SMTP_URL, MAIL_FROM, ADMIN_EMAIL } from 'astro:env/server';
import { formatEur } from './pricing';
import { revolutLink } from './revolut';
import type { Order } from './db';
import { baseUrl, revolutTemplate } from './server';

const transport = SMTP_URL ? nodemailer.createTransport(SMTP_URL) : null;

async function send(to: string, subject: string, text: string): Promise<void> {
  if (!transport) {
    console.info(`[mail non envoyé — SMTP_URL vide] À: ${to}\nObjet: ${subject}\n${text}\n`);
    return;
  }
  try {
    await transport.sendMail({ from: MAIL_FROM, to, subject, text, replyTo: ADMIN_EMAIL });
  } catch (err) {
    // Une panne SMTP ne doit pas faire échouer la commande : elle est déjà enregistrée.
    console.error(`[mail] échec d'envoi à ${to} :`, err);
  }
}

function recap(o: Order): string {
  return [
    `Commande ${o.ref}`,
    `${o.qty} × « Les hormones d'Yvonne » — ${formatEur(o.qty * o.unit_cents)}`,
    `Livraison Mondial Relay — ${formatEur(o.shipping_cents)}`,
    `Total : ${formatEur(o.total_cents)}`,
    '',
    'Point relais :',
    `${o.relay_name}${o.relay_id ? ` (n° ${o.relay_id})` : ''}`,
    o.relay_address,
    `${o.relay_postcode} ${o.relay_city} — ${o.relay_country}`,
  ].join('\n');
}

export function orderPageUrl(o: Order): string {
  return `${baseUrl}/merci?ref=${encodeURIComponent(o.ref)}&t=${o.token}`;
}

export async function mailOrderCreated(o: Order): Promise<void> {
  const pay = revolutLink(revolutTemplate, o.total_cents, o.ref);
  await send(
    o.email,
    `Votre commande ${o.ref} — Les hormones d'Yvonne`,
    `Bonjour ${o.first_name},

Merci pour votre commande ! Elle sera expédiée dès réception du paiement.

${recap(o)}

Pour régler ${formatEur(o.total_cents)} avec Revolut :
${pay}
Merci d'indiquer la référence ${o.ref} dans le message du paiement.

Suivre votre commande : ${orderPageUrl(o)}

Hormonalement vôtre,
Vanessa`,
  );
  if (ADMIN_EMAIL) {
    await send(
      ADMIN_EMAIL,
      `Nouvelle commande ${o.ref} — ${formatEur(o.total_cents)}`,
      `${recap(o)}

Client : ${o.first_name} ${o.last_name} — ${o.email} — ${o.phone}
${o.note ? `Message : ${o.note}\n` : ''}
Admin : ${baseUrl}/admin/${o.ref}`,
    );
  }
}

export async function mailOrderPaid(o: Order): Promise<void> {
  await send(
    o.email,
    `Paiement reçu — commande ${o.ref}`,
    `Bonjour ${o.first_name},

Votre paiement de ${formatEur(o.total_cents)} est bien arrivé. Je prépare votre colis avec soin !
Vous recevrez un e-mail avec le numéro de suivi dès l'expédition.

Suivre votre commande : ${orderPageUrl(o)}

Vanessa`,
  );
}

export async function mailOrderShipped(o: Order): Promise<void> {
  await send(
    o.email,
    `Votre colis est parti — commande ${o.ref}`,
    `Bonjour ${o.first_name},

Votre colis est en route vers :
${o.relay_name}, ${o.relay_address}, ${o.relay_postcode} ${o.relay_city}
${o.tracking ? `\nNuméro de suivi Mondial Relay : ${o.tracking}\nSuivi : https://www.mondialrelay.fr/suivi-de-colis/\n` : ''}
Mondial Relay vous préviendra par SMS/e-mail à son arrivée.

Bonne lecture !
Vanessa`,
  );
}
