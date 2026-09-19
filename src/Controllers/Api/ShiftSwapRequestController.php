<?php

declare(strict_types=1);

namespace kintai\Bundles\Installed\ShiftSwap\Controllers\Api;

use kintai\Core\Api\Paginator;
use kintai\Core\Auth\PermissionService;
use kintai\Core\Repositories\ShiftSwapRequestRepositoryInterface;
use kintai\Core\Request;
use kintai\Core\Response;
use kintai\Core\Services\AuditLogger;

/**
 * Régression (audit RBAC du 11/09/2026) : ApiPermissionMiddleware ne peut borner
 * swaps.* au store réel que si le client fournit lui-même store_id — sur les routes
 * par {id} (show/update/destroy) rien ne l'y oblige, et PermissionService::can()
 * traite l'absence de store comme "n'importe où". Un manager n'ayant swaps.* que sur
 * un store pouvait ainsi lire/modifier/supprimer une demande d'échange de n'importe
 * quel autre store. Corrigé via PermissionService::requireOwnedResource() (RBAC-V2),
 * qui re-vérifie la permission sur le store réel de la ressource après chargement.
 */
final class ShiftSwapRequestController
{
    public function __construct(
        private readonly ShiftSwapRequestRepositoryInterface $swapRequests,
        private readonly AuditLogger $auditLogger,
        private readonly PermissionService $permissions,
    ) {}

    /** GET /api/v1/shift-swap-requests?store_id=X&requester_id=Y&status=Z&page=1&limit=20 */
    public function index(Request $request): Response
    {
        [$page, $limit] = Paginator::params($request);
        $storeId     = $request->query('store_id');
        $requesterId = $request->query('requester_id');
        $status      = $request->query('status');

        if ($storeId !== null && $status !== null) {
            $items = $this->swapRequests->findByStatus((int) $storeId, $status);
        } elseif ($storeId !== null) {
            $items = $this->swapRequests->findByStore((int) $storeId);
        } elseif ($requesterId !== null) {
            $items = $this->swapRequests->findByRequester((int) $requesterId);
        } else {
            $items = [];
        }

        $items = $this->permissions->restrictToScope($this->authUser($request), 'swaps.view', $items);

        return Response::json(Paginator::paginate($items, $page, $limit));
    }

    /** GET /api/v1/shift-swap-requests/{id} */
    public function show(Request $request): Response
    {
        $item = $this->requireSwap($request, 'swaps.view');
        return Response::json($item);
    }

    /** POST /api/v1/shift-swap-requests */
    public function store(Request $request): Response
    {
        $data  = $request->json() ?? [];
        $saved = $this->swapRequests->save($data);
        $this->auditLogger->log($request, 'shift_swap_request.created', 'shift_swap_request', resourceId: (int) ($saved['id'] ?? 0) ?: null, details: $data, storeId: isset($data['store_id']) ? (int) $data['store_id'] : null);
        return Response::json($saved, 201);
    }

    /** PUT /api/v1/shift-swap-requests/{id} */
    public function update(Request $request): Response
    {
        $old = $this->requireSwap($request, 'swaps.update');
        $id  = (int) $old['id'];
        $data  = $request->json() ?? [];
        $saved = $this->swapRequests->save(array_merge($data, ['id' => $id]));
        $this->auditLogger->logUpdate($request, 'shift_swap_request.updated', 'shift_swap_request', resourceId: $id, oldData: $old, newData: $saved, extraContext: $data, storeId: isset($data['store_id']) ? (int) $data['store_id'] : null);
        return Response::json($saved);
    }

    /** DELETE /api/v1/shift-swap-requests/{id} */
    public function destroy(Request $request): Response
    {
        $item = $this->requireSwap($request, 'swaps.delete');
        $id   = (int) $item['id'];
        $this->swapRequests->delete($id);
        $this->auditLogger->log($request, 'shift_swap_request.deleted', 'shift_swap_request', resourceId: $id);
        return Response::empty();
    }

    private function authUser(Request $request): array
    {
        return $request->getAttribute('auth_user') ?? [];
    }

    /** Charge la demande par id et vérifie $permissionKey sur son store réel. */
    private function requireSwap(Request $request, string $permissionKey): array
    {
        return $this->permissions->requireOwnedResource(
            $this->authUser($request),
            fn(int $id) => $this->swapRequests->findById($id),
            (int) $request->param('id'),
            $permissionKey,
            notFoundMessage: 'Demande d\'échange introuvable.',
        );
    }
}
