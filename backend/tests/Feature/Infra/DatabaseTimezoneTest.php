<?php

declare(strict_types=1);

use App\Services\OtpService;
use Illuminate\Support\Facades\DB;

uses()->group('infra');

/*
 * Incident du 26/10/2026 : PostgreSQL de production en Europe/Berlin, Laravel
 * en UTC. Les dates écrites sans décalage étaient relues décalées et les
 * codes de réinitialisation du PIN naissaient expirés.
 *
 * On simule un serveur réglé sur Berlin (PGTZ, appliqué par libpq à chaque
 * nouvelle connexion) puis on rouvre la connexion : c'est la configuration
 * Laravel qui doit imposer le fuseau de l'application.
 */
it('aligne la session PostgreSQL sur le fuseau de l\'application et garde les codes SMS valides', function (): void {
    putenv('PGTZ=Europe/Berlin');

    try {
        DB::purge('pgsql');
        DB::reconnect('pgsql');

        expect(DB::selectOne('show timezone')->TimeZone)->toBe(config('app.timezone'));

        config()->set('pssfp.otp.cooldown_seconds', 0);
        $otp = app(OtpService::class);
        $code = $otp->generate('+237699000001', 'reset_pin');

        expect($otp->validate('+237699000001', $code, 'reset_pin'))->toBe('ok');
    } finally {
        putenv('PGTZ');
        DB::table('sms_otps')->where('phone_e164', '+237699000001')->delete();
    }
});
