<?php

namespace FluentCartGiftCards\App\Services;

use FluentCart\App\Models\Coupon;
use FluentCart\App\Services\DateTime\DateTime;

class GiftCardService
{
    private $table = 'fct_user_gift_cards';

    public function createCard($userId, $initialBalance, $orderId = null, $meta = [])
    {
        global $wpdb;
        $table = $wpdb->prefix . $this->table;

        $code = $this->generateUniqueCode($userId);

        // create the coupon in FluentCart side first
        $couponId = $this->syncToFluentCoupon($code, $initialBalance, $userId);

        $data = [
            'user_id'         => $userId,
            'order_id'        => $orderId,
            'coupon_id'       => $couponId,
            'code'            => $code,
            'initial_balance' => $initialBalance,
            'current_balance' => $initialBalance,
            'status'          => 'active',
            'created_at'      => current_time('mysql'),
            'updated_at'      => current_time('mysql'),
            'settings'        => json_encode($meta)
        ];

        $wpdb->insert($table, $data);

        return $wpdb->insert_id;
    }

    public function getCardByCode($code)
    {
        global $wpdb;
        $table = $wpdb->prefix . $this->table;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE code = %s", $code));
    }

    public function getCardsByUser($userId)
    {
        global $wpdb;
        $table = $wpdb->prefix . $this->table;
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE user_id = %d AND current_balance > 0 AND status = 'active'", $userId));
    }

    /*
     * Updates the balance of a gift card.
     * Logic: If balance reaches 0, mark as redeemed (or keeping active but 0 balance is safer for refunds).
     */
    public function debitBalance($cardId, $amount)
    {
        global $wpdb;
        $table = $wpdb->prefix . $this->table;
        
        $card = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $cardId));
        if (!$card || $card->current_balance < $amount) {
            return false;
        }

        $newBalance = $card->current_balance - $amount;
        $status = $newBalance <= 0 ? 'redeemed' : 'active';

        $wpdb->update($table, [
            'current_balance' => $newBalance,
            'status'          => $status,
            'updated_at'      => current_time('mysql')
        ], ['id' => $cardId]);

        // Sync to Coupon
        $this->updateFluentCoupon($card->coupon_id, $newBalance, $status);

        return true;
    }

    public function creditBalance($cardId, $amount)
    {
        global $wpdb;
        $table = $wpdb->prefix . $this->table;

        $card = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $cardId));
        if (!$card) return false;

        $newBalance = $card->current_balance + $amount;
        // Cap at initial balance? Maybe not, refunds could technically overflow if not careful, but usually capped. 
        // For now, let's assume strict refund logic handles caps.
        if ($newBalance > $card->initial_balance) {
            $newBalance = $card->initial_balance;
        }

        $wpdb->update($table, [
            'current_balance' => $newBalance,
            'status'          => 'active', // Should always be active if refunded
            'updated_at'      => current_time('mysql')
        ], ['id' => $cardId]);

        // Sync to Coupon
        $this->updateFluentCoupon($card->coupon_id, $newBalance, 'active');

        return true;
    }

    private function generateUniqueCode($userId)
    {
        // Format: gift-{userid}-{random4}
        // Random 4 digit/char
        $postfix = substr(str_shuffle("0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ"), 0, 4);
        return 'gift-' . $userId . '-' . $postfix;
    }

    private function syncToFluentCoupon($code, $amount, $userId)
    {
        // Create a Coupon in FluentCart
        $couponData = [
            'title'      => 'Gift Card ' . $code,
            'code'       => $code,
            'status'     => 'active',
            'type'       => 'fixed_cart_amount',
            'amount'     => $amount,
            'start_date' => date('Y-m-d H:i:s'),
            'end_date'   => null,
            'settings'   => [
                'is_gift_card' => 'yes',
                'gift_card_owner' => $userId
            ]
        ];

        $coupon = Coupon::create($couponData);
        return $coupon->id;
    }

    private function updateFluentCoupon($couponId, $newBalance, $vmStatus)
    {
        $coupon = Coupon::find($couponId);
        if (!$coupon) return;

        $coupon->amount = $newBalance;
        
        if ($vmStatus == 'redeemed' || $newBalance <= 0) {
            $coupon->status = 'inactive';
        } else {
            $coupon->status = 'active';
        }

        $coupon->save();
    }
}
