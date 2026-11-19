<?php
declare(strict_types=1);

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Helpers.php';

/**
 * TelegramNotifier
 * 
 * Centralized, fail-safe Telegram alert dispatcher.
 * Guaranteed NON-BLOCKING:
 * - 3-second network timeout
 * - Fully wrapped in try-catch blocks
 * - Never halts shop operations, cashier shift closing, day closing, or POS sales if Telegram fails or is not configured
 * - Strictly omits customer phone numbers to protect customer privacy
 */
class TelegramNotifier {

    /**
     * Send an HTML formatted message to the configured owner Telegram chat.
     * Returns true if sent successfully, false otherwise.
     */
    public static function send(string $message): bool {
        try {
            $token = (string)get_setting('telegram_bot_token', '');
            $chatId = (string)get_setting('telegram_chat_id', '');

            // If Telegram is not configured, silently skip immediately (zero latency)
            if (empty($token) || empty($chatId)) {
                return false;
            }

            $url = "https://api.telegram.org/bot" . urlencode($token) . "/sendMessage";
            $payload = [
                'chat_id'                  => $chatId,
                'text'                     => $message,
                'parse_mode'               => 'HTML',
                'disable_web_page_preview' => true,
            ];

            $ctx = stream_context_create([
                'http' => [
                    'method'        => 'POST',
                    'header'        => "Content-Type: application/x-www-form-urlencoded\r\n",
                    'content'       => http_build_query($payload),
                    'timeout'       => 3, // Fast 3-second timeout prevents any blocking
                    'ignore_errors' => true
                ],
                'ssl' => [
                    'verify_peer'      => true,
                    'verify_peer_name' => true
                ]
            ]);

            $resp = @file_get_contents($url, false, $ctx);
            if ($resp === false) {
                return false;
            }

            $json = json_decode($resp, true);
            return !empty($json['ok']);
        } catch (Throwable $t) {
            // Never break parent caller
            error_log("TelegramNotifier::send exception: " . $t->getMessage());
            return false;
        }
    }

    /**
     * Event 1: Day Closed (Z-Report)
     * Notifies the owner of total sales (USD & KHR) and whether drawer cash is balanced, short, or over.
     * Strictly omits customer phone numbers.
     */
    public static function notifyDayClosed(array $data): bool {
        try {
            $date = htmlspecialchars((string)($data['close_date'] ?? date('Y-m-d')));
            $closedBy = htmlspecialchars((string)($data['closed_by_name'] ?? 'Staff'));
            $role = htmlspecialchars(ucfirst((string)($data['role'] ?? 'Cashier')));
            $salesUsd = number_format((float)($data['total_sales_usd'] ?? 0), 2);
            $salesKhr = number_format((int)($data['total_sales_khr'] ?? 0));
            $ordersCount = (int)($data['paid_orders_count'] ?? 0);
            $zReport = htmlspecialchars((string)($data['z_report_number'] ?? ''));

            $diffUsd = (float)($data['difference_usd'] ?? 0);
            $diffKhr = (int)($data['difference_khr'] ?? 0);
            $totalDiffUsd = (float)($data['total_difference_usd'] ?? $diffUsd);

            if ($totalDiffUsd < -0.01) {
                $statusLine = "⚠️ <b>Cash Status: SHORT by -$" . number_format(abs($totalDiffUsd), 2) . "</b> (Needs Review)";
            } elseif ($totalDiffUsd > 0.01) {
                $statusLine = "ℹ️ <b>Cash Status: OVER by +$" . number_format($totalDiffUsd, 2) . "</b>";
            } else {
                $statusLine = "✅ <b>Cash Status: BALANCED ($0.00 difference)</b>";
            }

            $msg = "🌙 <b>[DAY CLOSED] {$date}</b>\n"
                 . "Z-Report: <code>{$zReport}</code>\n"
                 . "Closed By: <b>{$closedBy}</b> ({$role})\n"
                 . "Paid Orders: <b>{$ordersCount}</b>\n"
                 . "Total Sales: <b>\${$salesUsd}</b> / <b>{$salesKhr} KHR</b>\n"
                 . $statusLine;

            return self::send($msg);
        } catch (Throwable $t) {
            error_log("TelegramNotifier::notifyDayClosed error: " . $t->getMessage());
            return false;
        }
    }

