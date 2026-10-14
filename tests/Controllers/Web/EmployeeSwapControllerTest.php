<?php

declare(strict_types=1);

namespace kintai\Tests\Bundles\ShiftSwap\Controllers\Web;


use kintai\Bundles\Installed\ShiftSwap\Controllers\Web\EmployeeSwapController;
use kintai\Core\Exceptions\ForbiddenException;
use kintai\Core\Repositories\ShiftRepositoryInterface;
use kintai\Core\Repositories\ShiftSwapRequestRepositoryInterface;
use kintai\Core\Repositories\ShiftTypeRepositoryInterface;
use kintai\Core\Repositories\StoreRepositoryInterface;
use kintai\Core\Repositories\StoreUserRepositoryInterface;
use kintai\Core\Repositories\UserRepositoryInterface;
use kintai\Core\Request;
use kintai\Core\Services\AuditLogger;
use kintai\Core\Services\NotificationService;
use kintai\UI\ViewRenderer;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Régression : storeSwap/acceptSwap/refuseSwap écrivaient et relisaient les clés
 * 'target_id'/'peer_accepted_at', qui n'existent pas dans la table shift_swap_requests
 * (colonnes réelles : target_user_id/accepted_at). En production, storeSwap plantait
 * avec une erreur SQL "unknown column 'target_id'" à chaque tentative d'échange.
 */
final class EmployeeSwapControllerTest extends TestCase
{
    private ShiftRepositoryInterface&MockObject $shifts;
    private ShiftSwapRequestRepositoryInterface&MockObject $swapRequests;
    private StoreRepositoryInterface&MockObject $stores;
    private StoreUserRepositoryInterface&MockObject $storeUsers;
    private NotificationService&MockObject $notifs;
    private EmployeeSwapController $controller;

    protected function setUp(): void
    {
        $viewDir = sys_get_temp_dir() . '/kintai-shift-swap-views';
        $this->ensureViewFile($viewDir, 'swaps');
        $this->ensureViewFile($viewDir, 'swaps-form');
        $this->ensureViewFile(sys_get_temp_dir(), 'layout.app');

        $view = new ViewRenderer(sys_get_temp_dir());
        $view->addNamespace('shift-swap', $viewDir);

        $this->shifts       = $this->createMock(ShiftRepositoryInterface::class);
        $this->swapRequests = $this->createMock(ShiftSwapRequestRepositoryInterface::class);
        $this->stores       = $this->createMock(StoreRepositoryInterface::class);
        $this->storeUsers   = $this->createMock(StoreUserRepositoryInterface::class);
        $this->notifs       = $this->createMock(NotificationService::class);

        $this->controller = new EmployeeSwapController(
            $view,
            $this->swapRequests,
            $this->shifts,
            $this->createMock(ShiftTypeRepositoryInterface::class),
            $this->stores,
            $this->storeUsers,
            $this->createMock(UserRepositoryInterface::class),
            new AuditLogger(),
            $this->notifs,
        );
    }

    protected function tearDown(): void
    {
        $_GET = [];
        $_POST = [];
    }

    public function testStoreSwapSavesTargetUserIdAndAcceptedAtColumns(): void
    {
        $this->storeUsers->method('findByUser')->with(1)->willReturn([['store_id' => 5, 'user_id' => 1]]);
        $this->stores->method('getFeatures')->with(5)->willReturn([]);

        $_POST = [
            'requester_shift_id' => '10',
            'target_id'          => '2',
            'target_shift_id'    => '20',
            'reason'             => 'Test',
        ];

        $myShift     = ['id' => 10, 'user_id' => 1, 'store_id' => 5];
        $targetShift = ['id' => 20, 'user_id' => 2, 'store_id' => 5];
        $this->shifts->method('findById')->willReturnMap([
            [10, $myShift],
            [20, $targetShift],
        ]);

        $captured = null;
        $this->swapRequests->method('save')->willReturnCallback(function (array $d) use (&$captured) {
            $captured = $d;
            return $d + ['id' => 42];
        });

        $this->notifs->expects($this->once())->method('notify')->with(2, 'swap_requested', $this->anything(), $this->anything(), 42);

        $req = new Request();
        $req->setAttribute('auth_user', ['id' => 1]);

        $response = $this->controller->storeSwap($req);

        $this->assertSame(302, $response->status());
        $this->assertArrayHasKey('target_user_id', $captured);
        $this->assertSame(2, $captured['target_user_id']);
        $this->assertArrayNotHasKey('target_id', $captured);
        $this->assertArrayHasKey('accepted_at', $captured);
        $this->assertNull($captured['accepted_at']);
        $this->assertArrayNotHasKey('peer_accepted_at', $captured);
    }

