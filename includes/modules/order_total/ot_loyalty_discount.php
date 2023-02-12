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
        $sort_order
        $output;

    protected
        $_check,
        $include_shipping,
        $include_tax,
        $calculate_tax,
        $table,
        $loyalty_order_status,
        $od_pc,
        $deduction,
        $period_string,
        $cum_order_total,
        $cum_order_period;

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
        $this->include_shipping = MODULE_LOYALTY_DISCOUNT_INC_SHIPPING;
        $this->include_tax = MODULE_LOYALTY_DISCOUNT_INC_TAX;
        $this->calculate_tax = MODULE_LOYALTY_DISCOUNT_CALC_TAX;
        $this->table = MODULE_LOYALTY_DISCOUNT_TABLE;
        $this->loyalty_order_status = MODULE_LOYALTY_DISCOUNT_ORDER_STATUS;
        $this->cum_order_period = MODULE_LOYALTY_DISCOUNT_CUMORDER_PERIOD;
        $this->output = [];
    }

    public function process()
    {
        global $order, $ot_subtotal, $currencies;

        if ($this->enabled === false) {
            return;
        }

        $od_amount = $this->calculate_credit($this->get_order_total(), $this->get_cum_order_total());
        if ($od_amount > 0) {
            $this->deduction = $od_amount;

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
        $table_cost_group = explode(',', MODULE_LOYALTY_DISCOUNT_TABLE);
        foreach ($table_cost_group as $loyalty_group) {
            $group_loyalty = explode(':', $loyalty_group);
            if ($amount_cum_order >= $group_loyalty[0]) {
                $od_pc = (float) $group_loyalty[1];
                $this->od_pc = $od_pc;
            }
        }
        // Calculate tax reduction if necessary
        if ($this->calculate_tax === 'true') {
            // Calculate main tax reduction
            $tod_amount = round($order->info['tax'] * 10) / 10;
            $todx_amount = $tod_amount * ((float) $od_pc / 100);
            $order->info['tax'] -= $todx_amount;
            // Calculate tax group deductions
            reset($order->info['tax_groups']);
            foreach ($order->info['tax_groups'] as $key => $value) {
                $god_amount = round($value * 10) / 10 * $od_pc / 100;
                $order->info['tax_groups'][$key] -= $god_amount;
            }
        }
        $od_amount = (round((float) $amount_order * 10) / 10) * ($od_pc / 100);
        $od_amount += $todx_amount;
        return $od_amount;
    }

    public function get_order_total()
    {
        global $order, $db;

        $order_total = $order->info['total'];
        $order_total_tax = $order->info['tax'];
        // Check if gift voucher is in cart and adjust total
        $products = $_SESSION['cart']->get_products();
        for ($i = 0, $iMax = count($products); $i < $iMax; $i++) {
            $t_prid = zen_get_prid($products[$i]['id']);
            $gv_query = $db->Execute('select products_price, products_tax_class_id, products_model from ' . TABLE_PRODUCTS . " where products_id = '" . $t_prid . "'");            
            if (preg_match('/^GIFT/', addslashes($gv_query->fields['products_model']))) {
                $qty = $_SESSION['cart']->get_quantity($t_prid);
                
                $products_tax = zen_get_tax_rate($gv_result['products_tax_class_id']);
                if ($this->include_tax == 'false') {
                    $gv_amount = $gv_result['products_price'] * $qty;
                } else {
                    $gv_amount = ($gv_result['products_price'] + zen_calculate_tax($gv_result['products_price'], $products_tax)) * $qty;
                }
                $order_total = $order_total - $gv_amount;
            }
        }
        $orderTotalFull = $order_total;
        if ($this->include_tax === 'false') {
            $order_total = $order_total - $order->info['tax'];
        }
        if ($this->include_shipping === 'false') {
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
        $cum_order_total = 0;
        if (!$history_query->EOF) {
            $cutoff_date = $this->get_cutoff_date();
            foreach ($history_query as $next_order) {
                if ($this->get_date_in_period($cutoff_date, $next_order['date_purchased']) === true) {
                    $cum_order_total += $next_order['order_total'];
                }
            }
        }

        $this->cum_order_total = $cum_order_total;
        return $cum_order_total;
    }

    protected function get_cutoff_date()
    {
        switch ($this->cum_order_period) {
            case 'year':
                $this->period_string = MODULE_LOYALTY_DISCOUNT_YEAR;
                $cutoff_date = strtotime('-1 year');
                break;

            case 'quarter':
                $this->period_string = MODULE_LOYALTY_DISCOUNT_QUARTER;
                $cutoff_date = strtotime('-3 month');
                break;

            case 'month':
                $this->period_string = MODULE_LOYALTY_DISCOUNT_MONTH;
                $cutoff_date = strtotime('-1 month');
                break;

            case 'alltime':
            default:
                $this->period_string = MODULE_LOYALTY_DISCOUNT_WITHUS;
                $cutoff_date = 0;
                break;
        }
        return $cutoff_date;
    }

    protected function get_date_in_period($cutoff_date, $raw_date)
    {
        if ($raw_date === '0000-00-00 00:00:00' || empty($raw_date)) {
            return false;
        }

        $order_date_purchased = strtotime($raw_date);
        return $order_date_purchased >= $cutoff_date;
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
            'MODULE_LOYALTY_DISCOUNT_ORDER_STATUS'
        ];
    }

    public function install()
    {
        global $db;

        $db->Execute('INSERT INTO ' . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) values ('Display Total', 'MODULE_LOYALTY_DISCOUNT_STATUS', 'true', 'Do you want to enable the Order Discount?', 6, 1, 'zen_cfg_select_option([\'true\', \'false\'], ', now())");

        $db->Execute('INSERT INTO ' . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values ('Sort Order', 'MODULE_LOYALTY_DISCOUNT_SORT_ORDER', '998', 'Sort order of display.', 6, 2, now())");
        
        $db->Execute('INSERT INTO ' . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function ,date_added) values ('Include Shipping', 'MODULE_LOYALTY_DISCOUNT_INC_SHIPPING', 'true', 'Include Shipping in calculation', 6, 3, 'zen_cfg_select_option([\'true\', \'false\'], ', now())");

        $db->Execute('INSERT INTO ' . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function ,date_added) values ('Include Tax', 'MODULE_LOYALTY_DISCOUNT_INC_TAX', 'true', 'Include Tax in calculation.', 6, 4, 'zen_cfg_select_option([\'true\', \'false\'], ', now())");

        $db->Execute('INSERT INTO ' . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function ,date_added) values ('Calculate Tax', 'MODULE_LOYALTY_DISCOUNT_CALC_TAX', 'false', 'Re-calculate Tax on discounted amount.', 6, 5, 'zen_cfg_select_option([\'true\', \'false\'], ', now())");

        $db->Execute('INSERT INTO ' . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function ,date_added) values ('Cumulative order total period', 'MODULE_LOYALTY_DISCOUNT_CUMORDER_PERIOD', 'year', 'Set the period over which to calculate cumulative order total.', 6, 6, 'zen_cfg_select_option([\'alltime\', \'year\', \'quarter\', \'month\'], ', now())");

        $db->Execute('INSERT INTO ' . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values ('Discount Percentage', 'MODULE_LOYALTY_DISCOUNT_TABLE', '1000:5,1500:7.5,2000:10,3000:12.5,5000:15', 'Set the cumulative order total breaks per period set above, and discount percentages. <br><br>For example, in admin you have set the pre-defined rolling period to a month, and set up a table of discounts that gives 5.0% discount if they have spent over \$1000 in the previous month (i.e previous 31 days, not calendar month), or 7.5% if they have spent over \$1500 in the previous month.<br>', 6, 7, now())");

        $db->Execute('INSERT INTO ' . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values ('Order Status', 'MODULE_LOYALTY_DISCOUNT_ORDER_STATUS', '3', 'Set the minimum order status for an order to add it to the total amount ordered', 6, 8, now())");
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
