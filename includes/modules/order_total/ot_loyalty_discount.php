<?php
/**
 * Order Total Module
 *
 * @package - Loyalty Disccount
 * @copyright Copyright 2007-2008 Numinix Technology http://www.numinix.com
 * @copyright Copyright 2003-2019 Zen Cart Development Team
 * @copyright Portions Copyright 2003 osCommerce
 * @license http://www.zen-cart.com/license/2_0.txt GNU Public License V2.0
 * @version $Id: ot_loyalty_discount.php 2019-11-24 15:15:00 webchills
 */
class ot_loyalty_discount
{
    public
        $code,
        $title,
        $description,
        $enabled,
        $sort_order,
        $output;

    protected
        $_check,
        $discount_table,
        $loyalty_order_status,
        $od_pc,
        $period_string,
        $cum_order_total;

    public function __construct()
    {
        $this->code = 'ot_loyalty_discount';
        $this->title = MODULE_LOYALTY_DISCOUNT_TITLE;
        $this->description = MODULE_LOYALTY_DISCOUNT_DESCRIPTION;

        $this->sort_order = defined('MODULE_LOYALTY_DISCOUNT_SORT_ORDER') ? MODULE_LOYALTY_DISCOUNT_SORT_ORDER : null;
        if (null === $this->sort_order) {
            return false;
        }

        $this->enabled = (MODULE_LOYALTY_DISCOUNT_STATUS === 'true');
        if ($this->enabled === false) {
            return false;
        }

        $this->loyalty_order_status = MODULE_LOYALTY_DISCOUNT_ORDER_STATUS;
        $this->output = [];
        $this->od_pc = 0;

        // -----
        // No loyalty-discount for guest-checkout purchases!
        //
        if (IS_ADMIN_FLAG === false && zen_in_guest_checkout() === true) {
            $this->enabled = false;
        } else {
            $this->checkConfiguration();
        }
    }

    // -----
    // Check the order-total's configuration to see if the discount-table specification
    // is 'processable'.
    //
    // The table's format is in the form 'vvv:ppp[,vvv:ppp]' where
    //
    // 1. Each 'vvv' represents the minimum value of the customer's previous order's amount
    //    for which the 'ppp' percentage discount is to apply.  Each 'vvv' and 'ppp' value
    //    is a numeric value, e.g. '5' or '5.00'.
    // 2. Each 'vvv' value specified must be greater than the previous 'vvv' value.
    //
    protected function checkConfiguration()
    {
        $this->discount_table = [];

        $discount_table = explode(',', str_replace(' ', '', MODULE_LOYALTY_DISCOUNT_TABLE));
        $configuration_message = '';
        if (empty($discount_table)) {
            $configuration_message = 'No discounts configured.';
        } else {
            $last_discount_amount = 0;
            foreach ($discount_table as $next_discount) {
                if (strpos($next_discount, ':') === false) {
                    $configuration_message = "Invalid discount-table format [$next_discount].";
                    break;
                }
                list($discount_amount, $discount_percentage) = explode(':', $next_discount);
                if (!is_numeric($discount_amount) || !is_numeric($discount_percentage)) {
                    $configuration_message = "Invalid discount amount [$next_discount].";
                    break;
                }
                if ($discount_amount <= $last_discount_amount) {
                    $configuration_message = "Discount amounts must be in ascending order [$next_discount].";
                    break;
                }
                $this->discount_table[$discount_amount] = $discount_percentage;
                $last_discount_amount = $discount_amount;
            }
        }
        if ($configuration_message !== '') {
            $this->enabled = false;
            if (IS_ADMIN_FLAG === true) {
                $this->title .= '<small><span class="alert">(' . $configuration_message . ')</span></small>';
            }
        }
    }

    public function process()
    {
        global $order, $ot_subtotal, $currencies;

        if ($this->enabled === false) {
            return;
        }

        $od_amount = $this->calculate_credit($this->get_order_total(), $this->get_cum_order_total());
        if ($od_amount > 0) {
            $tmp = sprintf(MODULE_LOYALTY_DISCOUNT_INFO, $this->period_string, $currencies->format($this->cum_order_total), $this->od_pc . '%');
            $this->output[] = [
                'title' => '<div class="ot_loyalty_title">' . $this->title . ':</div>' . $tmp,
                'text' => $currencies->format($od_amount),
                'value' => $od_amount
            ];
            $order->info['total'] -= $od_amount;
            if ($this->sort_order < $ot_subtotal->sort_order) {
                $order->info['subtotal'] -= $od_amount;
            }
        }
    }

