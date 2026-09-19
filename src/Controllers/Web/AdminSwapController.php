<?php

declare(strict_types=1);

namespace kintai\Bundles\Installed\ShiftSwap\Controllers\Web;

use kintai\Core\Exceptions\ForbiddenException;
use kintai\Core\Exceptions\NotFoundException;
use kintai\Core\Repositories\ShiftRepositoryInterface;
use kintai\Core\Repositories\ShiftSwapRequestRepositoryInterface;
use kintai\Core\Repositories\StoreRepositoryInterface;
use kintai\Core\Repositories\UserRepositoryInterface;
use kintai\Core\Request;
use kintai\Core\Response;
use kintai\Core\Services\AuditLogger;
use kintai\Core\Services\NotificationService;
use kintai\UI\Controller\Web\HasAdminAccess;
use kintai\UI\ViewRenderer;

final class AdminSwapController
{
    use HasAdminAccess;
    public function __construct(
        private readonly ViewRenderer $view,
        private readonly ShiftSwapRequestRepositoryInterface $swapRequests,
        private readonly ShiftRepositoryInterface $shifts,
        private readonly AuditLogger $auditLogger,
        private readonly NotificationService $notifs,
        private readonly StoreRepositoryInterface $stores,
        private readonly UserRepositoryInterface $users,
    ) {}

    /**
     * GET /admin/swap-requests/create — formulaire pour appliquer directement
     * un échange de shifts entre deux employés (sans workflow d'acceptation).
     */
    public function createSwap(Request $request): Response
    {
        $managedIds = $this->managedIds($request);
        $userIds    = $managedIds !== null ? $this->memberUserIds($managedIds) : null;
        $employees  = $userIds !== null
            ? array_values(array_filter($this->users->findAll(), fn($u) => in_array((int) $u['id'], $userIds, true)))
            : $this->users->findAll();

        usort($employees, fn($a, $b) => strcmp(
            strtolower(trim(($a['last_name'] ?? '') . ' ' . ($a['first_name'] ?? ''))),
            strtolower(trim(($b['last_name'] ?? '') . ' ' . ($b['first_name'] ?? '')))
        ));

        $today = date('Y-m-d');
        $requesterId = (int) ($request->query('requester_id') ?? 0);
        $targetId    = (int) ($request->query('target_id') ?? 0);

        $futureShiftsFor = function (int $uid) use ($today) {
            $list = array_values(array_filter(
                $this->shifts->findByUser($uid),
                fn($s) => ($s['shift_date'] ?? '') >= $today
            ));
            usort($list, fn($a, $b) => strcmp($a['shift_date'] ?? '', $b['shift_date'] ?? ''));
            return $list;
        };

        $requesterShifts = $requesterId > 0 ? $futureShiftsFor($requesterId) : [];
        $targetShifts    = $targetId > 0 ? $futureShiftsFor($targetId) : [];

        // Exclure les shifts dont l'échange créerait un conflit : l'autre employé
        // a déjà un shift ce jour-là (il se retrouverait avec deux shifts le même jour).
        if ($requesterId > 0 && $targetId > 0) {
            $requesterDates = array_column($requesterShifts, 'shift_date');
            $targetDates    = array_column($targetShifts, 'shift_date');
            $requesterShifts = array_values(array_filter(
                $requesterShifts,
                fn($s) => !in_array($s['shift_date'] ?? '', $targetDates, true)
            ));
            $targetShifts = array_values(array_filter(
                $targetShifts,
                fn($s) => !in_array($s['shift_date'] ?? '', $requesterDates, true)
            ));
        }

        return Response::html($this->view->render('shift-swap::swap-form', [
            'title'            => __('create_swap'),
            'employees'        => $employees,
            'requester_id'     => $requesterId,
            'target_id'        => $targetId,
            'requester_shifts' => $requesterShifts,
            'target_shifts'    => $targetShifts,
            'stores_map'       => $this->buildStoresMap($managedIds),
        ], 'layout.app'));
    }

