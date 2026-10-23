/**
 * Construit le lien de paiement Revolut.
 * Le modèle peut contenir {amount} (ex. "12.50") et {ref} (ex. "YV-0007").
 * Sans {amount}, le lien est utilisé tel quel et le client saisit le montant.
 */
export function revolutLink(template: string, totalCents: number, ref: string): string {
  const amount = (totalCents / 100).toFixed(2);
  return template.replaceAll('{amount}', amount).replaceAll('{ref}', encodeURIComponent(ref));
}
