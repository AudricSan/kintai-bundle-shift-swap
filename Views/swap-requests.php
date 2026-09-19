<?php
use kintai\UI\Components\Button;
use kintai\UI\Components\Badge;
use kintai\UI\Components\FilterBar;
use kintai\UI\Components\Table;
use kintai\UI\Components\Flash;

/** @var array  $swaps */
/** @var array  $users_map */
/** @var array  $stores_map */
/** @var array  $shifts_map  id → shift */
/** @var string $sort */
/** @var string $status_filter */
$users_map     ??= [];
$stores_map    ??= [];
$shifts_map    ??= [];
$sort          ??= 'date_desc';
$status_filter ??= '';

$statusLabels = [
    'pending'   => __('pending'),
    'accepted'  => __('accepted'),
    'refused'   => __('rejected'),
    'cancelled' => __('cancelled'),
];

if (!function_exists('kintai_swap_shift_cell')) {
    function kintai_swap_shift_cell(array $shifts_map, int $shiftId): string
    {
        $s = $shifts_map[$shiftId] ?? null;
        if ($s === null) {
            return '<span class="text-sm-muted">—</span>';
        }
        $date  = htmlspecialchars($s['shift_date'] ?? '');
        $start = substr($s['start_time'] ?? '', 0, 5);
        $end   = substr($s['end_time'] ?? '', 0, 5);
        return '<div>' . $date . '</div><div class="td-mono text-sm-muted">' . htmlspecialchars($start . '–' . $end) . '</div>';
    }
}

echo Flash::fromQuery('success', [
    'approved' => __('approved'),
    'refused'  => __('rejected'),
    'created'  => __('swap_created_admin'),
    'deleted'  => __('swap_deleted'),
])->render();
?>
<div class="page-header">
    <h2 class="page-header__title"><?= __('swap_requests') ?> <span class="page-count">(<?= count($swaps) ?>)</span></h2>
    <div class="page-header__actions">
        <?= Button::make('+ ' . __('new_swap'))->primary()->link($BASE_URL . '/admin/swap-requests/create')->render() ?>
    </div>
</div>

<?= FilterBar::make()
    ->select('status', __('status'), $statusLabels, $status_filter, __('all'))
    ->render()
?>

<div class="card">
<?= Table::make()
    ->data($swaps)
    ->emptyMessage(__('no_swap_found'))
    ->currentSort($sort)
    ->filters(['status' => $status_filter])
    ->column('#', fn($s) => (string) (int) $s['id'])
    ->sortable(__('store'), 'store', fn($s) => htmlspecialchars($stores_map[(int)($s['store_id'] ?? 0)] ?? ('ID ' . (int)($s['store_id'] ?? 0))))
    ->sortable(__('requester'), 'requester', fn($s) =>
        '<a href="' . htmlspecialchars($BASE_URL . '/admin/users/' . (int)($s['requester_id'] ?? 0) . '/edit') . '">'
        . htmlspecialchars($users_map[(int)($s['requester_id'] ?? 0)] ?? ('ID ' . (int)($s['requester_id'] ?? 0))) . '</a>'
    )
    ->column(__('target'), fn($s) =>
        '<a href="' . htmlspecialchars($BASE_URL . '/admin/users/' . (int)($s['target_user_id'] ?? 0) . '/edit') . '">'
        . htmlspecialchars($users_map[(int)($s['target_user_id'] ?? 0)] ?? ('ID ' . (int)($s['target_user_id'] ?? 0))) . '</a>'
    )
    ->column(__('requester_shift'), fn($s) => kintai_swap_shift_cell($shifts_map, (int) ($s['requester_shift_id'] ?? 0)))
    ->column(__('target_shift'), fn($s) => !empty($s['target_shift_id']) ? kintai_swap_shift_cell($shifts_map, (int) $s['target_shift_id']) : '<span class="text-sm-muted">—</span>')
    ->sortable(__('status'), 'status', function($s) use ($statusLabels) {
        $st = $s['status'] ?? 'pending';
        return Badge::make($statusLabels[$st] ?? $st)->status($st)->render();
    })
    ->sortable(__('date'), 'date', fn($s) => '<span class="td-date-muted">' . htmlspecialchars(substr($s['created_at'] ?? '', 0, 10)) . '</span>')
    ->column(__('reason'), fn($s) => '<span class="td-reason">' . htmlspecialchars($s['reason'] ?? '—') . '</span>')
    ->column(__('actions'), function($s) use ($BASE_URL) {
        $status = $s['status'] ?? 'pending';
        $id = (int) $s['id'];

        if ($status === 'pending') {
            $html = '<div class="btn-group">';
            $html .= '<form method="POST" action="' . htmlspecialchars($BASE_URL . '/admin/swap-requests/' . $id . '/approve') . '" class="form-inline">' . csrf_field()
                . Button::make(__('approve'))->success()->sm()->submit()->render() . '</form>';
            $html .= '<form method="POST" action="' . htmlspecialchars($BASE_URL . '/admin/swap-requests/' . $id . '/refuse') . '" class="form-inline">' . csrf_field()
                . Button::make(__('reject'))->danger()->sm()->submit()->render() . '</form>';
            $html .= '</div>';
            return $html;
        }

        if ($status === 'accepted') {
            return '<form method="POST" action="' . htmlspecialchars($BASE_URL . '/admin/swap-requests/' . $id . '/delete') . '" class="form-inline" data-confirm="' . htmlspecialchars(__('confirm_delete_swap'), ENT_QUOTES) . '">' . csrf_field()
                . Button::make(__('delete'))->danger()->sm()->submit()->render() . '</form>';
        }

        return '<span class="text-sm-muted">—</span>';
    })
    ->render()
?></div>