    /**
     * POST /admin/swap-requests/create — échange immédiatement les shifts des deux employés.
     */
    public function storeSwap(Request $request): Response
    {
        $requesterId     = (int) $request->post('requester_id', 0);
        $targetId        = (int) $request->post('target_id', 0);
        $requesterShiftId = (int) $request->post('requester_shift_id', 0);
        $targetShiftId    = (int) $request->post('target_shift_id', 0);
        $reason          = $request->post('reason', '') ?: null;

        if ($requesterId <= 0 || $targetId <= 0 || $requesterId === $targetId) {
            throw new ForbiddenException(__('error_invalid_employee_selection'));
        }

        $reqShift = $this->shifts->findById($requesterShiftId);
        if ($reqShift === null || (int) $reqShift['user_id'] !== $requesterId) {
            throw new ForbiddenException(__('error_shift_not_found'));
        }

        $tgtShift = $this->shifts->findById($targetShiftId);
        if ($tgtShift === null || (int) $tgtShift['user_id'] !== $targetId) {
            throw new ForbiddenException(__('error_target_shift_not_found'));
        }

        if ((int) $reqShift['store_id'] !== (int) $tgtShift['store_id']) {
            throw new ForbiddenException(__('error_shifts_different_stores'));
        }

        $storeId = (int) $reqShift['store_id'];
        $this->assertStoreAccess($request, $storeId);

        // Conflit : après échange, un employé se retrouverait avec deux shifts le même jour.
        // Le demandeur va recevoir le shift cible (date de $tgtShift) ; la cible va recevoir le shift du demandeur (date de $reqShift).
        if ($this->hasOtherShiftOnDate($requesterId, $tgtShift['shift_date'] ?? '', $requesterShiftId)
            || $this->hasOtherShiftOnDate($targetId, $reqShift['shift_date'] ?? '', $targetShiftId)) {
            return Response::redirect($this->base() . '/admin/swap-requests/create?requester_id=' . $requesterId . '&target_id=' . $targetId . '&error=swap_conflict');
        }

        // Application immédiate de l'échange (pas de workflow d'acceptation)
        $this->shifts->save(array_merge($reqShift, ['user_id' => $targetId]));
        $this->shifts->save(array_merge($tgtShift, ['user_id' => $requesterId]));

        $savedSwap = $this->swapRequests->save([
            'store_id'           => $storeId,
            'requester_id'       => $requesterId,
            'target_user_id'     => $targetId,
            'requester_shift_id' => $requesterShiftId,
            'target_shift_id'    => $targetShiftId,
            'reason'             => $reason,
            'status'             => 'accepted',
            'accepted_at'        => date('Y-m-d H:i:s'),
        ]);

        $this->auditLogger->log($request, 'swap.created_by_admin', 'shift_swap_request', (int) ($savedSwap['id'] ?? 0), [
            'requester_id' => $requesterId,
            'target_id'    => $targetId,
        ], $storeId);

        $this->notifs->notifyMany(
            [$requesterId, $targetId],
            'shift_assigned',
            'notif_swap_applied_body',
            [],
            (int) ($savedSwap['id'] ?? 0)
        );

        return Response::redirect($this->base() . '/admin/swap-requests?success=created');
    }

    /**
     * Vrai si $userId a déjà un shift à la date $date, autre que $excludeShiftId.
     */
    private function hasOtherShiftOnDate(int $userId, string $date, int $excludeShiftId): bool
    {
        if ($date === '') {
            return false;
        }
        foreach ($this->shifts->findByUser($userId) as $s) {
            if ((int) ($s['id'] ?? 0) === $excludeShiftId) continue;
            if (($s['shift_date'] ?? '') === $date) return true;
        }
        return false;
    }

    public function swapRequests(Request $request): Response
    {
        $managedIds = $this->managedIds($request);

        $usersMap  = $this->buildUsersMap();
        $storesMap = $this->buildStoresMap($managedIds);
        $swaps     = $this->filterByStore($this->swapRequests->findAll(), $managedIds);

        $statusFilter = (string) ($request->query('status') ?? '');
        if ($statusFilter !== '') {
            $swaps = array_values(array_filter($swaps, fn($s) => ($s['status'] ?? 'pending') === $statusFilter));
        }

        $sort = $request->query('sort') ?? 'date_desc';
        usort($swaps, function ($a, $b) use ($sort, $usersMap, $storesMap) {
            $dateA  = ($a['created_at'] ?? '');
            $dateB  = ($b['created_at'] ?? '');
            $reqA   = strtolower($usersMap[(int)($a['requester_id'] ?? 0)] ?? '');
            $reqB   = strtolower($usersMap[(int)($b['requester_id'] ?? 0)] ?? '');
            $statA  = $a['status'] ?? '';
            $statB  = $b['status'] ?? '';
            $storeA = strtolower($storesMap[(int)($a['store_id'] ?? 0)] ?? '');
            $storeB = strtolower($storesMap[(int)($b['store_id'] ?? 0)] ?? '');
            return match ($sort) {
                'date_asc'        => strcmp($dateA, $dateB),
                'requester_asc'   => strcmp($reqA, $reqB) ?: strcmp($dateA, $dateB),
                'requester_desc'  => strcmp($reqB, $reqA) ?: strcmp($dateA, $dateB),
                'status_asc'      => strcmp($statA, $statB) ?: strcmp($dateA, $dateB),
                'status_desc'     => strcmp($statB, $statA) ?: strcmp($dateA, $dateB),
                'store_asc'       => strcmp($storeA, $storeB) ?: strcmp($dateA, $dateB),
                'store_desc'      => strcmp($storeB, $storeA) ?: strcmp($dateA, $dateB),
                default           => strcmp($dateB, $dateA),
            };
        });

        $shiftIds = [];
        foreach ($swaps as $sw) {
            if (!empty($sw['requester_shift_id'])) $shiftIds[] = (int) $sw['requester_shift_id'];
            if (!empty($sw['target_shift_id']))    $shiftIds[] = (int) $sw['target_shift_id'];
        }
        $shiftsMap = [];
        foreach (array_unique($shiftIds) as $sid) {
            $s = $this->shifts->findById($sid);
            if ($s !== null) $shiftsMap[$sid] = $s;
        }

        return Response::html($this->view->render('shift-swap::swap-requests', [
            'title'         => 'Échanges de shifts',
            'swaps'         => $swaps,
            'users_map'     => $usersMap,
            'stores_map'    => $storesMap,
            'shifts_map'    => $shiftsMap,
            'sort'          => $sort,
            'status_filter' => $statusFilter,
        ], 'layout.app'));
    }