    /**
     * Event 2: Shift Ended With Shortage
     * Triggered only when the shift drawer cash count is short.
     * Strictly omits customer phone numbers.
     */
    public static function notifyShiftShortage(
        string $cashierName,
        float $shortUsd,
        int $shortKhr,
        string $shiftUid,
        float $totalDiffUsd
    ): bool {
        try {
            $cashier = htmlspecialchars($cashierName);
            $uid = htmlspecialchars($shiftUid);
            $absTotalDiff = number_format(abs($totalDiffUsd), 2);

            $breakdown = [];
            if ($shortUsd < -0.01) {
                $breakdown[] = "-$" . number_format(abs($shortUsd), 2);
            }
            if ($shortKhr < -100) {
                $breakdown[] = "-" . number_format(abs($shortKhr)) . " KHR";
            }
            $details = !empty($breakdown) ? " (" . implode(' / ', $breakdown) . ")" : "";

            $msg = "⚠️ <b>[SHIFT CASH SHORTAGE]</b>\n"
                 . "Shift: <code>{$uid}</code>\n"
                 . "Cashier: <b>{$cashier}</b>\n"
                 . "Short Amount: <b>-\${$absTotalDiff}</b>{$details}\n"
                 . "Status: Recorded as owed by cashier. Needs manager review.";

            return self::send($msg);
        } catch (Throwable $t) {
            error_log("TelegramNotifier::notifyShiftShortage error: " . $t->getMessage());
            return false;
        }
    }

    /**
     * Event 3: Order Cancelled
     * Notifies owner when an order is voided/cancelled with reason and authorizer.
     * Strictly omits customer phone numbers.
     */
    public static function notifyOrderCancelled(
        string $orderNumber,
        string $plateNumber,
        float $amountUsd,
        string $reason,
        string $cancelledBy
    ): bool {
        try {
            $ordNum = htmlspecialchars($orderNumber);
            $plate = htmlspecialchars($plateNumber !== '' ? $plateNumber : 'N/A');
            $amt = number_format($amountUsd, 2);
            $who = htmlspecialchars($cancelledBy);
            $cleanReason = htmlspecialchars(mb_strimwidth(trim($reason), 0, 150, '...'));

            $msg = "🚫 <b>[ORDER CANCELLED]</b>\n"
                 . "Order: <code>#{$ordNum}</code>\n"
                 . "Vehicle: <b>{$plate}</b>\n"
                 . "Amount: <b>\${$amt}</b>\n"
                 . "Cancelled By: <b>{$who}</b>\n"
                 . "Reason: <i>{$cleanReason}</i>";

            return self::send($msg);
        } catch (Throwable $t) {
            error_log("TelegramNotifier::notifyOrderCancelled error: " . $t->getMessage());
            return false;
        }
    }

    /**
     * Event 4: Day Reopened
     * Triggered when a Manager/Owner reopens a previously closed day.
     * Includes who did it and the mandatory reason.
     * Strictly omits customer phone numbers.
     */
    public static function notifyDayReopened(
        string $closeDate,
        string $zReportNumber,
        string $reopenedBy,
        string $role,
        string $reason
    ): bool {
        try {
            $date = htmlspecialchars($closeDate);
            $zReport = htmlspecialchars($zReportNumber);
            $who = htmlspecialchars($reopenedBy);
            $roleLabel = htmlspecialchars(ucfirst($role));
            $cleanReason = htmlspecialchars(mb_strimwidth(trim($reason), 0, 150, '...'));

            $msg = "🔓 <b>[DAY REOPENED] {$date}</b>\n"
                 . "Z-Report: <code>{$zReport}</code>\n"
                 . "Reopened By: <b>{$who}</b> ({$roleLabel})\n"
                 . "Reason: <i>{$cleanReason}</i>\n"
                 . "Status: Day is unlocked. Cashier sales and shifts resumed.";

            return self::send($msg);
        } catch (Throwable $t) {
            error_log("TelegramNotifier::notifyDayReopened error: " . $t->getMessage());
            return false;
        }
    }

