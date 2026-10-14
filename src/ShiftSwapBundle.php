<?php

declare(strict_types=1);

namespace kintai\Bundles\Installed\ShiftSwap;

use kintai\Bundles\Installed\ShiftSwap\Providers\PendingSwapsProvider;
use kintai\Contract\Bundle;
use kintai\Contract\Container;
use kintai\Contract\Extension\Action;
use kintai\Contract\Extension\NavItem;
use kintai\Contract\Extension\StoreFeature;

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
        return '1.2.0';
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
        $this->loadAssetsFrom('public');
    }

    /**
     * Navigation (contrat 1.1) : entrée(s) de la barre du haut et de la barre mobile.
     */
    public function navigation(): array
    {
        return [
            new NavItem('swaps', NavItem::REQUESTS, 'swaps', 'admin.swap_requests', 'employee.swaps',
                icon: 'arrows', activePaths: ['/admin/swap-requests', '/employee/swaps'],
                storeFeature: 'swaps', order: 20, mobile: true),
        ];
    }

    /**
     * Catégories de permissions (contrat 1.2), proposées sur la page des rôles.
     */
    public function permissions(): array
    {
        return ['swaps' => ['view', 'create', 'update', 'approve', 'delete']];
    }

    /**
     * Fonctionnalité activable par magasin (contrat 1.3), sur la fiche magasin.
     */
    public function storeFeatures(): array
    {
        return [new StoreFeature('swaps', 'feature_swaps', 'feature_swaps_desc', '🔄', 40)];
    }

    /**
     * Liens dans les pages du Core (contrat 1.6).
     */
    public function actions(): array
    {
        return [
            new Action('request_swap', Action::SHIFTS_EMPLOYEE, 'request_swap', static fn(): string => route_url('employee.swaps.create'), '⇄', Action::PRIMARY, order: 10),
        ];
    }

    /**
     * Demandes en attente (contrat 1.9) : page « Demandes » et compteur du tableau de bord du Core.
     */
    public function pendingRequestsProviders(): array
    {
        return [new PendingSwapsProvider(Container::getInstance())];
    }
}
