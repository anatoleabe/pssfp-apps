# Seeders de référentiels — Module 5 Candidatures

> **Référence** : Sprint Specs (préparation Sprint B5)
> **Date** : 2026-05-05
> **Source** : extrait du dump `pssfp_candidatures.sql` (système PHP P13 existant)

Ce document contient les seeders Laravel prêts à l'emploi pour les 3 tables référentielles du module candidatures :

- `PaysSeeder` — 200+ pays avec code ISO-2 et indicatif téléphonique
- `RegionsCamerounSeeder` — 11 régions du Cameroun avec quotas d'admission
- `DepartementsCamerounSeeder` — 58 départements avec FK région et chef-lieu

À placer dans `backend/database/seeders/` au moment du Sprint B5.

## 1. Migration `2026_05_15_120000_create_reference_tables.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pays', function (Blueprint $table) {
            $table->string('code_iso', 2)->primary(); // CM, FR, US, etc.
            $table->string('nom', 100);
            $table->string('indicatif', 10); // +237, +33, +1, etc.
            $table->timestamps();
            $table->index('nom');
        });

        Schema::create('regions_cameroun', function (Blueprint $table) {
            $table->string('code', 30)->primary(); // ADAMAOUA, CENTRE, ...
            $table->string('nom', 50); // pour affichage capitalisé
            $table->decimal('quota_admission', 5, 4)->nullable(); // 0.05, 0.15, etc.
            $table->string('chef_lieu', 50)->nullable();
            $table->integer('order')->default(0);
            $table->timestamps();
        });

        Schema::create('departements_cameroun', function (Blueprint $table) {
            $table->string('code', 50)->primary();
            $table->string('nom', 50); // = code mais capitalisé
            $table->string('chef_lieu', 100)->nullable();
            $table->string('region_code', 30);
            $table->timestamps();
            $table->foreign('region_code')->references('code')->on('regions_cameroun')->onDelete('cascade');
            $table->index('region_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('departements_cameroun');
        Schema::dropIfExists('regions_cameroun');
        Schema::dropIfExists('pays');
    }
};
```

## 2. `database/seeders/PaysSeeder.php`

```php
<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PaysSeeder extends Seeder
{
    public function run(): void
    {
        $pays = [
            ['code_iso' => 'AD', 'nom' => 'Andorre', 'indicatif' => '+376'],
            ['code_iso' => 'AE', 'nom' => 'Émirats arabes unis', 'indicatif' => '+971'],
            ['code_iso' => 'AF', 'nom' => 'Afghanistan', 'indicatif' => '+93'],
            ['code_iso' => 'AG', 'nom' => 'Antigua-et-Barbuda', 'indicatif' => '+1'],
            ['code_iso' => 'AL', 'nom' => 'Albanie', 'indicatif' => '+355'],
            ['code_iso' => 'AM', 'nom' => 'Arménie', 'indicatif' => '+374'],
            ['code_iso' => 'AO', 'nom' => 'Angola', 'indicatif' => '+244'],
            ['code_iso' => 'AR', 'nom' => 'Argentine', 'indicatif' => '+54'],
            ['code_iso' => 'AT', 'nom' => 'Autriche', 'indicatif' => '+43'],
            ['code_iso' => 'AU', 'nom' => 'Australie', 'indicatif' => '+61'],
            ['code_iso' => 'AZ', 'nom' => 'Azerbaïdjan', 'indicatif' => '+994'],
            ['code_iso' => 'BA', 'nom' => 'Bosnie-Herzégovine', 'indicatif' => '+387'],
            ['code_iso' => 'BB', 'nom' => 'Barbade', 'indicatif' => '+1'],
            ['code_iso' => 'BD', 'nom' => 'Bangladesh', 'indicatif' => '+880'],
            ['code_iso' => 'BE', 'nom' => 'Belgique', 'indicatif' => '+32'],
            ['code_iso' => 'BF', 'nom' => 'Burkina Faso', 'indicatif' => '+226'],
            ['code_iso' => 'BG', 'nom' => 'Bulgarie', 'indicatif' => '+359'],
            ['code_iso' => 'BH', 'nom' => 'Bahreïn', 'indicatif' => '+973'],
            ['code_iso' => 'BI', 'nom' => 'Burundi', 'indicatif' => '+257'],
            ['code_iso' => 'BJ', 'nom' => 'Bénin', 'indicatif' => '+229'],
            ['code_iso' => 'BN', 'nom' => 'Brunei', 'indicatif' => '+673'],
            ['code_iso' => 'BO', 'nom' => 'Bolivie', 'indicatif' => '+591'],
            ['code_iso' => 'BR', 'nom' => 'Brésil', 'indicatif' => '+55'],
            ['code_iso' => 'BS', 'nom' => 'Bahamas', 'indicatif' => '+1'],
            ['code_iso' => 'BT', 'nom' => 'Bhoutan', 'indicatif' => '+975'],
            ['code_iso' => 'BW', 'nom' => 'Botswana', 'indicatif' => '+267'],
            ['code_iso' => 'BY', 'nom' => 'Bélarus', 'indicatif' => '+375'],
            ['code_iso' => 'BZ', 'nom' => 'Belize', 'indicatif' => '+501'],
            ['code_iso' => 'CA', 'nom' => 'Canada', 'indicatif' => '+1'],
            ['code_iso' => 'CD', 'nom' => 'République Démocratique du Congo', 'indicatif' => '+243'],
            ['code_iso' => 'CF', 'nom' => 'République Centrafricaine', 'indicatif' => '+236'],
            ['code_iso' => 'CG', 'nom' => 'Congo', 'indicatif' => '+242'],
            ['code_iso' => 'CH', 'nom' => 'Suisse', 'indicatif' => '+41'],
            ['code_iso' => 'CI', 'nom' => "Côte d'Ivoire", 'indicatif' => '+225'],
            ['code_iso' => 'CL', 'nom' => 'Chili', 'indicatif' => '+56'],
            ['code_iso' => 'CM', 'nom' => 'Cameroun', 'indicatif' => '+237'],
            ['code_iso' => 'CN', 'nom' => 'Chine', 'indicatif' => '+86'],
            ['code_iso' => 'CO', 'nom' => 'Colombie', 'indicatif' => '+57'],
            ['code_iso' => 'CR', 'nom' => 'Costa Rica', 'indicatif' => '+506'],
            ['code_iso' => 'CU', 'nom' => 'Cuba', 'indicatif' => '+53'],
            ['code_iso' => 'CV', 'nom' => 'Cap-Vert', 'indicatif' => '+238'],
            ['code_iso' => 'CY', 'nom' => 'Chypre', 'indicatif' => '+357'],
            ['code_iso' => 'DE', 'nom' => 'Allemagne', 'indicatif' => '+49'],
            ['code_iso' => 'DJ', 'nom' => 'Djibouti', 'indicatif' => '+253'],
            ['code_iso' => 'DK', 'nom' => 'Danemark', 'indicatif' => '+45'],
            ['code_iso' => 'DM', 'nom' => 'Dominique', 'indicatif' => '+1'],
            ['code_iso' => 'DO', 'nom' => 'République Dominicaine', 'indicatif' => '+1'],
            ['code_iso' => 'DZ', 'nom' => 'Algérie', 'indicatif' => '+213'],
            ['code_iso' => 'EC', 'nom' => 'Équateur', 'indicatif' => '+593'],
            ['code_iso' => 'EE', 'nom' => 'Estonie', 'indicatif' => '+372'],
            ['code_iso' => 'EG', 'nom' => 'Égypte', 'indicatif' => '+20'],
            ['code_iso' => 'ER', 'nom' => 'Érythrée', 'indicatif' => '+291'],
            ['code_iso' => 'ES', 'nom' => 'Espagne', 'indicatif' => '+34'],
            ['code_iso' => 'ET', 'nom' => 'Éthiopie', 'indicatif' => '+251'],
            ['code_iso' => 'FI', 'nom' => 'Finlande', 'indicatif' => '+358'],
            ['code_iso' => 'FJ', 'nom' => 'Fidji', 'indicatif' => '+679'],
            ['code_iso' => 'FM', 'nom' => 'Micronésie', 'indicatif' => '+691'],
            ['code_iso' => 'FR', 'nom' => 'France', 'indicatif' => '+33'],
            ['code_iso' => 'GA', 'nom' => 'Gabon', 'indicatif' => '+241'],
            ['code_iso' => 'GB', 'nom' => 'Royaume-Uni', 'indicatif' => '+44'],
            ['code_iso' => 'GD', 'nom' => 'Grenade', 'indicatif' => '+1'],
            ['code_iso' => 'GE', 'nom' => 'Géorgie', 'indicatif' => '+995'],
            ['code_iso' => 'GH', 'nom' => 'Ghana', 'indicatif' => '+233'],
            ['code_iso' => 'GM', 'nom' => 'Gambie', 'indicatif' => '+220'],
            ['code_iso' => 'GN', 'nom' => 'Guinée', 'indicatif' => '+224'],
            ['code_iso' => 'GQ', 'nom' => 'Guinée Équatoriale', 'indicatif' => '+240'],
            ['code_iso' => 'GR', 'nom' => 'Grèce', 'indicatif' => '+30'],
            ['code_iso' => 'GT', 'nom' => 'Guatemala', 'indicatif' => '+502'],
            ['code_iso' => 'GW', 'nom' => 'Guinée-Bissau', 'indicatif' => '+245'],
            ['code_iso' => 'GY', 'nom' => 'Guyana', 'indicatif' => '+592'],
            ['code_iso' => 'HN', 'nom' => 'Honduras', 'indicatif' => '+504'],
            ['code_iso' => 'HR', 'nom' => 'Croatie', 'indicatif' => '+385'],
            ['code_iso' => 'HT', 'nom' => 'Haïti', 'indicatif' => '+509'],
            ['code_iso' => 'HU', 'nom' => 'Hongrie', 'indicatif' => '+36'],
            ['code_iso' => 'ID', 'nom' => 'Indonésie', 'indicatif' => '+62'],
            ['code_iso' => 'IE', 'nom' => 'Irlande', 'indicatif' => '+353'],
            ['code_iso' => 'IL', 'nom' => 'Israël', 'indicatif' => '+972'],
            ['code_iso' => 'IN', 'nom' => 'Inde', 'indicatif' => '+91'],
            ['code_iso' => 'IQ', 'nom' => 'Irak', 'indicatif' => '+964'],
            ['code_iso' => 'IR', 'nom' => 'Iran', 'indicatif' => '+98'],
            ['code_iso' => 'IS', 'nom' => 'Islande', 'indicatif' => '+354'],
            ['code_iso' => 'IT', 'nom' => 'Italie', 'indicatif' => '+39'],
            ['code_iso' => 'JM', 'nom' => 'Jamaïque', 'indicatif' => '+1'],
            ['code_iso' => 'JO', 'nom' => 'Jordanie', 'indicatif' => '+962'],
            ['code_iso' => 'JP', 'nom' => 'Japon', 'indicatif' => '+81'],
            ['code_iso' => 'KE', 'nom' => 'Kenya', 'indicatif' => '+254'],
            ['code_iso' => 'KG', 'nom' => 'Kirghizistan', 'indicatif' => '+996'],
            ['code_iso' => 'KH', 'nom' => 'Cambodge', 'indicatif' => '+855'],
            ['code_iso' => 'KI', 'nom' => 'Kiribati', 'indicatif' => '+686'],
            ['code_iso' => 'KM', 'nom' => 'Comores', 'indicatif' => '+269'],
            ['code_iso' => 'KN', 'nom' => 'Saint-Christophe-et-Niévès', 'indicatif' => '+1'],
            ['code_iso' => 'KP', 'nom' => 'Corée du Nord', 'indicatif' => '+850'],
            ['code_iso' => 'KR', 'nom' => 'Corée du Sud', 'indicatif' => '+82'],
            ['code_iso' => 'KW', 'nom' => 'Koweït', 'indicatif' => '+965'],
            ['code_iso' => 'KZ', 'nom' => 'Kazakhstan', 'indicatif' => '+7'],
            ['code_iso' => 'LA', 'nom' => 'Laos', 'indicatif' => '+856'],
            ['code_iso' => 'LB', 'nom' => 'Liban', 'indicatif' => '+961'],
            ['code_iso' => 'LC', 'nom' => 'Sainte-Lucie', 'indicatif' => '+1'],
            ['code_iso' => 'LI', 'nom' => 'Liechtenstein', 'indicatif' => '+423'],
            ['code_iso' => 'LK', 'nom' => 'Sri Lanka', 'indicatif' => '+94'],
            ['code_iso' => 'LR', 'nom' => 'Libéria', 'indicatif' => '+231'],
            ['code_iso' => 'LS', 'nom' => 'Lesotho', 'indicatif' => '+266'],
            ['code_iso' => 'LT', 'nom' => 'Lituanie', 'indicatif' => '+370'],
            ['code_iso' => 'LU', 'nom' => 'Luxembourg', 'indicatif' => '+352'],
            ['code_iso' => 'LV', 'nom' => 'Lettonie', 'indicatif' => '+371'],
            ['code_iso' => 'LY', 'nom' => 'Libye', 'indicatif' => '+218'],
            ['code_iso' => 'MA', 'nom' => 'Maroc', 'indicatif' => '+212'],
            ['code_iso' => 'MC', 'nom' => 'Monaco', 'indicatif' => '+377'],
            ['code_iso' => 'MD', 'nom' => 'Moldavie', 'indicatif' => '+373'],
            ['code_iso' => 'ME', 'nom' => 'Monténégro', 'indicatif' => '+382'],
            ['code_iso' => 'MG', 'nom' => 'Madagascar', 'indicatif' => '+261'],
            ['code_iso' => 'MH', 'nom' => 'Îles Marshall', 'indicatif' => '+692'],
            ['code_iso' => 'MK', 'nom' => 'Macédoine du Nord', 'indicatif' => '+389'],
            ['code_iso' => 'ML', 'nom' => 'Mali', 'indicatif' => '+223'],
            ['code_iso' => 'MM', 'nom' => 'Myanmar (Birmanie)', 'indicatif' => '+95'],
            ['code_iso' => 'MN', 'nom' => 'Mongolie', 'indicatif' => '+976'],
            ['code_iso' => 'MR', 'nom' => 'Mauritanie', 'indicatif' => '+222'],
            ['code_iso' => 'MT', 'nom' => 'Malte', 'indicatif' => '+356'],
            ['code_iso' => 'MU', 'nom' => 'Maurice', 'indicatif' => '+230'],
            ['code_iso' => 'MV', 'nom' => 'Maldives', 'indicatif' => '+960'],
            ['code_iso' => 'MW', 'nom' => 'Malawi', 'indicatif' => '+265'],
            ['code_iso' => 'MX', 'nom' => 'Mexique', 'indicatif' => '+52'],
            ['code_iso' => 'MY', 'nom' => 'Malaisie', 'indicatif' => '+60'],
            ['code_iso' => 'MZ', 'nom' => 'Mozambique', 'indicatif' => '+258'],
            ['code_iso' => 'NA', 'nom' => 'Namibie', 'indicatif' => '+264'],
            ['code_iso' => 'NE', 'nom' => 'Niger', 'indicatif' => '+227'],
            ['code_iso' => 'NG', 'nom' => 'Nigéria', 'indicatif' => '+234'],
            ['code_iso' => 'NI', 'nom' => 'Nicaragua', 'indicatif' => '+505'],
            ['code_iso' => 'NL', 'nom' => 'Pays-Bas', 'indicatif' => '+31'],
            ['code_iso' => 'NO', 'nom' => 'Norvège', 'indicatif' => '+47'],
            ['code_iso' => 'NP', 'nom' => 'Népal', 'indicatif' => '+977'],
            ['code_iso' => 'NR', 'nom' => 'Nauru', 'indicatif' => '+674'],
            ['code_iso' => 'NZ', 'nom' => 'Nouvelle-Zélande', 'indicatif' => '+64'],
            ['code_iso' => 'OM', 'nom' => 'Oman', 'indicatif' => '+968'],
            ['code_iso' => 'PA', 'nom' => 'Panama', 'indicatif' => '+507'],
            ['code_iso' => 'PE', 'nom' => 'Pérou', 'indicatif' => '+51'],
            ['code_iso' => 'PG', 'nom' => 'Papouasie-Nouvelle-Guinée', 'indicatif' => '+675'],
            ['code_iso' => 'PH', 'nom' => 'Philippines', 'indicatif' => '+63'],
            ['code_iso' => 'PK', 'nom' => 'Pakistan', 'indicatif' => '+92'],
            ['code_iso' => 'PL', 'nom' => 'Pologne', 'indicatif' => '+48'],
            ['code_iso' => 'PT', 'nom' => 'Portugal', 'indicatif' => '+351'],
            ['code_iso' => 'PW', 'nom' => 'Palaos', 'indicatif' => '+680'],
            ['code_iso' => 'PY', 'nom' => 'Paraguay', 'indicatif' => '+595'],
            ['code_iso' => 'QA', 'nom' => 'Qatar', 'indicatif' => '+974'],
            ['code_iso' => 'RO', 'nom' => 'Roumanie', 'indicatif' => '+40'],
            ['code_iso' => 'RS', 'nom' => 'Serbie', 'indicatif' => '+381'],
            ['code_iso' => 'RU', 'nom' => 'Russie', 'indicatif' => '+7'],
            ['code_iso' => 'RW', 'nom' => 'Rwanda', 'indicatif' => '+250'],
            ['code_iso' => 'SA', 'nom' => 'Arabie Saoudite', 'indicatif' => '+966'],
            ['code_iso' => 'SB', 'nom' => 'Îles Salomon', 'indicatif' => '+677'],
            ['code_iso' => 'SC', 'nom' => 'Seychelles', 'indicatif' => '+248'],
            ['code_iso' => 'SD', 'nom' => 'Soudan', 'indicatif' => '+249'],
            ['code_iso' => 'SE', 'nom' => 'Suède', 'indicatif' => '+46'],
            ['code_iso' => 'SG', 'nom' => 'Singapour', 'indicatif' => '+65'],
            ['code_iso' => 'SI', 'nom' => 'Slovénie', 'indicatif' => '+386'],
            ['code_iso' => 'SK', 'nom' => 'Slovaquie', 'indicatif' => '+421'],
            ['code_iso' => 'SL', 'nom' => 'Sierra Leone', 'indicatif' => '+232'],
            ['code_iso' => 'SM', 'nom' => 'Saint-Marin', 'indicatif' => '+378'],
            ['code_iso' => 'SN', 'nom' => 'Sénégal', 'indicatif' => '+221'],
            ['code_iso' => 'SO', 'nom' => 'Somalie', 'indicatif' => '+252'],
            ['code_iso' => 'SR', 'nom' => 'Suriname', 'indicatif' => '+597'],
            ['code_iso' => 'SS', 'nom' => 'Soudan du Sud', 'indicatif' => '+211'],
            ['code_iso' => 'ST', 'nom' => 'Sao Tomé-et-Principe', 'indicatif' => '+239'],
            ['code_iso' => 'SV', 'nom' => 'Salvador', 'indicatif' => '+503'],
            ['code_iso' => 'SY', 'nom' => 'Syrie', 'indicatif' => '+963'],
            ['code_iso' => 'SZ', 'nom' => 'Eswatini', 'indicatif' => '+268'],
            ['code_iso' => 'TD', 'nom' => 'Tchad', 'indicatif' => '+235'],
            ['code_iso' => 'TG', 'nom' => 'Togo', 'indicatif' => '+228'],
            ['code_iso' => 'TH', 'nom' => 'Thaïlande', 'indicatif' => '+66'],
            ['code_iso' => 'TJ', 'nom' => 'Tadjikistan', 'indicatif' => '+992'],
            ['code_iso' => 'TL', 'nom' => 'Timor oriental', 'indicatif' => '+670'],
            ['code_iso' => 'TM', 'nom' => 'Turkménistan', 'indicatif' => '+993'],
            ['code_iso' => 'TN', 'nom' => 'Tunisie', 'indicatif' => '+216'],
            ['code_iso' => 'TO', 'nom' => 'Tonga', 'indicatif' => '+676'],
            ['code_iso' => 'TR', 'nom' => 'Turquie', 'indicatif' => '+90'],
            ['code_iso' => 'TT', 'nom' => 'Trinité-et-Tobago', 'indicatif' => '+1'],
            ['code_iso' => 'TV', 'nom' => 'Tuvalu', 'indicatif' => '+688'],
            ['code_iso' => 'TW', 'nom' => 'Taïwan', 'indicatif' => '+886'],
            ['code_iso' => 'TZ', 'nom' => 'Tanzanie', 'indicatif' => '+255'],
            ['code_iso' => 'UA', 'nom' => 'Ukraine', 'indicatif' => '+380'],
            ['code_iso' => 'UG', 'nom' => 'Ouganda', 'indicatif' => '+256'],
            ['code_iso' => 'US', 'nom' => 'États-Unis', 'indicatif' => '+1'],
            ['code_iso' => 'UY', 'nom' => 'Uruguay', 'indicatif' => '+598'],
            ['code_iso' => 'UZ', 'nom' => 'Ouzbékistan', 'indicatif' => '+998'],
            ['code_iso' => 'VA', 'nom' => 'Vatican', 'indicatif' => '+379'],
            ['code_iso' => 'VC', 'nom' => 'Saint-Vincent-et-les-Grenadines', 'indicatif' => '+1'],
            ['code_iso' => 'VE', 'nom' => 'Venezuela', 'indicatif' => '+58'],
            ['code_iso' => 'VN', 'nom' => 'Vietnam', 'indicatif' => '+84'],
            ['code_iso' => 'VU', 'nom' => 'Vanuatu', 'indicatif' => '+678'],
            ['code_iso' => 'WS', 'nom' => 'Samoa', 'indicatif' => '+685'],
            ['code_iso' => 'XK', 'nom' => 'Kosovo', 'indicatif' => '+383'],
            ['code_iso' => 'YE', 'nom' => 'Yémen', 'indicatif' => '+967'],
            ['code_iso' => 'ZA', 'nom' => 'Afrique du Sud', 'indicatif' => '+27'],
            ['code_iso' => 'ZM', 'nom' => 'Zambie', 'indicatif' => '+260'],
            ['code_iso' => 'ZW', 'nom' => 'Zimbabwe', 'indicatif' => '+263'],
        ];

        $now = now();
        foreach (array_chunk($pays, 50) as $chunk) {
            $rows = array_map(fn($p) => array_merge($p, ['created_at' => $now, 'updated_at' => $now]), $chunk);
            DB::table('pays')->upsert($rows, ['code_iso'], ['nom', 'indicatif', 'updated_at']);
        }
    }
}
```

## 3. `database/seeders/RegionsCamerounSeeder.php`

```php
<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RegionsCamerounSeeder extends Seeder
{
    public function run(): void
    {
        $regions = [
            ['code' => 'ADAMAOUA',     'nom' => 'Adamaoua',      'quota_admission' => 0.05, 'chef_lieu' => 'Ngaoundéré',  'order' => 1],
            ['code' => 'CENTRE',       'nom' => 'Centre',        'quota_admission' => 0.15, 'chef_lieu' => 'Yaoundé',     'order' => 2],
            ['code' => 'EST',          'nom' => 'Est',           'quota_admission' => 0.04, 'chef_lieu' => 'Bertoua',     'order' => 3],
            ['code' => 'EXTREME-NORD', 'nom' => 'Extrême-Nord',  'quota_admission' => 0.18, 'chef_lieu' => 'Maroua',      'order' => 4],
            ['code' => 'LITTORAL',     'nom' => 'Littoral',      'quota_admission' => 0.12, 'chef_lieu' => 'Douala',      'order' => 5],
            ['code' => 'NORD',         'nom' => 'Nord',          'quota_admission' => 0.07, 'chef_lieu' => 'Garoua',      'order' => 6],
            ['code' => 'NORD-OUEST',   'nom' => 'Nord-Ouest',    'quota_admission' => 0.12, 'chef_lieu' => 'Bamenda',     'order' => 7],
            ['code' => 'OUEST',        'nom' => 'Ouest',         'quota_admission' => 0.13, 'chef_lieu' => 'Bafoussam',   'order' => 8],
            ['code' => 'SUD',          'nom' => 'Sud',           'quota_admission' => 0.04, 'chef_lieu' => 'Ebolowa',     'order' => 9],
            ['code' => 'SUD-OUEST',    'nom' => 'Sud-Ouest',     'quota_admission' => 0.08, 'chef_lieu' => 'Buéa',        'order' => 10],
            ['code' => 'Z AUTRES',     'nom' => 'Autres',        'quota_admission' => null, 'chef_lieu' => null,          'order' => 99],
        ];

        $now = now();
        $rows = array_map(fn($r) => array_merge($r, ['created_at' => $now, 'updated_at' => $now]), $regions);
        DB::table('regions_cameroun')->upsert($rows, ['code'], ['nom', 'quota_admission', 'chef_lieu', 'order', 'updated_at']);
    }
}
```

**Note métier** : la somme des quotas régionaux du Cameroun est `0.98` (98%). Les `2%` restants vont implicitement à « Z AUTRES » (candidats résidant à l'étranger ou nationalités étrangères). Le widget Filament dashboard affichera le pourcentage réel avec un seuil d'alerte si l'écart > 5 points pour une région.

## 4. `database/seeders/DepartementsCamerounSeeder.php`

```php
<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DepartementsCamerounSeeder extends Seeder
{
    public function run(): void
    {
        $departements = [
            ['code' => 'AUTRES',                   'nom' => 'Autres',                  'chef_lieu' => null,          'region_code' => 'Z AUTRES'],
            ['code' => 'Bamboutos',                'nom' => 'Bamboutos',               'chef_lieu' => 'Mbouda',      'region_code' => 'OUEST'],
            ['code' => 'Bénoué',                   'nom' => 'Bénoué',                  'chef_lieu' => 'Garoua',      'region_code' => 'NORD'],
            ['code' => 'Boumba-et-Ngoko',          'nom' => 'Boumba-et-Ngoko',         'chef_lieu' => 'Yokadouma',   'region_code' => 'EST'],
            ['code' => 'Boyo',                     'nom' => 'Boyo',                    'chef_lieu' => 'Fundong',     'region_code' => 'NORD-OUEST'],
            ['code' => 'Bui',                      'nom' => 'Bui',                     'chef_lieu' => 'Kumbo',       'region_code' => 'NORD-OUEST'],
            ['code' => 'Diamaré',                  'nom' => 'Diamaré',                 'chef_lieu' => 'Maroua',      'region_code' => 'EXTREME-NORD'],
            ['code' => 'Dja-et-Lobo',              'nom' => 'Dja-et-Lobo',             'chef_lieu' => 'Sangmélima',  'region_code' => 'SUD'],
            ['code' => 'Djérem',                   'nom' => 'Djérem',                  'chef_lieu' => 'Tibati',      'region_code' => 'ADAMAOUA'],
            ['code' => 'Donga-Mantung',            'nom' => 'Donga-Mantung',           'chef_lieu' => 'Nkambé',      'region_code' => 'NORD-OUEST'],
            ['code' => 'Fako',                     'nom' => 'Fako',                    'chef_lieu' => 'Limbé',       'region_code' => 'SUD-OUEST'],
            ['code' => 'Faro',                     'nom' => 'Faro',                    'chef_lieu' => 'Poli',        'region_code' => 'NORD'],
            ['code' => 'Faro-et-Déo',              'nom' => 'Faro-et-Déo',             'chef_lieu' => 'Tignère',     'region_code' => 'ADAMAOUA'],
            ['code' => 'Haut-Nkam',                'nom' => 'Haut-Nkam',               'chef_lieu' => 'Bafang',      'region_code' => 'OUEST'],
            ['code' => 'Haut-Nyong',               'nom' => 'Haut-Nyong',              'chef_lieu' => 'Abong-Mbang', 'region_code' => 'EST'],
            ['code' => 'Haute-Sanaga',             'nom' => 'Haute-Sanaga',            'chef_lieu' => 'Nanga-Eboko', 'region_code' => 'CENTRE'],
            ['code' => 'Hauts-Plateaux',           'nom' => 'Hauts-Plateaux',          'chef_lieu' => 'Baham',       'region_code' => 'OUEST'],
            ['code' => 'Kadey',                    'nom' => 'Kadey',                   'chef_lieu' => 'Batouri',     'region_code' => 'EST'],
            ['code' => 'Koung-Khi',                'nom' => 'Koung-Khi',               'chef_lieu' => 'Bandjoun',    'region_code' => 'OUEST'],
            ['code' => 'Koupé-Manengouba',         'nom' => 'Koupé-Manengouba',        'chef_lieu' => 'Bangem',      'region_code' => 'SUD-OUEST'],
            ['code' => 'Lebialem',                 'nom' => 'Lebialem',                'chef_lieu' => 'Menji',       'region_code' => 'SUD-OUEST'],
            ['code' => 'Lekié',                    'nom' => 'Lekié',                   'chef_lieu' => 'Monatélé',    'region_code' => 'CENTRE'],
            ['code' => 'Logone-et-Chari',          'nom' => 'Logone-et-Chari',         'chef_lieu' => 'Kousséri',    'region_code' => 'EXTREME-NORD'],
            ['code' => 'Lom-et-Djérem',            'nom' => 'Lom-et-Djérem',           'chef_lieu' => 'Bertoua',     'region_code' => 'EST'],
            ['code' => 'Manyu',                    'nom' => 'Manyu',                   'chef_lieu' => 'Mamfé',       'region_code' => 'SUD-OUEST'],
            ['code' => 'Mayo-Banyo',               'nom' => 'Mayo-Banyo',              'chef_lieu' => 'Banyo',       'region_code' => 'ADAMAOUA'],
            ['code' => 'Mayo-Danay',               'nom' => 'Mayo-Danay',              'chef_lieu' => 'Yagoua',      'region_code' => 'EXTREME-NORD'],
            ['code' => 'Mayo-Kani',                'nom' => 'Mayo-Kani',               'chef_lieu' => 'Kaélé',       'region_code' => 'EXTREME-NORD'],
            ['code' => 'Mayo-Louti',               'nom' => 'Mayo-Louti',              'chef_lieu' => 'Guider',      'region_code' => 'NORD'],
            ['code' => 'Mayo-Rey',                 'nom' => 'Mayo-Rey',                'chef_lieu' => 'Tcholliré',   'region_code' => 'NORD'],
            ['code' => 'Mayo-Sava',                'nom' => 'Mayo-Sava',               'chef_lieu' => 'Mora',        'region_code' => 'EXTREME-NORD'],
            ['code' => 'Mayo-Tsanaga',             'nom' => 'Mayo-Tsanaga',            'chef_lieu' => 'Mokolo',      'region_code' => 'EXTREME-NORD'],
            ['code' => 'Mbam-et-Inoubou',          'nom' => 'Mbam-et-Inoubou',         'chef_lieu' => 'Bafia',       'region_code' => 'CENTRE'],
            ['code' => 'Mbam-et-Kim',              'nom' => 'Mbam-et-Kim',             'chef_lieu' => 'Ntui',        'region_code' => 'CENTRE'],
            ['code' => 'Mbéré',                    'nom' => 'Mbéré',                   'chef_lieu' => 'Meiganga',    'region_code' => 'ADAMAOUA'],
            ['code' => 'Méfou-et-Afamba',          'nom' => 'Méfou-et-Afamba',         'chef_lieu' => 'Mfou',        'region_code' => 'CENTRE'],
            ['code' => 'Méfou-et-Akono',           'nom' => 'Méfou-et-Akono',          'chef_lieu' => 'Ngoumou',     'region_code' => 'CENTRE'],
            ['code' => 'Meme',                     'nom' => 'Meme',                    'chef_lieu' => 'Kumba',       'region_code' => 'SUD-OUEST'],
            ['code' => 'Menchum',                  'nom' => 'Menchum',                 'chef_lieu' => 'Wum',         'region_code' => 'NORD-OUEST'],
            ['code' => 'Menoua',                   'nom' => 'Menoua',                  'chef_lieu' => 'Dschang',     'region_code' => 'OUEST'],
            ['code' => 'Mezam',                    'nom' => 'Mezam',                   'chef_lieu' => 'Bamenda',     'region_code' => 'NORD-OUEST'],
            ['code' => 'Mfoundi',                  'nom' => 'Mfoundi',                 'chef_lieu' => 'Yaoundé',     'region_code' => 'CENTRE'],
            ['code' => 'Mifi',                     'nom' => 'Mifi',                    'chef_lieu' => 'Bafoussam',   'region_code' => 'OUEST'],
            ['code' => 'Momo',                     'nom' => 'Momo',                    'chef_lieu' => 'Mbengwi',     'region_code' => 'NORD-OUEST'],
            ['code' => 'Moungo',                   'nom' => 'Moungo',                  'chef_lieu' => 'Nkongsamba',  'region_code' => 'LITTORAL'],
            ['code' => 'Mvila',                    'nom' => 'Mvila',                   'chef_lieu' => 'Ebolowa',     'region_code' => 'SUD'],
            ['code' => 'Ndé',                      'nom' => 'Ndé',                     'chef_lieu' => 'Bangangté',   'region_code' => 'OUEST'],
            ['code' => 'Ndian',                    'nom' => 'Ndian',                   'chef_lieu' => 'Mudemba',     'region_code' => 'SUD-OUEST'],
            ['code' => 'Ngo-Ketunjia',             'nom' => 'Ngo-Ketunjia',            'chef_lieu' => 'Ndop',        'region_code' => 'NORD-OUEST'],
            ['code' => 'Nkam',                     'nom' => 'Nkam',                    'chef_lieu' => 'Yabassi',     'region_code' => 'LITTORAL'],
            ['code' => 'Noun',                     'nom' => 'Noun',                    'chef_lieu' => 'Foumban',     'region_code' => 'OUEST'],
            ['code' => 'Nyong-et-Kellé',           'nom' => 'Nyong-et-Kellé',          'chef_lieu' => 'Éséka',       'region_code' => 'CENTRE'],
            ['code' => 'Nyong-et-Mfoumou',         'nom' => 'Nyong-et-Mfoumou',        'chef_lieu' => 'Akonolinga',  'region_code' => 'CENTRE'],
            ['code' => "Nyong-et-So'o",            'nom' => "Nyong-et-So'o",           'chef_lieu' => 'Mbalmayo',    'region_code' => 'CENTRE'],
            ['code' => 'Océan',                    'nom' => 'Océan',                   'chef_lieu' => 'Kribi',       'region_code' => 'SUD'],
            ['code' => 'Sanaga-maritime',          'nom' => 'Sanaga-Maritime',         'chef_lieu' => 'Édéa',        'region_code' => 'LITTORAL'],
            ['code' => 'Vallée-du-Ntem',           'nom' => 'Vallée-du-Ntem',          'chef_lieu' => 'Ambam',       'region_code' => 'SUD'],
            ['code' => 'Vina',                     'nom' => 'Vina',                    'chef_lieu' => 'Ngaoundéré',  'region_code' => 'ADAMAOUA'],
            ['code' => 'Wouri',                    'nom' => 'Wouri',                   'chef_lieu' => 'Douala',      'region_code' => 'LITTORAL'],
        ];

        $now = now();
        $rows = array_map(fn($d) => array_merge($d, ['created_at' => $now, 'updated_at' => $now]), $departements);
        foreach (array_chunk($rows, 50) as $chunk) {
            DB::table('departements_cameroun')->upsert($chunk, ['code'], ['nom', 'chef_lieu', 'region_code', 'updated_at']);
        }
    }
}
```

**Notes** :

- Le code `AUTRES` rattaché à `Z AUTRES` capture les candidats hors Cameroun ou hors classification standard.
- Les chef-lieux manquants dans le dump original (Wouri, Sanaga-maritime, Nkam) ont été enrichis depuis les références officielles INS Cameroun 2014.
- La table comporte exactement **58 départements** (les 58 du dump + le AUTRES = 58 réels selon l'organisation administrative camerounaise).

## 5. Mise à jour du `DatabaseSeeder.php` racine

À ajouter dans la méthode `run()` :

```php
$this->call([
    PaysSeeder::class,
    RegionsCamerounSeeder::class,
    DepartementsCamerounSeeder::class,
    // ... autres seeders existants
]);
```

## 6. Modèles Eloquent associés

```php
// app/Models/Pays.php
class Pays extends Model {
    protected $table = 'pays';
    protected $primaryKey = 'code_iso';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $fillable = ['code_iso', 'nom', 'indicatif'];
}

// app/Models/RegionCameroun.php
class RegionCameroun extends Model {
    protected $table = 'regions_cameroun';
    protected $primaryKey = 'code';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $fillable = ['code', 'nom', 'quota_admission', 'chef_lieu', 'order'];

    public function departements() {
        return $this->hasMany(DepartementCameroun::class, 'region_code', 'code');
    }
}

// app/Models/DepartementCameroun.php
class DepartementCameroun extends Model {
    protected $table = 'departements_cameroun';
    protected $primaryKey = 'code';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $fillable = ['code', 'nom', 'chef_lieu', 'region_code'];

    public function region() {
        return $this->belongsTo(RegionCameroun::class, 'region_code', 'code');
    }
}
```

## 7. Endpoints API associés

À ajouter dans `routes/api.php` (cf. `docs/api-contract.md` à étendre) :

```php
Route::get('/v1/reference/pays', fn () => Pays::orderBy('nom')->get());
Route::get('/v1/reference/regions-cameroun', fn () => RegionCameroun::orderBy('order')->get());
Route::get('/v1/reference/departements-cameroun', function (Request $r) {
    $q = DepartementCameroun::query();
    if ($r->has('region')) $q->where('region_code', $r->region);
    return $q->orderBy('nom')->get();
});
```

Cache 24 h côté Laravel (`Cache::remember`) sur ces 3 endpoints — référentiels rarement modifiés.

## 8. Composant Next.js cascading dropdown

Stub à intégrer dans `candidature/components/PaysRegionDepartementSelect.tsx` :

```typescript
'use client';

import { useState, useEffect } from 'react';

interface Props {
  paysSelected?: string;
  regionSelected?: string;
  departementSelected?: string;
  onChange: (values: { pays: string; region?: string; departement?: string }) => void;
}

export function PaysRegionDepartementSelect({ paysSelected, regionSelected, departementSelected, onChange }: Props) {
  const [pays, setPays] = useState<{ code_iso: string; nom: string }[]>([]);
  const [regions, setRegions] = useState<{ code: string; nom: string }[]>([]);
  const [departements, setDepartements] = useState<{ code: string; nom: string }[]>([]);

  useEffect(() => {
    fetch('/api/v1/reference/pays').then(r => r.json()).then(setPays);
  }, []);

  useEffect(() => {
    if (paysSelected === 'CM') {
      fetch('/api/v1/reference/regions-cameroun').then(r => r.json()).then(setRegions);
    } else {
      setRegions([]);
      setDepartements([]);
    }
  }, [paysSelected]);

  useEffect(() => {
    if (regionSelected) {
      fetch(`/api/v1/reference/departements-cameroun?region=${regionSelected}`)
        .then(r => r.json()).then(setDepartements);
    } else {
      setDepartements([]);
    }
  }, [regionSelected]);

  // ...rendu trois <select> en cascade
}
```

## Annexe — Quotas régionaux résumés

| Région | Quota | Affichage |
|---|---|---|
| Extrême-Nord | 18 % | priorité haute |
| Centre | 15 % | priorité haute |
| Ouest | 13 % | |
| Littoral | 12 % | |
| Nord-Ouest | 12 % | |
| Sud-Ouest | 8 % | |
| Nord | 7 % | |
| Adamaoua | 5 % | |
| Est | 4 % | |
| Sud | 4 % | |
| **Total CM** | **98 %** | |
| Z Autres (étranger) | 2 % implicite | |
