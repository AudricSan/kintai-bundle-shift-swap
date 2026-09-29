# Changelog

Tous les changements notables de ce bundle sont documentés dans ce fichier.

Le format suit [Keep a Changelog](https://keepachangelog.com/fr/1.0.0/).
Le schéma de version (X.Y.Z, canaux alpha/beta/main) est décrit dans
`.github/workflows/release.yml`.

## [Unreleased]

## [1.1.0] - 2026-09-29

### Changed

- Aucun changement fonctionnel — bump de version pour aligner ce bundle sur la ligne 1.1.0 commune à tous les bundles officiels.
- Les huit notifications d'échange de shift (demande, acceptation/refus par le collègue, annulation, application/approbation/refus/suppression par un admin) ne disaient rien des shifts concernés et ne menaient nulle part au clic. Le corps précise désormais les dates des deux shifts et le magasin (le collègue nommé pour les notifications entre employés) — `notif_swap_*_body` (Kintai Core) gagnent les placeholders `:colleague`/`:my_date`/`:target_date`/`:req_date`/`:tgt_date`/`:store` selon le cas. Le clic renvoie vers `/employee/swaps`. **Nécessite** la version de Kintai Core introduisant le paramètre `$link` sur `notify()`/`notifyMany()`.

## [1.0.0] - 2026-09-19

### Added

- Extraction initiale depuis Kintai (`src/Bundles/ShiftSwap`).
