<?php

declare(strict_types=1);

namespace kintai\Bundles\Installed\ShiftSwap;

use kintai\Core\BundleContract\Bundle;

/**
 * Comme TimeOff et HiringReport, ce bundle n'enregistre pas son propre
 * repository : ShiftSwapRequestRepositoryInterface reste un service Core
 * (RepositoryServiceProvider), car StoreStatsService, HomeController et
 * le tableau de bord d'EmployeeController en dépendent pour des calculs
 * qui doivent continuer de fonctionner même si ce bundle est désactivé.
 * Désactiver "shift-swap" retire uniquement l'UI de gestion des échanges,
 * pas les données elles-mêmes.
 */
final class ShiftSwapBundle extends Bundle
{
    public function getName(): string
    {
        return 'shift-swap';
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function getLabel(): string
    {
        return __('bundle_shift_swap');
    }

    public function getDescription(): string
    {
        return __('bundle_shift_swap_desc');
    }

    public function register(): void
    {
        $this->loadViewsFrom($this->getPath() . '/Views', 'shift-swap');
        $this->loadRoutesFrom($this->getPath() . '/routes.php');
    }
}
