# Changelog

Tous les changements notables de ce bundle sont documentés dans ce fichier.

Le format suit [Keep a Changelog](https://keepachangelog.com/fr/1.0.0/).
Le schéma de version (X.Y.Z, canaux alpha/beta/main) est décrit dans
`.github/workflows/release.yml`.

## [Unreleased]

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