    /**
     * Event 5: Shift Ended With Cash Over
     * Triggered when shift drawer cash count exceeds expected amount.
     * Includes cashier and over amount.
     * Strictly omits customer phone numbers.
     */
    public static function notifyShiftOver(
        string $cashierName,
        float $diffUsd,
        int $diffKhr,
        string $shiftUid,
        float $totalDiffUsd
    ): bool {
        try {
            $cashier = htmlspecialchars($cashierName);
            $uid = htmlspecialchars($shiftUid);
            $absTotalDiff = number_format(abs($totalDiffUsd), 2);

            $breakdown = [];
            if ($diffUsd > 0.01) {
                $breakdown[] = "+$" . number_format($diffUsd, 2);
            }
            if ($diffKhr > 100) {
                $breakdown[] = "+" . number_format($diffKhr) . " KHR";
            }
            $details = !empty($breakdown) ? " (" . implode(' / ', $breakdown) . ")" : "";

            $msg = "💵 <b>[SHIFT CASH OVER]</b>\n"
                 . "Shift: <code>{$uid}</code>\n"
                 . "Cashier: <b>{$cashier}</b>\n"
                 . "Over Amount: <b>+\${$absTotalDiff}</b>{$details}\n"
                 . "Status: Cash surplus recorded on shift closeout.";

            return self::send($msg);
        } catch (Throwable $t) {
            error_log("TelegramNotifier::notifyShiftOver error: " . $t->getMessage());
            return false;
        }
    }

    /**
     * Event 6: Order Discount Above Limits
     * Triggered when an order discount exceeds BOTH configured thresholds:
     * - Percent threshold (default 20%)
     * - Dollar threshold (default $5.00)
     * Strictly omits customer phone numbers.
     */
    public static function checkAndNotifyOrderDiscount(
        string $orderNumber,
        float $subtotalUsd,
        float $discountUsd,
        string $appliedBy,
        string $plateNumber = ''
    ): bool {
        try {
            if ($subtotalUsd <= 0.0 || $discountUsd <= 0.0) {
                return false;
            }

            $pctLimit = (float)get_setting('discount_alert_percent_threshold', '20');
            $usdLimit = (float)get_setting('discount_alert_dollar_threshold', '5');

            $actualPct = ($discountUsd / $subtotalUsd) * 100;

            // Trigger ONLY when BOTH limits are passed
            if ($actualPct <= $pctLimit || $discountUsd <= $usdLimit) {
                return false;
            }

            $ordNum = htmlspecialchars($orderNumber);
            $plate = htmlspecialchars($plateNumber !== '' ? $plateNumber : 'N/A');
            $who = htmlspecialchars($appliedBy);
            $subtotalFmt = number_format($subtotalUsd, 2);
            $discFmt = number_format($discountUsd, 2);
            $pctFmt = number_format($actualPct, 1);
            $finalTotal = number_format(max(0.0, $subtotalUsd - $discountUsd), 2);

            $msg = "🏷️ <b>[HIGH DISCOUNT ALERT]</b>\n"
                 . "Order: <code>#{$ordNum}</code>\n"
                 . "Vehicle: <b>{$plate}</b>\n"
                 . "Subtotal: <b>\${$subtotalFmt}</b>\n"
                 . "Discount: <b>-\${$discFmt} ({$pctFmt}%)</b>\n"
                 . "Final Total: <b>\${$finalTotal}</b>\n"
                 . "Given By: <b>{$who}</b>\n"
                 . "Limits Passed: >" . number_format($pctLimit, 1) . "% and >\$" . number_format($usdLimit, 2);

            return self::send($msg);
        } catch (Throwable $t) {
            error_log("TelegramNotifier::checkAndNotifyOrderDiscount error: " . $t->getMessage());
            return false;
        }
    }
}