    public function approveSwap(Request $request): Response
    {
        $swap = $this->swapRequests->findById((int) $request->param('id'));
        if ($swap === null) {
            throw new NotFoundException(__('error_request_not_found'));
        }
        $this->assertStoreAccess($request, (int) ($swap['store_id'] ?? 0));

        $reqShiftId = (int) ($swap['requester_shift_id'] ?? 0);
        $tgtShiftId = (int) ($swap['target_shift_id'] ?? 0);
        if ($reqShiftId > 0 && $tgtShiftId > 0) {
            $reqShift = $this->shifts->findById($reqShiftId);
            $tgtShift = $this->shifts->findById($tgtShiftId);
            if ($reqShift !== null && $tgtShift !== null) {
                $this->shifts->save(array_merge($reqShift, ['user_id' => (int) $tgtShift['user_id']]));
                $this->shifts->save(array_merge($tgtShift, ['user_id' => (int) $reqShift['user_id']]));
            }
        }

        $this->swapRequests->save(array_merge($swap, ['status' => 'accepted']));
        $this->auditLogger->logUpdate($request, 'swap.approved', 'shift_swap_request', (int) $swap['id'], $swap, array_merge($swap, ['status' => 'accepted']), [], (int) ($swap['store_id'] ?? 0) ?: null);
        $this->notifs->notifyMany(
            array_filter([(int) ($swap['requester_id'] ?? 0), (int) ($swap['target_user_id'] ?? 0)]),
            'swap_accepted',
            'notif_swap_accepted_body',
            [],
            (int) $swap['id']
        );
        return Response::redirect($this->base() . '/admin/swap-requests?success=approved');
    }

    public function refuseSwap(Request $request): Response
    {
        $swap = $this->swapRequests->findById((int) $request->param('id'));
        if ($swap === null) {
            throw new NotFoundException(__('error_request_not_found'));
        }
        $this->assertStoreAccess($request, (int) ($swap['store_id'] ?? 0));
        $this->swapRequests->save(array_merge($swap, ['status' => 'refused']));
        $this->auditLogger->logUpdate($request, 'swap.refused', 'shift_swap_request', (int) $swap['id'], $swap, array_merge($swap, ['status' => 'refused']), [], (int) ($swap['store_id'] ?? 0) ?: null);
        $this->notifs->notify(
            (int) ($swap['requester_id'] ?? 0),
            'swap_refused',
            'notif_swap_refused_body',
            [],
            (int) $swap['id']
        );
        return Response::redirect($this->base() . '/admin/swap-requests?success=refused');
    }

    /**
     * POST /admin/swap-requests/{id}/delete — supprime un échange.
     * S'il avait été appliqué (status "accepted"), les shifts sont remis
     * dans leur état initial (ré-échangés) avant suppression.
     */
    public function deleteSwap(Request $request): Response
    {
        $swap = $this->swapRequests->findById((int) $request->param('id'));
        if ($swap === null) {
            throw new NotFoundException(__('error_request_not_found'));
        }
        $this->assertStoreAccess($request, (int) ($swap['store_id'] ?? 0));

        $requesterId = (int) ($swap['requester_id'] ?? 0);
        $targetId    = (int) ($swap['target_user_id'] ?? 0);

        if (($swap['status'] ?? '') === 'accepted') {
            $reqShiftId = (int) ($swap['requester_shift_id'] ?? 0);
            $tgtShiftId = (int) ($swap['target_shift_id'] ?? 0);
            if ($reqShiftId > 0 && $tgtShiftId > 0) {
                $reqShift = $this->shifts->findById($reqShiftId);
                $tgtShift = $this->shifts->findById($tgtShiftId);
                if ($reqShift !== null && $tgtShift !== null) {
                    $this->shifts->save(array_merge($reqShift, ['user_id' => $requesterId]));
                    $this->shifts->save(array_merge($tgtShift, ['user_id' => $targetId]));
                }
            }
            $this->notifs->notifyMany(
                array_filter([$requesterId, $targetId]),
                'shift_assigned',
                'notif_swap_cancelled_restored_body',
                [],
                (int) $swap['id']
            );
        }

        $this->auditLogger->log($request, 'swap.deleted', 'shift_swap_request', (int) $swap['id'], [
            'requester_id' => $requesterId,
            'target_id'    => $targetId,
            'was_status'   => $swap['status'] ?? null,
        ], (int) ($swap['store_id'] ?? 0) ?: null);

        $this->swapRequests->delete((int) $swap['id']);

        return Response::redirect($this->base() . '/admin/swap-requests?success=deleted');
    }
}
