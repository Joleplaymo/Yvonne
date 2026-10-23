import { defineMiddleware } from 'astro:middleware';
import { ADMIN_USER, ADMIN_PASSWORD } from 'astro:env/server';
import { createHash, timingSafeEqual } from 'node:crypto';

function same(a: string, b: string): boolean {
  const ha = createHash('sha256').update(a).digest();
  const hb = createHash('sha256').update(b).digest();
  return timingSafeEqual(ha, hb);
}

function authorized(header: string | null): boolean {
  if (!header?.startsWith('Basic ')) return false;
  const decoded = Buffer.from(header.slice(6), 'base64').toString('utf8');
  const sep = decoded.indexOf(':');
  if (sep < 0) return false;
  const userOk = same(decoded.slice(0, sep), ADMIN_USER ?? '');
  const passOk = same(decoded.slice(sep + 1), ADMIN_PASSWORD ?? '');
  return userOk && passOk;
}

export const onRequest = defineMiddleware(async (ctx, next) => {
  const p = ctx.url.pathname;
  if (p === '/admin' || p.startsWith('/admin/')) {
    if (!ADMIN_PASSWORD) {
      return new Response('Admin désactivé : définir ADMIN_PASSWORD dans .env', { status: 503 });
    }
    if (!authorized(ctx.request.headers.get('authorization'))) {
      return new Response('Authentification requise', {
        status: 401,
        headers: { 'WWW-Authenticate': 'Basic realm="admin", charset="UTF-8"' },
      });
    }
    const res = await next();
    res.headers.set('Cache-Control', 'no-store');
    res.headers.set('X-Robots-Tag', 'noindex');
    return res;
  }
  return next();
});
