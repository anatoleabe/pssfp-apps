<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Contact;

use App\Http\Controllers\Controller;
use App\Http\Requests\Contact\SendContactRequest;
use App\Mail\ContactAutoReplyMailable;
use App\Mail\ContactMessageMailable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * POST /v1/contact (cf. spec module 1 PR O).
 *
 * - Rate limit : 5 messages / IP / heure (anti-spam léger côté serveur).
 * - Envoi du message à contact@pssfp.org.
 * - Auto-reply au sender.
 * - Retour 201 si OK, 422 si validation, 429 si rate-limit.
 */
final class ContactController extends Controller
{
    public function send(SendContactRequest $request): JsonResponse
    {
        $ip = (string) ($request->ip() ?? 'unknown');
        $key = 'contact:'.$ip;

        if (RateLimiter::tooManyAttempts($key, 5)) {
            $seconds = RateLimiter::availableIn($key);

            return response()->json([
                'message' => 'Trop de messages envoyés. Réessayez dans '.ceil($seconds / 60).' minutes.',
            ], Response::HTTP_TOO_MANY_REQUESTS);
        }

        $payload = array_merge($request->validated(), ['ip' => $ip]);

        $recipient = config('mail.contact_recipient', 'contact@pssfp.org');

        try {
            Mail::to($recipient)->send(new ContactMessageMailable($payload));
            Mail::to($payload['email'])->send(new ContactAutoReplyMailable($payload));
        } catch (\Throwable $e) {
            Log::error('contact_mail_send_failed', [
                'error' => $e->getMessage(),
                'ip' => $ip,
            ]);

            return response()->json([
                'message' => 'Le message n\'a pas pu être transmis. Réessayez ultérieurement ou écrivez directement à '.$recipient.'.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        RateLimiter::hit($key, 3600);

        return response()->json([
            'message' => 'Votre message a bien été envoyé. Vous recevrez une confirmation par email.',
        ], Response::HTTP_CREATED);
    }
}
