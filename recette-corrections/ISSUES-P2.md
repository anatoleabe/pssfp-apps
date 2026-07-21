# Backlog P2 — audit du portail de candidature

Ces fiches sont prêtes à être copiées dans l'outil de suivi de l'équipe. Aucun connecteur de tickets n'était configuré dans l'environnement local de recette.

## P2-01 — Mini-test public d'éligibilité

Ajouter avant l'inscription un questionnaire sans compte : diplôme Bac+3 minimum, expérience, spécialité et mode. Le résultat doit rester indicatif et afficher « sous réserve de la décision du comité ».

## P2-02 — Enrichir le fond du dossier

Permettre plusieurs diplômes, structurer l'expérience professionnelle (poste, structure, ancienneté et total d'années en finances publiques) et ajouter deux réponses de motivation de 500 à 800 caractères.

## P2-03 — Vérifier le téléphone par OTP à la création

Réutiliser le canal SMS du reset PIN, avec expiration, limites de tentatives et protection anti-énumération. Aucun compte ne doit être activé avant validation du numéro.

## P2-04 — Compresser et recadrer la photo côté client

Redimensionner les images de smartphone, proposer un recadrage carré, corriger l'orientation EXIF et produire un fichier inférieur à 2 Mo sans dégradation visible.

## P2-05 — Sauvegarder le brouillon côté serveur

Créer tôt un brouillon lié à un numéro vérifié et permettre la reprise multi-appareils. Conserver l'auto-save local comme filet de sécurité et résoudre les conflits de versions.

## P2-06 — Simplifier l'état civil

Retirer « Mlle », réduire la redondance civilité/genre et conserver le libellé « Nom d'usage (si différent) ». Prévoir une migration de données non destructive.

## P2-07 — Compléter la conformité accessibilité

Garantir un contraste de 4,5:1 pour les petits textes, des styles `:focus-visible` partout et un parcours clavier complet du wizard. Ajouter des tests automatisés et une vérification lecteur d'écran manuelle.

## P2-08 — Checklist détaillée des pièces

Afficher les six types de pièces avec état déposé/manquant, nom de fichier et taille en Ko, sans rendre les pièces recommandées artificiellement bloquantes.

## P2-09 — Isoler les signatures MinIO

Créer un compte de service dédié aux candidatures avec le minimum de droits requis, renouveler les identifiants et vérifier que les URLs signées ne révèlent plus `pssfp_minio_admin`.

