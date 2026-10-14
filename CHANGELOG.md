# Changelog

Tous les changements notables de ce bundle sont documentés dans ce fichier.

Le format suit [Keep a Changelog](https://keepachangelog.com/fr/1.0.0/).
Le schéma de version (X.Y.Z, canaux alpha/beta/main) est décrit dans
`.github/workflows/release.yml`.

## [Unreleased]

### Added

- Socle de tests PHPUnit (composer.json, phpunit.xml) et CI qui l'exécute contre le vrai Core : les 46 tests que le Core faisait tourner sur une copie figée du bundle (fixtures 1.0.0) vivent désormais ici et testent le code réel.

### Fixed

- `/admin/swap-requests/create` répondait 500 pour un gérant limité à ses magasins : `AdminSwapController` n'avait pas le dépôt des membres de magasin que le trait `HasAdminAccess` utilise. Le contrôleur le reçoit maintenant (`StoreUserRepositoryInterface`).
- Sécurité — l'approbation d'un échange appliquait les shifts sans garde : approuver une demande déjà acceptée ré-échangeait les shifts (et annulait l'échange), approuver une demande refusée l'appliquait, refuser un échange appliqué le marquait « refusé » sans restituer les shifts. Seule une demande en attente se décide désormais, l'échange n'est appliqué que si les deux shifts appartiennent encore au demandeur et à la cible, un conflit de jour (deux shifts le même jour) est refusé, et la suppression d'un échange n'écrase plus des shifts réaffectés entre-temps.
- Sécurité — `POST /api/v1/shift-swap-requests` fusionnait le JSON brut du client dans `save()` (upsert dès qu'un `id` est présent) : un `id` écrasait la demande de quelqu'un d'autre en contournant `requireSwap()`, et `status`/`approved_by_id` étaient acceptés tels quels. Seuls `target_user_id`, `requester_shift_id`, `target_shift_id` et `reason` sont lus ; le magasin est obligatoire et `swaps.create` est revérifié dessus, le demandeur est l'appelant, le statut initial est `pending`. `PUT` ne modifie plus que le statut, le motif et le collègue ciblé (une approbation enregistre son auteur et sa date) ; magasin, demandeur et shifts ne changent jamais.

### Changed

- Compatibilité avec la Content-Security-Policy stricte de Kintai (`script-src 'self' 'nonce-…'`, sans `'unsafe-inline'`) : les 5 attributs d'événements inline des vues (`onclick=`/`onchange=`/`onsubmit=`/`oninput=`) sont remplacés par des attributs `data-*` (`data-on-click`, `data-submit-on-change`, `data-confirm`… gérés par `csp-actions.js` du Core). Sans ce changement, les boutons, sélecteurs et confirmations de ces vues ne font plus rien sous la nouvelle politique, sans aucune erreur visible. **Nécessite Kintai Core 0.3.0 ou plus** (`kintai_core.min`), version qui introduit `csp-actions.js` et la CSP à nonce. `tests.yml` échoue désormais si un handler inline, un lien `javascript:` ou un `<script>` sans nonce réapparaît dans `Views/` ou `src/`.

## [1.1.0] - 2026-09-29

### Changed

- Aucun changement fonctionnel — bump de version pour aligner ce bundle sur la ligne 1.1.0 commune à tous les bundles officiels.
- Les huit notifications d'échange de shift (demande, acceptation/refus par le collègue, annulation, application/approbation/refus/suppression par un admin) ne disaient rien des shifts concernés et ne menaient nulle part au clic. Le corps précise désormais les dates des deux shifts et le magasin (le collègue nommé pour les notifications entre employés) — `notif_swap_*_body` (Kintai Core) gagnent les placeholders `:colleague`/`:my_date`/`:target_date`/`:req_date`/`:tgt_date`/`:store` selon le cas. Le clic renvoie vers `/employee/swaps`. **Nécessite** la version de Kintai Core introduisant le paramètre `$link` sur `notify()`/`notifyMany()`.
- Le CSS (`.swap-card*`/`.swap-form-row*`/`.swap-actions`) vivait mélangé au fichier Core `features/employee.css` (page espace employé), pas dans ce dépôt. Il vit maintenant dans `public/css/shift-swap.css`, fourni par ce bundle via `Bundle::loadAssetsFrom()`/`bundle_asset()`. **Nécessite** `kintai_core.min: "0.2.0"`.

## [1.0.0] - 2026-09-19

### Added

- Extraction initiale depuis Kintai (`src/Bundles/ShiftSwap`).
