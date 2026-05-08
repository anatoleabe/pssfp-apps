# 2026-05-08 — Sprint S2 — PR D — Filament Module 5 Admissions

> Branche : `feat/m5-pr-d-filament`
> Périmètre : `CandidatureResource` + `CampagneCandidatureResource` + 2 widgets dashboard + actions de transition + emails accepté/refusé queue + 37 tests Filament
> Spec : `docs/specs/module-5-candidatures.md` v1.1 §7

## Surface livrée

Resources Filament :
- **`CandidatureResource`** — coeur de la PR. List avec filtres campagne/statut/région/frais, View, Edit (lecture seule sur dossiers décidés sauf super_admin), pas de Create. Actions individuelles : Accept, Refuse, MarkAsCandidat (avec motif obligatoire), MarkPaid, ViewRecipisse, Withdraw. Bulk : ExportCsv, MarkPaidBulk, AcceptBulk (super_admin), RefuseBulk (super_admin).
- **`CampagneCandidatureResource`** — minimaliste. Création (super_admin), liste, édition. Pas de delete (FK RESTRICT).

Widgets dashboard :
- **`QuotasRegionauxWidget`** — table des 11 régions, quota cible vs réel, badge couleur (vert <3 pts, orange 3-7, rouge >7 pts), polling 60s + bouton refresh manuel. Visible `admission_committee` / `admin` / `super_admin`.
- **`AvancementCampagneWidget`** — 4 stats (total dossiers, décomposition statut, taux frais payés, compte à rebours clôture). Visible à tous les rôles avec accès au panel.

Système email :
- 2 Mailables `CandidatureAcceptedMail` + `CandidatureRefusedMail` (queue, retry 3 fois backoff exponentiel 30s/2min/8min).
- 2 Events `CandidatureAccepted` + `CandidatureRefused` dispatchés depuis les actions Filament.
- Listener `SendCandidatureDecisionEmail` enregistré dans `AppServiceProvider`. Si pas d'email candidat → log warning, pas d'envoi.
- Templates Blade markdown traduisibles via `__()` + `lang/fr/mail.php`.

Sécurité :
- Policies `CandidaturePolicy` + `CampagneCandidaturePolicy`.
- Permissions Spatie hybrides (filament-shield convention `view_any_*`, `view_*`, `update_*` + permissions métier dédiées `candidature.accept`, `candidature.refuse`, `candidature.mark_paid`, `candidature.export_csv`, `candidature.bulk_decision`, etc.).
- Matrice rôle → permissions appliquée dans `RolePermissionSeeder` (idempotent via `findOrCreate`).
- `EditCandidature` : champs systèmes (uuid, numero_dossier, campagne_id, user_id, statut, dates, recipisse_*) sont disabled() dans le form ET filtrés dans `mutateFormDataBeforeSave` pour empêcher toute manipulation par body forgé.

## Décisions de PR (8 arbitrages + 5 ajouts + 4 précisions intégrés)

### Arbitrages validés

- **A1** — `CandidatureResource` ET `CampagneCandidatureResource` (gestion des campagnes admin).
- **B** — Workflow validé : pas de `withdrawn` en transition admin (le candidat retire lui-même via API). Action `Withdraw` admin réservée `super_admin` pour cas exceptionnels (fraude, décès), avec motif obligatoire tracé en activity_log. Rétrogradation candidat→postulant avec motif obligatoire.
- **C1** — Hybride : convention shield CRUD + permissions métier nommées. Seeder idempotent via `findOrCreate`.
- **D** — `canCreate()` retourne `false`. Pas de création admin (un dossier vient toujours de `/v1/applications/me`).
- **E** — Export CSV natif `fputcsv` + BOM UTF-8 pour Excel. Aucune dépendance ajoutée. Activity log inclut le `count` et la liste des `numeros` exportés (P-min-2 RGPD).
- **F1** — Emails accepté/refusé en V1, queue Redis avec retry policy. Les 9 autres templates (frais payés, complément, retrait, ...) viendront en PR séparée notifications.
- **G** — Seuils ajustés : vert <3 pts, orange 3-7 pts, rouge >7 pts. Bouton refresh manuel ajouté en plus du polling 60s.
- **H** — Tests Filament avec `pest-plugin-livewire`.

### Ajouts intégrés

