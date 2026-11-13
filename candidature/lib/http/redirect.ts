import { NextResponse } from 'next/server';

/**
 * Redirection à `Location` relative pour les Route Handlers.
 *
 * Derrière nginx, `request.url` d'un Route Handler porte l'hôte interne
 * (`localhost:6003`) : `new URL(path, request.url)` renverrait le visiteur
 * hors du site. Une `Location` relative est résolue par le navigateur sur
 * l'adresse publique qu'il a demandée.
 */
export function redirectTo(path: string, status: 303 | 307 = 307): NextResponse {
  // 303 après un formulaire POST : le navigateur revient en GET sur la page.
  return new NextResponse(null, { status, headers: { Location: path } });
}