    protected function calculate_credit($amount_order, $amount_cum_order)
    {
        global $order;

        $od_amount = 0;
        $od_pc = false;
        foreach ($this->discount_table as $amount => $discount) {
            if ($amount_cum_order >= $amount) {
                $od_pc = $discount;
                $this->od_pc = $discount;
            }
        }

        // -----
        // If the customer doesn't qualify for a discount, quick return.
        //
        if ($od_pc === false) {
            return 0;
        }

        // Calculate tax reduction if necessary
        $od_amount = $amount_order * $od_pc / 100;
        if (MODULE_LOYALTY_DISCOUNT_CALC_TAX === 'true') {
            // Calculate main tax reduction
            $todx_amount = $order->info['tax'] * $od_pc / 100;
            $order->info['tax'] -= $todx_amount;
            $od_amount += $todx_amount;

            // Calculate tax group deductions
            foreach ($order->info['tax_groups'] as $key => $value) {
                $order->info['tax_groups'][$key] -= ($value * $od_pc / 100);
            }

        }

        return $od_amount;
    }

    public function get_order_total()
    {
        global $order;

        $order_total = $order->info['total'];

        // -----
        // Gift certificates/vouchers aren't included in any discount, so their
        // cost (and tax) will be removed from the order's total prior to return.
        //
        $gv_amount = 0;
        $gv_tax = 0;
        foreach ($order->products as $next_product) {
            if (strpos($next_product['model'], 'GIFT') !== 0) {
                continue;
            }

            $current_gv_amount = $next_product['final_price'] * $next_product['qty'];

            $gv_tax += zen_calculate_tax($current_gv_amount, $next_product['tax']);
            $gv_amount += $current_gv_amount;
        }

        if (MODULE_LOYALTY_DISCOUNT_INC_TAX === 'false') {
            $order_total = $order_total - $order->info['tax'] + $gv_tax;
        }
        if (MODULE_LOYALTY_DISCOUNT_INC_SHIPPING === 'false') {
            $order_total = $order_total - $order->info['shipping_cost'];
        }
        return $order_total;
    }

    protected function get_cum_order_total()
    {
        global $db;

        $customer_id = $_SESSION['customer_id'];
        $history_query_raw =
            "SELECT o.date_purchased, ot.value AS order_total
               FROM " . TABLE_ORDERS . " o
                    LEFT JOIN " . TABLE_ORDERS_TOTAL . " ot
                        ON o.orders_id = ot.orders_id
              WHERE o.customers_id = " . $customer_id . "
                AND ot.class = 'ot_total'
                AND o.orders_status >= " . $this->loyalty_order_status . "
              ORDER BY date_purchased DESC";
        $history_query = $db->Execute($history_query_raw);
        $this->cum_order_total = 0;
        if (!$history_query->EOF) {
            $cutoff_timestamp = $this->get_cutoff_timestamp();
            foreach ($history_query as $next_order) {
                if ($this->get_date_in_period($cutoff_timestamp, $next_order['date_purchased']) === true) {
                    $this->cum_order_total += $next_order['order_total'];
                }
            }
        }

        return $this->cum_order_total;
    }

    protected function get_cutoff_timestamp()
    {
        switch (MODULE_LOYALTY_DISCOUNT_CUMORDER_PERIOD) {
            case 'year':
                $this->period_string = MODULE_LOYALTY_DISCOUNT_YEAR;
                $cutoff_timestamp = strtotime('-1 year');
                break;

            case 'quarter':
                $this->period_string = MODULE_LOYALTY_DISCOUNT_QUARTER;
                $cutoff_timestamp = strtotime('-3 month');
                break;

            case 'month':
                $this->period_string = MODULE_LOYALTY_DISCOUNT_MONTH;
                $cutoff_timestamp = strtotime('-1 month');
                break;

            case 'alltime':
            default:
                $this->period_string = MODULE_LOYALTY_DISCOUNT_WITHUS;
                $cutoff_timestamp = 0;
                break;
        }
        return $cutoff_timestamp;
    }