1. **`viewRecipisse` audit log** : chaque téléchargement par un admin écrit `event=recipisse_downloaded_by_admin` avec `type=admin_download` dans activity_log.
2. **`AvancementCampagneWidget` compagnon** : stats en haut du dashboard, accessible à tous les rôles ayant le panel.
3. **Bulk Accept/Refuse** : réservés `super_admin` (permission `candidature.bulk_decision`). Activity log inclut la liste complète des `numeros` traités, pas juste le count.
4. **Filtres persistés en session** : `persistFiltersInSession()` + `persistSearchInSession()` sur le table builder.
5. **Test ownership ajout 5** : un admin ne peut modifier ni `uuid`, ni `numero_dossier`, ni `campagne_id`, ni `user_id`, ni `statut` via le form Filament — `EditCandidature::mutateFormDataBeforeSave` filtre les champs systèmes.

### Précisions intégrées

- **P-min-1** — `EditCandidature::authorizeAccess()` retourne 403 si statut ∈ {accepte, refuse} sauf super_admin.
- **P-min-2** — `exportCsv` log toutes les colonnes critiques (count + numeros) dans activity_log.
- **P-min-3** — Templates email `__('mail.candidature.accepted.*')` + `lang/fr/mail.php` versionné. Préparé pour fr/en/ar.
- **P-min-4** — `ViewCandidature::getRecentActivity()` expose les 10 dernières entries activity_log liées au dossier (rendu dans la vue).

## Pièges traités pendant le code

- **Pest livewire helper retournait null** : la fonction globale `Pest\Livewire\livewire()` ne fonctionnait pas via le seul `Plugin::uses` — le trait `InteractsWithLivewire` doit être ajouté directement dans `Tests\TestCase`. Les tests utilisent désormais `$this->livewire(...)` après `$this->actingAs($user)`.
- **`Testable::actingAs` est void** côté Livewire : retournait null, cassait le chaînage. Refactor : `$this->actingAs($user); $this->livewire(...)->...`.
- **`AvancementCampagneWidget::$pollingInterval`** doit être `static` (parent l'est).
- **`color(fn (string $s))`** dans Filament : le param doit s'appeler `$state` (Filament résout par nom).
- **Tests Filament FK violation** : `seeder([Pays, Regions, Departements, Roles])` requis quand on crée des Candidature factories.
- **route key Candidature = uuid** (PR A) : `livewire(EditCandidature::class, ['record' => $cand->getRouteKey()])` au lieu de `getKey()`.

## Tests Pest — 166 verts, 441 assertions

37 nouveaux tests PR D :

| Suite | Tests |
|---|---|
| `Filament/PanelAccessTest` | 7 — admission_committee/super_admin/admin/editor/librarian autorisés, candidat/teacher/auditor refusés, 2FA requis bloque sans 2FA. |
| `Filament/CandidatureResourceTest` | 13 — list page, canCreate=false, columns, filtres campagne+statut, accept dispatch event, refuse motif requis, motif trop court rejeté, markPaid avec audit, viewRecipisse audit, librarian privé d'accept, hide bulk pour non-super_admin, bulk accept log liste complète. |
| `Filament/EditCandidatureSecurityTest` | 3 — admin ne peut modifier uuid/numero/campagne/user/statut via form, admission_committee bloqué sur dossier accepte, super_admin OK pour correction. |
| `Filament/CampagneResourceTest` | 3 — super_admin crée, editor refusé, count des candidatures. |
| `Filament/QuotasWidgetTest` | 5 — visible cm, hidden librarian, no rows si pas de campagne, calcul danger, calcul success. |
| `Filament/AvancementWidgetTest` | 4 — visible librarian, hidden candidat, render no campagne, render avec stats. |

Plus les 129 tests des PRs A+B+C toujours verts.

## Hors périmètre — viendra en PRs suivantes

| PR | Périmètre |
|---|---|
| **E** | Frontend `/inscription` wizard 4 étapes + cascading PaysRegionDepartementSelect + pages publiques campagne. |
| **F** | Frontend `/login` + `/forgot-pin` + `/dossier` + `/dossier/suivi` + endpoint `/v1/applications/me/withdraw` (retrait par le candidat lui-même). |
| **PR notifications** | 9 templates email restants (frais payés, complément, retrait, etc.) + suite des dispatch events. |

## Variables d'env

Aucune nouvelle variable. Les emails utilisent la config SMTP existante (`MAIL_*` dans `.env`).
