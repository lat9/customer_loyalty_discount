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
class ot_loyalty_discount {

    public $title, $output;

    public function __construct() {
        $this->code = 'ot_loyalty_discount';
        $this->title = MODULE_LOYALTY_DISCOUNT_TITLE;
        $this->description = MODULE_LOYALTY_DISCOUNT_DESCRIPTION;
        $this->enabled = (defined('MODULE_LOYALTY_DISCOUNT_STATUS') && MODULE_LOYALTY_DISCOUNT_STATUS == 'true'); 
        $this->sort_order = defined('MODULE_LOYALTY_DISCOUNT_SORT_ORDER') ? MODULE_LOYALTY_DISCOUNT_SORT_ORDER : null;
        if (null === $this->sort_order) return false;
        $this->include_shipping = MODULE_LOYALTY_DISCOUNT_INC_SHIPPING;
        $this->include_tax = MODULE_LOYALTY_DISCOUNT_INC_TAX;
        $this->calculate_tax = MODULE_LOYALTY_DISCOUNT_CALC_TAX;
        $this->table = MODULE_LOYALTY_DISCOUNT_TABLE;
        $this->loyalty_order_status = MODULE_LOYALTY_DISCOUNT_ORDER_STATUS;
        $this->cum_order_period = MODULE_LOYALTY_DISCOUNT_CUMORDER_PERIOD;
        $this->output = array();
    }

    public function process() {
        global $order, $ot_subtotal, $currencies;
        $od_amount = $this->calculate_credit($this->get_order_total(), $this->get_cum_order_total());
        if ($od_amount > 0) {
            $this->deduction = $od_amount;

            $tmp = '' . sprintf(MODULE_LOYALTY_DISCOUNT_INFO, $this->period_string, $currencies->format($this->cum_order_total), $this->od_pc . '%') . '';
            $this->output[] = array('title' => '<div class="ot_loyality_title">' . $this->title . ':</div>' . $tmp,
                'text' => $currencies->format($od_amount),
                'value' => $od_amount);
            $order->info['total'] = $order->info['total'] - $od_amount;
            if ($this->sort_order < $ot_subtotal->sort_order) {
                $order->info['subtotal'] = $order->info['subtotal'] - $od_amount;
            }
        }
    }

    public function calculate_credit($amount_order, $amount_cum_order) {
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
        if ($this->calculate_tax == 'true') {
            // Calculate main tax reduction
            $tod_amount = round($order->info['tax'] * 10) / 10;
            $todx_amount = $tod_amount * ((float) $od_pc / 100);
            $order->info['tax'] = $order->info['tax'] - $todx_amount;
            // Calculate tax group deductions
            reset($order->info['tax_groups']);
            foreach ($order->info['tax_groups'] as $key => $value) {
                $god_amount = round($value * 10) / 10 * $od_pc / 100;
                $order->info['tax_groups'][$key] = $order->info['tax_groups'][$key] - $god_amount;
            }
        }
        $od_amount = (round((float) $amount_order * 10) / 10) * ($od_pc / 100);
        $od_amount = $od_amount + $todx_amount;
        return $od_amount;
    }

    public function get_order_total() {
        global $order, $db, $cart;
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
        if ($this->include_tax == 'false') {
            $order_total = $order_total - $order->info['tax'];
        }
        if ($this->include_shipping == 'false') {
            $order_total = $order_total - $order->info['shipping_cost'];
        }
        return $order_total;
    }

    public function get_cum_order_total() {
        global $db;
        $customer_id = $_SESSION['customer_id'];
        $history_query_raw = 'select o.date_purchased, ot.value as order_total from ' . TABLE_ORDERS . ' o left join ' . TABLE_ORDERS_TOTAL . " ot on (o.orders_id = ot.orders_id) where o.customers_id = '" . $customer_id . "' and ot.class = 'ot_total' and o.orders_status >= '" . $this->loyalty_order_status . "' order by date_purchased DESC";
        $history_query = $db->Execute($history_query_raw);
        if ($history_query->RecordCount() > 0) {
            $cum_order_total = 0;
            $cutoff_date = $this->get_cutoff_date();
            while (!$history_query->EOF) {
                if ($this->get_date_in_period($cutoff_date, $history_query->fields['date_purchased']) == true) {
                    $cum_order_total = $cum_order_total + $history_query->fields['order_total'];
                }
                $history_query->MoveNext();
            }
            $this->cum_order_total = $cum_order_total;
            return $cum_order_total;
        } else {
            $cum_order_total = 0;
            $this->cum_order_total = $cum_order_total;
            return $cum_order_total;
        }
    }