    public function testStoreSwapRedirectsWhenFeatureDisabledForStore(): void
    {
        $this->storeUsers->method('findByUser')->with(1)->willReturn([['store_id' => 5, 'user_id' => 1]]);
        $this->stores->method('getFeatures')->with(5)->willReturn(['shifts']);

        $_POST = ['requester_shift_id' => '10', 'target_id' => '2', 'target_shift_id' => '20'];
        $req = new Request();
        $req->setAttribute('auth_user', ['id' => 1]);

        $response = $this->controller->storeSwap($req);

        $this->assertSame(302, $response->status());
    }

    public function testAcceptSwapReadsAndWritesAcceptedAtColumn(): void
    {
        $swap = [
            'id' => 5, 'store_id' => 3, 'requester_id' => 1,
            'target_user_id' => 9, 'status' => 'pending', 'accepted_at' => null,
        ];
        $this->swapRequests->method('findById')->with(5)->willReturn($swap);

        $captured = null;
        $this->swapRequests->expects($this->once())->method('save')->willReturnCallback(function (array $d) use (&$captured) {
            $captured = $d;
            return $d;
        });

        $this->notifs->expects($this->once())->method('notify')->with(1, 'swap_peer_accepted', $this->anything(), $this->anything(), 5);

        $req = new Request();
        $req->setAttribute('auth_user', ['id' => 9]);
        $req->setRouteParams(['id' => '5']);

        $response = $this->controller->acceptSwap($req);

        $this->assertSame(302, $response->status());
        $this->assertArrayHasKey('accepted_at', $captured);
        $this->assertNotNull($captured['accepted_at']);
    }

    public function testAcceptSwapForbiddenWhenCurrentUserIsNotTheTarget(): void
    {
        $swap = [
            'id' => 5, 'store_id' => 3, 'requester_id' => 1,
            'target_user_id' => 9, 'status' => 'pending', 'accepted_at' => null,
        ];
        $this->swapRequests->method('findById')->with(5)->willReturn($swap);

        $req = new Request();
        $req->setAttribute('auth_user', ['id' => 1]);
        $req->setRouteParams(['id' => '5']);

        $this->expectException(ForbiddenException::class);
        $this->controller->acceptSwap($req);
    }

    public function testRefuseSwapReadsTargetUserIdColumn(): void
    {
        $swap = [
            'id' => 5, 'store_id' => 3, 'requester_id' => 1,
            'target_user_id' => 9, 'status' => 'pending', 'accepted_at' => null,
        ];
        $this->swapRequests->method('findById')->with(5)->willReturn($swap);

        $captured = null;
        $this->swapRequests->expects($this->once())->method('save')->willReturnCallback(function (array $d) use (&$captured) {
            $captured = $d;
            return $d;
        });

        $this->notifs->expects($this->once())->method('notify')->with(1, 'swap_peer_refused', $this->anything(), $this->anything(), 5);

        $req = new Request();
        $req->setAttribute('auth_user', ['id' => 9]);
        $req->setRouteParams(['id' => '5']);

        $response = $this->controller->refuseSwap($req);

        $this->assertSame(302, $response->status());
        $this->assertSame('refused', $captured['status']);
    }

    public function testCancelSwapNotifiesTargetUser(): void
    {
        $swap = [
            'id' => 5, 'store_id' => 3, 'requester_id' => 1,
            'target_user_id' => 9, 'status' => 'pending', 'accepted_at' => null,
        ];
        $this->swapRequests->method('findById')->with(5)->willReturn($swap);
        $this->swapRequests->expects($this->once())->method('save');

        $this->notifs->expects($this->once())->method('notify')->with(9, 'swap_cancelled', $this->anything(), $this->anything(), 5);

        $req = new Request();
        $req->setAttribute('auth_user', ['id' => 1]);
        $req->setRouteParams(['id' => '5']);

        $response = $this->controller->cancelSwap($req);

        $this->assertSame(302, $response->status());
    }

    private function ensureViewFile(string $dir, string $view): void
    {
        $file = $dir . DIRECTORY_SEPARATOR . str_replace('.', DIRECTORY_SEPARATOR, $view) . '.php';
        $parent = dirname($file);
        if (!is_dir($parent)) {
            mkdir($parent, 0777, true);
        }
        touch($file);
    }
}