    protected function get_date_in_period($cutoff_timestamp, $raw_date)
    {
        if ($raw_date === '0000-00-00 00:00:00' || empty($raw_date)) {
            return false;
        }
        return strtotime($raw_date) >= $cutoff_timestamp;
    }

    public function check()
    {
        global $db;

        if (!isset($this->_check)) {
            $check_query = $db->Execute(
                "SELECT configuration_value
                   FROM " . TABLE_CONFIGURATION . "
                  where configuration_key = 'MODULE_LOYALTY_DISCOUNT_STATUS'"
            );
            $this->_check = $check_query->RecordCount();
        }
        return $this->_check;
    }

    public function keys()
    {
        return [
            'MODULE_LOYALTY_DISCOUNT_STATUS',
            'MODULE_LOYALTY_DISCOUNT_SORT_ORDER',
            'MODULE_LOYALTY_DISCOUNT_CUMORDER_PERIOD',
            'MODULE_LOYALTY_DISCOUNT_TABLE',
            'MODULE_LOYALTY_DISCOUNT_INC_SHIPPING',
            'MODULE_LOYALTY_DISCOUNT_INC_TAX',
            'MODULE_LOYALTY_DISCOUNT_CALC_TAX',
            'MODULE_LOYALTY_DISCOUNT_ORDER_STATUS',
        ];
    }

    public function install()
    {
        global $db;

        $db->Execute(
            "INSERT INTO " . TABLE_CONFIGURATION . "
                (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added)
             VALUES
                ('Enable Discount?', 'MODULE_LOYALTY_DISCOUNT_STATUS', 'true', 'Do you want to enable the Loyalty Discount?', 6, 1, 'zen_cfg_select_option([\'true\', \'false\'], ', now()),

                ('Sort Order', 'MODULE_LOYALTY_DISCOUNT_SORT_ORDER', '998', 'Sort order of display.', 6, 2, NULL, now()),

                ('Include Shipping', 'MODULE_LOYALTY_DISCOUNT_INC_SHIPPING', 'true', 'Include Shipping in calculation', 6, 3, 'zen_cfg_select_option([\'true\', \'false\'], ', now()),

                ('Include Tax', 'MODULE_LOYALTY_DISCOUNT_INC_TAX', 'true', 'Include Tax in calculation.', 6, 4, 'zen_cfg_select_option([\'true\', \'false\'], ', now()),

                ('Calculate Tax', 'MODULE_LOYALTY_DISCOUNT_CALC_TAX', 'false', 'Re-calculate Tax on discounted amount.', 6, 5, 'zen_cfg_select_option([\'true\', \'false\'], ', now()),

                ('Cumulative Order Period', 'MODULE_LOYALTY_DISCOUNT_CUMORDER_PERIOD', 'year', 'Set the period over which to calculate the cumulative order total.', 6, 6, 'zen_cfg_select_option([\'alltime\', \'year\', \'quarter\', \'month\'], ', now()),

                ('Discount Percentage', 'MODULE_LOYALTY_DISCOUNT_TABLE', '1000:5,1500:7.5,2000:10', 'Set the cumulative order total breaks for the period set above and discount percentages.<br><br>The default value (<code>1000:5,1500:7.5,2000:10</code>) gives the customer:<ol><li>A 5% discount for a total &gt; 1000.</li><li>A 7.5% discount for a total &gt; 1500.</li><li>A 10% discount for a total &gt; 2000.</li></ol>', 6, 7, NULL, now()),

                ('Order Status', 'MODULE_LOYALTY_DISCOUNT_ORDER_STATUS', '3', 'Set the minimum order status for an order to add it to the total amount ordered.', 6, 8, NULL, now())"
        );
    }

    public function remove()
    {
        global $db;

        $db->Execute(
            "DELETE FROM " . TABLE_CONFIGURATION . "
              WHERE configuration_key in ('" . implode("', '", $this->keys()) . "')"
        );
    }
}