    public function get_cutoff_date() {
        $rightnow = time();
        switch ($this->cum_order_period) {
            case 'alltime':
                $this->period_string = MODULE_LOYALTY_DISCOUNT_WITHUS;
                $cutoff_date = 0;
                break;
            case 'year':
                $this->period_string = MODULE_LOYALTY_DISCOUNT_YEAR;
                $cutoff_date = $rightnow - (60 * 60 * 24 * 365);
                break;
            case 'quarter':
                $this->period_string = MODULE_LOYALTY_DISCOUNT_QUARTER;
                $cutoff_date = $rightnow - (60 * 60 * 24 * 92);
                break;
            case 'month':
                $this->period_string = MODULE_LOYALTY_DISCOUNT_MONTH;
                $cutoff_date = $rightnow - (60 * 60 * 24 * 31);
                break;
            default:
                $cutoff_date = $rightnow;
        }
        return $cutoff_date;
    }

    public function get_date_in_period($cutoff_date, $raw_date) {
        if (($raw_date == '0000-00-00 00:00:00') || ($raw_date == '')) {
            return false;
        }

        $year = (int) substr($raw_date, 0, 4);
        $month = (int) substr($raw_date, 5, 2);
        $day = (int) substr($raw_date, 8, 2);
        $hour = (int) substr($raw_date, 11, 2);
        $minute = (int) substr($raw_date, 14, 2);
        $second = (int) substr($raw_date, 17, 2);

        $order_date_purchased = mktime($hour, $minute, $second, $month, $day, $year);
        return $order_date_purchased >= $cutoff_date;
    }

    public function check() {
        global $db;
        if (!isset($this->_check)) {
            $check_query = $db->Execute('select configuration_value from ' . TABLE_CONFIGURATION . " where configuration_key = 'MODULE_LOYALTY_DISCOUNT_STATUS'");
            $this->_check = $check_query->RecordCount();
        }

        return $this->_check;
    }

    public function keys() {
        return array('MODULE_LOYALTY_DISCOUNT_STATUS', 'MODULE_LOYALTY_DISCOUNT_SORT_ORDER', 'MODULE_LOYALTY_DISCOUNT_CUMORDER_PERIOD', 'MODULE_LOYALTY_DISCOUNT_TABLE', 'MODULE_LOYALTY_DISCOUNT_INC_SHIPPING', 'MODULE_LOYALTY_DISCOUNT_INC_TAX', 'MODULE_LOYALTY_DISCOUNT_CALC_TAX', 'MODULE_LOYALTY_DISCOUNT_ORDER_STATUS');
    }

    public function install() {
        global $db;
        $db->Execute('insert into ' . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) values ('Display Total', 'MODULE_LOYALTY_DISCOUNT_STATUS', 'true', 'Do you want to enable the Order Discount?', '6', '1','zen_cfg_select_option(array(\'true\', \'false\'), ', now())");
        $db->Execute('insert into ' . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values ('Sort Order', 'MODULE_LOYALTY_DISCOUNT_SORT_ORDER', '998', 'Sort order of display.', '6', '2', now())");
        $db->Execute('insert into ' . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function ,date_added) values ('Include Shipping', 'MODULE_LOYALTY_DISCOUNT_INC_SHIPPING', 'true', 'Include Shipping in calculation', '6', '3', 'zen_cfg_select_option(array(\'true\', \'false\'), ', now())");
        $db->Execute('insert into ' . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function ,date_added) values ('Include Tax', 'MODULE_LOYALTY_DISCOUNT_INC_TAX', 'true', 'Include Tax in calculation.', '6', '4','zen_cfg_select_option(array(\'true\', \'false\'), ', now())");
        $db->Execute('insert into ' . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function ,date_added) values ('Calculate Tax', 'MODULE_LOYALTY_DISCOUNT_CALC_TAX', 'false', 'Re-calculate Tax on discounted amount.', '6', '5','zen_cfg_select_option(array(\'true\', \'false\'), ', now())");
        $db->Execute('insert into ' . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function ,date_added) values ('Cumulative order total period', 'MODULE_LOYALTY_DISCOUNT_CUMORDER_PERIOD', 'year', 'Set the period over which to calculate cumulative order total.', '6', '6','zen_cfg_select_option(array(\'alltime\', \'year\', \'quarter\', \'month\'), ', now())");
        $db->Execute('insert into ' . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values ('Discount Percentage', 'MODULE_LOYALTY_DISCOUNT_TABLE', '1000:5,1500:7.5,2000:10,3000:12.5,5000:15', 'Set the cumulative order total breaks per period set above, and discount percentages. <br /><br />For example, in admin you have set the pre-defined rolling period to a month, and set up a table of discounts that gives 5.0% discount if they have spent over \$1000 in the previous month (i.e previous 31 days, not calendar month), or 7.5% if they have spent over \$1500 in the previous month.<br />', '6', '7', now())");
        $db->Execute('insert into ' . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values ('Order Status', 'MODULE_LOYALTY_DISCOUNT_ORDER_STATUS', '3', 'Set the minimum order status for an order to add it to the total amount ordered', '6', '8', now())");
    }

    public function remove() {
        global $db;
        $db->Execute('delete from ' . TABLE_CONFIGURATION . " where configuration_key in ('" . implode("', '", $this->keys()) . "')");
    }

}