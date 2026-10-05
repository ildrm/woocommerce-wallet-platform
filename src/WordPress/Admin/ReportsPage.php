<?php

declare(strict_types=1);

namespace WalletPlatform\WordPress\Admin;

use WalletPlatform\Application\CommandContext;
use WalletPlatform\Application\ReportService;
use WalletPlatform\Bootstrap\Services;
use WalletPlatform\Infrastructure\Csv;
use WalletPlatform\Presentation\Format;

final class ReportsPage
{
    public function __construct(private readonly Services $services)
    {
    }

    public function register(): void
    {
        add_action('admin_menu', fn () => add_submenu_page('woocommerce', __('Wallet reports', 'wallet-platform'), __('Wallet reports', 'wallet-platform'), 'wallet_reports_view', 'wallet-platform-reports', [$this, 'render']));
        add_action('admin_post_wallet_report', [$this, 'export']);
    }

    public function export(): void
    {
        if (!current_user_can('wallet_export')) {
            wp_die(esc_html__('You cannot export wallet reports.', 'wallet-platform'), '', ['response' => 403]);
        }
        check_admin_referer('wallet_report');
        [$start, $end] = $this->period($_POST);
        $rows = (new ReportService($this->services->db))->liability($start, $end);
        $this->services->wallets->kernel->execute('export:' . bin2hex(random_bytes(16)), 'report_export', [$start, $end], [], get_current_user_id(), 'Wallet liability report export', static function (CommandContext $context): array {
            $context->audit(null);
            return ['reference' => $context->reference];
        });
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="wallet-liability.csv"');
        $stream = fopen('php://output', 'wb');
        if ($stream === false) {
            throw new \RuntimeException('Export stream unavailable.');
        }
        Csv::row($stream, ['currency', 'exponent', 'opening_minor', 'credits_minor', 'debits_minor', 'expiration_minor', 'closing_minor']);
        foreach ($rows as $row) {
            Csv::row($stream, $row);
        }
        fclose($stream);
        exit;
    }

    public function render(): void
    {
        if (!current_user_can('wallet_reports_view')) {
            return;
        }
        [$start, $end] = $this->period($_GET);
        $rows = (new ReportService($this->services->db))->liability($start, $end);
        echo '<div class="wrap wallet-platform"><h1>' . esc_html__('Wallet liability report', 'wallet-platform') . '</h1><p>' . esc_html__('Opening liability + credits − debits − expiration = closing liability. Currencies are reported independently. All periods use UTC; the end date is exclusive.', 'wallet-platform') . '</p><form method="get"><input type="hidden" name="page" value="wallet-platform-reports">';
        $this->fields($start, $end);
        echo '<button class="button">' . esc_html__('View report', 'wallet-platform') . '</button></form><div class="wallet-table-scroll"><table class="widefat striped"><thead><tr>';
        foreach ([__('Currency', 'wallet-platform'), __('Opening', 'wallet-platform'), __('Credits', 'wallet-platform'), __('Debits', 'wallet-platform'), __('Expiration', 'wallet-platform'), __('Closing', 'wallet-platform')] as $label) {
            echo '<th scope="col">' . esc_html($label) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($rows as $row) {
            echo '<tr><td>' . esc_html($row['currency']) . '</td>';
            foreach (['opening', 'credits', 'debits', 'expiration', 'closing'] as $key) {
                echo '<td>' . esc_html(Format::aggregate($row[$key], $row['currency'], (int) $row['exponent'])) . '</td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table></div>';
        if (current_user_can('wallet_export')) {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="wallet_report">';
            wp_nonce_field('wallet_report');
            echo '<input type="hidden" name="start" value="' . esc_attr(gmdate('Y-m-d', $start)) . '"><input type="hidden" name="end" value="' . esc_attr(gmdate('Y-m-d', $end)) . '"><button class="button">' . esc_html__('Export report CSV', 'wallet-platform') . '</button></form>';
        }
        echo '</div>';
    }

    private function fields(int $start, int $end): void
    {
        echo '<label for="wallet-report-start">' . esc_html__('Start date', 'wallet-platform') . '</label> <input id="wallet-report-start" name="start" type="date" value="' . esc_attr(gmdate('Y-m-d', $start)) . '"> <label for="wallet-report-end">' . esc_html__('End date (exclusive)', 'wallet-platform') . '</label> <input id="wallet-report-end" name="end" type="date" value="' . esc_attr(gmdate('Y-m-d', $end)) . '"> ';
    }

    private function period(array $values): array
    {
        $now = $this->services->clock->now();
        $end = $now - $now % 86400 + 86400;
        $start = $end - 30 * 86400;
        foreach (['start', 'end'] as $name) {
            if (isset($values[$name])) {
                $value = sanitize_text_field(wp_unslash($values[$name]));
                $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));
                if (!$date || $date->format('Y-m-d') !== $value) {
                    wp_die(esc_html__('Choose valid report dates.', 'wallet-platform'));
                }
                if ($name === 'start') {
                    $start = $date->getTimestamp();
                } else {
                    $end = $date->getTimestamp();
                }
            }
        }
        if ($start < 0 || $start >= $end) {
            wp_die(esc_html__('Start must precede end.', 'wallet-platform'));
        }
        return [$start, $end];
    }
}
