// Panier côté navigateur : un seul produit, on ne stocke que la quantité.
const KEY = 'yvonne-cart';

export type Cart = { qty: number };

export function readCart(): Cart {
  try {
    const raw = JSON.parse(localStorage.getItem(KEY) ?? '{}');
    const qty = Number(raw.qty);
    return { qty: Number.isInteger(qty) && qty > 0 ? qty : 0 };
  } catch {
    return { qty: 0 };
  }
}

export function writeCart(cart: Cart): void {
  try {
    if (cart.qty > 0) localStorage.setItem(KEY, JSON.stringify(cart));
    else localStorage.removeItem(KEY);
  } catch {
    /* navigation privée : le panier vit le temps de la page */
  }
  window.dispatchEvent(new CustomEvent('cart:change'));
}
