<?php

/**
 * @author     Albert Fatsini - pagamastarde.com
 * @date       : 23.11.2015
 *
 * @copyright  Copyright (C) 2015 - 2015 pagamastarde.com . All rights reserved.
 * @license    GNU General Public License version 2 or later; see LICENSE
 */

defined ('_JEXEC') or die('Restricted access');

/**
 * @version: Pagamastarde 1.1.2
 */
if (!class_exists ('vmPSPlugin')) {
    require(JPATH_VM_PLUGINS . DIRECTORY_SEPARATOR . 'vmpsplugin.php');
}

class plgVmPaymentPagamastarde extends vmPSPlugin {

    const PAGAMASTARDE_URL = "https://pmt.pagantis.com/v1/installments";
    //Only EUR is allowed
    const PAGAMASTARDE_CURRENCY = "EUR";

    /** @const */
    private static $lang_codes = array("ca","en","es","eu","fr","gl","it","pl","ru");

    function __construct (& $subject, $config) {

        parent::__construct ($subject, $config);

        $this->_loggable = TRUE;
        $this->tableFields = array_keys ($this->getTableSQLFields ());
        $this->_tablepkey = 'id';
        $this->_tableId = 'id';
        $varsToPush = $this->getVarsToPush ();
        $this->setConfigParameterable ($this->_configTableFieldName, $varsToPush);

    }

    /**
     * Create the table for this plugin if it does not yet exist.
     */
    public function getVmPluginCreateTableSQL () {

        return $this->createTableSQL ('Payment PagaMasTarde Table');
    }

    /**
     * Payment table fields
     *
     * @return string SQL Fileds
     */
    function getTableSQLFields () {

        $SQLfields = array(
            'id'                          => 'int(1) UNSIGNED NOT NULL AUTO_INCREMENT',
            'virtuemart_order_id'         => 'int(1) UNSIGNED',
            'order_number'                => 'char(64)',
            'virtuemart_paymentmethod_id' => 'mediumint(1) UNSIGNED',
            'payment_name'                => 'varchar(5000)',
            'payment_order_total'         => 'decimal(15,5) NOT NULL DEFAULT \'0.00000\'',
            'payment_currency'            => 'char(3)',
            'email_currency'              => 'char(3)',
            'cost_per_transaction'        => 'decimal(10,2)',
            'cost_percent_total'          => 'decimal(10,2)',
            'tax_id'                      => 'smallint(1)'
        );

        return $SQLfields;
    }

    /**
     * Form Creation
     */
    function plgVmConfirmedOrder ($cart, $order) {
        if (!($method = $this->getVmPluginMethod ($order['details']['BT']->virtuemart_paymentmethod_id))) {
            return NULL;
        }
        if (!$this->selectedThisElement ($method->payment_element)) {
            return FALSE;
        }

        $lang = JFactory::getLanguage ();
        $filename = 'com_virtuemart';
        $lang->load ($filename, JPATH_ADMINISTRATOR);

        if (!class_exists ('VirtueMartModelOrders')) {
            require(JPATH_VM_ADMINISTRATOR . DIRECTORY_SEPARATOR . 'models' . DIRECTORY_SEPARATOR . 'orders.php');
        }
        if (!class_exists ('VirtueMartModelCurrency')) {
            require(JPATH_VM_ADMINISTRATOR . DIRECTORY_SEPARATOR . 'models' . DIRECTORY_SEPARATOR . 'currency.php');
        }

        $jinput = JFactory::getApplication()->input;

        //Account Settings
        $environment = $method->pagamastarde_env;
        $eom = $method->pagamastarde_eom;
        $discount = $method->pagamastarde_discount;

        if($environment == 'test'){
            $account_id = $method->pagamastarde_test_account;
            $account_key = $method->pagamastarde_test_key;
        }else{
            $account_id = $method->pagamastarde_real_account;
            $account_key = $method->pagamastarde_real_key;
        }

        //Callback urls
        $url_ok = JURI::root () . 'index.php?option=com_virtuemart'.
        '&view=pluginresponse'.
        '&task=pluginresponsereceived'.
        '&vmethod=pagantis'.
        '&status=ok'.
        '&on=' .$order['details']['BT']->order_number .
        '&pm=' .$order['details']['BT']->virtuemart_paymentmethod_id .
        '&Itemid=' . $jinput->get('Itemid','',INT) .
        '&lang='. $jinput->get('lang','',CMD);

        $url_ko = JURI::root () . 'index.php?option=com_virtuemart'.
        '&view=pluginresponse'.
        '&task=pluginresponsereceived'.
        '&vmethod=pagantis'.
        '&status=ko'.
        '&on=' .$order['details']['BT']->order_number .
            '&pm=' .$order['details']['BT']->virtuemart_paymentmethod_id .
            '&Itemid=' .  $jinput->get('Itemid','',INT) .
            '&lang='. $jinput->get('lang','',CMD);

        $callback_url=JURI::root () . 'index.php?option=com_virtuemart'.
        '&view=pluginresponse'.
        '&task=pluginresponsereceived'.
        '&vmethod=pagantis'.
        '&pm=' .$order['details']['BT']->virtuemart_paymentmethod_id ;
        //Order ID
        $order_id = strval($order['details']['BT']->order_number);

        //Order email & Full name
        $customer_email = $order['details']['BT']->email;
        $customer_name = $order['details']['BT']->first_name.' '.$order['details']['BT']->last_name;

        //Order Amount
        //Precio del pedido
        $order_amount = number_format((float)($order['details']['BT']->order_total), 2, '.', '' );
        $order_amount = str_replace('.','',$order_amount);
        $order_amount = floatval($order_amount);

        //Currency
        $currency = self::PAGAMASTARDE_CURRENCY;

        //Lang code
        $lang_site = substr($_SERVER["HTTP_ACCEPT_LANGUAGE"],0,2);
        if(!in_array($lang_site,self::$lang_codes)) $lang_code = "en";
        else $lang_code = $lang_site;

        //address
        $address = $order['details']['BT']->address_1. " ". $order['details']['BT']->address_2;
        $city = $order['details']['BT']->city;
        $country = shopfunctions::getCountryByID($order['details']['BT']->virtuemart_country_id,'country_name');
        $state = shopfunctions::getStateByID($order['details']['BT']->virtuemart_state_id,'state_name');
        $zip = $order['details']['BT']->zip;

        //phone
        $phone = !empty($order['details']['BT']->phone_2)?$order['details']['BT']->phone_2:$order['details']['BT']->phone_1;


        $this->log("Creating Form");
        $this->log("OrderID:".$order_id);


        //Signature
        $signature = sha1($account_key.$account_id.$order_id.$order_amount.$currency.$url_ok.$url_ko.$callback_url.$discount);

        //Order description
        $description = 'OrderID: '.$order_id;

        //shippment and tax
        $products = '';
        $i = 1;
        $products .= '<input name="items['.$i.'][description]" type="hidden" value="Gastos de envío">';
        $products .= '<input name="items['.$i.'][quantity]" type="hidden" value="1">';
        $products .= '<input name="items['.$i.'][amount]" type="hidden" value="'.round($order['details']['BT']->order_shipment+$order['details']['BT']->order_shipment_tax,2).'">';
        $i++;
        /* tax included in the item price
        $products .= '<input name="items['.$i.'][description]" type="hidden" value="Impuestos">';
        $products .= '<input name="items['.$i.'][quantity]" type="hidden" value="1">';
        $products .= '<input name="items['.$i.'][amount]" type="hidden" value="'.number_format($order['details']['BT']->order_billTaxAmount,2).'">';
        $i++;
        */


        //Products description
        foreach ($cart->products as $product) {
              $products .= '<input name="items['.$i.'][description]" type="hidden" value="'.$product->product_name.' ('.$product->quantity.')">';
              $products .= '<input name="items['.$i.'][quantity]" type="hidden" value="'.$product->quantity.'">';
              $products .= '<input name="items['.$i.'][amount]" type="hidden" value="'.round($product->prices['salesPrice']*$product->quantity,2).'">';
              $i++;
        }
        //HTML necesary to send Paga+Tarde Request
        $form = '<html><head><title>Redirección Paga+Tarde</title></head><body><div style="margin: auto; text-align: center;">';
        $form .='
            <form action="'.self::PAGAMASTARDE_URL.'" method="post" name="vm_pagamastarde_form" id="pagamastarde_form">
                <input type="hidden" name="account_id"  value="'.$account_id.'" />
                <input type="hidden" name="currency"    value="'.$currency.'" />
                <input type="hidden" name="ok_url"      value="'.$url_ok.'" />
                <input type="hidden" name="nok_url"     value="'.$url_ko.'" />
                <input type="hidden" name="order_id"    value="'.$order_id.'" />
                <input type="hidden" name="amount"      value="'.$order_amount.'" />
                <input type="hidden" name="signature"   value="'.$signature.'" />
                <input type="hidden" name="description" value="'.$description.'" />
                <input type="hidden" name="locale"      value="'.$lang_code.'" />
                <input type="hidden" name="full_name"   value="'.$customer_name.'">
                <input type="hidden" name="email"       value="'.$customer_email.'">
                <input type="hidden" name="callback_url"       value="'.$callback_url.'">
                <input type="hidden" name="phone"       value="'.$phone.'">
                <input type="hidden" name="address[street]"       value="'.$address.'">
                <input type="hidden" name="address[city]"       value="'.$city.'">
                <input type="hidden" name="address[province]"       value="'.$state.'">
                <input type="hidden" name="address[zipcode]"       value="'.$zip.'">
                <input type="hidden" name="discount[full]"       value="'.$discount.'">
                <input type="hidden" name="end_of_month"       value="'.$eom.'">
            ';
        $form .= $products;
        $form .= '<input type="submit"  value="Si no redirige automáticamente a Paga+Tarde, pulse aquí." />
					<script type="text/javascript">document.vm_pagamastarde_form.submit();
          </script>';
        $form .= '</form></div>';
        $form .= '</body></html>';

        //Se crea el pedido
        $modelOrder = VmModel::getModel ('orders');
        //Status del pedido -> "Pending"
        $order['order_status'] = $this->getNewStatus ($method);
        $order['customer_notified'] = 1;
        $order['comments'] = '';
        $modelOrder->updateStatusForOneOrder ($order['details']['BT']->virtuemart_order_id, $order, TRUE);

        $jinput->set('html', $form);
        return TRUE;

    }

    /*
         *
         * Se genera el status inicial del pedido -> "Pending"
         */
    function getNewStatus ($method) {
        vmInfo (JText::_ ('Paga+Tarde: Pedido en estado "Pending"'));
        if (isset($method->status_pending) and $method->status_pending!="") {
            return $method->status_pending;
        } else {
            // $StatutWhiteList = array('P','C','X','R','S','N');
            return 'P';  //PENDING
            //return 'X';  //CANCELLED
            //return 'R';  //REFUNDED
            //return 'C';  //CONFIRMED
        }
    }

    /**
     * @param $html
     * @return bool|null|string
     */
    function plgVmOnPaymentResponseReceived (&$html) {
      $jinput = JFactory::getApplication()->input;

        if(empty($jinput->get('vmethod')) || !$jinput->get('vmethod') == "pagantis"){
            return NULL;
        }

        if (!class_exists('VirtueMartCart')) {
            require(JPATH_VM_SITE . DIRECTORY_SEPARATOR . 'helpers' . DIRECTORY_SEPARATOR . 'cart.php');
        }
        if (!class_exists('shopFunctionsF')) {
            require(JPATH_VM_SITE . DIRECTORY_SEPARATOR . 'helpers' . DIRECTORY_SEPARATOR . 'shopfunctionsf.php');
        }
        if (!class_exists('VirtueMartModelOrders')) {
            require(JPATH_VM_ADMINISTRATOR . DIRECTORY_SEPARATOR . 'models' . DIRECTORY_SEPARATOR . 'orders.php');
        }

        // Recuperamos Identificador de pedido
        $virtuemart_paymentmethod_id = $jinput->get('pm', 0);
        if (!($method = $this->getVmPluginMethod($virtuemart_paymentmethod_id))) {
            return NULL;
        }
        $json = file_get_contents('php://input');
        $data = json_decode($json, true);

        if (!empty( $data["event"] ) ) {//CallBack URL

            $this->log("Entramos en el CallBack");

            //Account Settings
            $environment = $method->pagamastarde_env;
            if($environment == 'test'){
                $key = $method->pagamastarde_test_key;
            }else{
                $key = $method->pagamastarde_real_key;
            }
            if ($data["event"] == 'charge.created' && !empty($data["data"]["order_id"]))
            {
                $signature_check = sha1($key.$data['account_id'].$data['api_version'].$data['event'].$data['data']['id']);
                if ($signature_check != $data['signature'] ){
                  //hack detected
                  $this->log("Hack detected");
                  exit;
                }
                $virtuemart_order_id = $data["data"]["order_id"];

                $orderModel = VmModel::getModel('orders');
                $order_number = $orderModel->getOrderIdByOrderNumber($virtuemart_order_id);
                $order = $orderModel->getOrder($order_number);
                $order['order_status'] =  "C";
                $order['customer_notified'] = 1;
                $updated = $orderModel->updateStatusForOneOrder ($order['details']['BT']->virtuemart_order_id, $order, TRUE);

                $msg = $updated? "Actualizado pedido ".$order['details']['BT']->virtuemart_order_id." a estado C":"No se ha actualizado el pedido ".$order['details']['BT']->virtuemart_order_id." a estado C";
                $this->log($msg);

                //Se eliminan productos del carrito
                $cart = VirtueMartCart::getCart();
                $cart->emptyCart();
            }
            else if($data["event"] == 'charge.failed' && !empty($data["data"]["order_id"])){

                $virtuemart_order_id = $data["data"]["order_id"];
                $orderModel = VmModel::getModel('orders');
                //Don't lose cart
                $order_number = $orderModel->getOrderIdByOrderNumber($virtuemart_order_id);
                $order = $orderModel->getOrder($order_number);
                $order['order_status'] =  "X";
                $order['customer_notified'] = 1;
                $cart = VirtueMartCart::getCart();
                $cart->emptyCart();
                $orderModel->updateStatusForOneOrder ($order['details']['BT']->virtuemart_order_id, $order, TRUE);
            }
        }
        else {//URL OK Y KO
            $status = $jinput->get("status");

            if (!$this->selectedThisElement($method->payment_element)) {
            return NULL;
            }

            if (!($virtuemart_order_id = VirtueMartModelOrders::getOrderIdByOrderNumber($order_number))) {
                return NULL;
            }

            if ($status == "ok") {
                $this->log("PAGA+TARDE Pedido Number: ".$order_number.", Pedido Id: ".$virtuemart_order_id.' Finalizado correctamente, mostrando pantalla de éxito');
                $html = '<img src="'.JURI::root () .'plugins/vmpayment/pagamastarde/pagamastarde/assets/images/pagamastarde.png" width="225"><br><br><br>';
                $html .= '<h3>El pedido con referencia '.$order_number.' ha finalizado correctamente. Gracias por utilizar Paga+Tarde.</h3>';
                //Flush cart
                $cart = VirtueMartCart::getCart();
                $cart->emptyCart();
            }
            else {
                $this->log("PAGA+TARDE Pedido Number: ".$order_number.", Pedido Id: ".$virtuemart_order_id.' Finalizado con error, mostrando pantalla de error ');
                $html = '<img src="'.JURI::root () .'plugins/vmpayment/pagamastarde/pagamastarde/assets/images/pagamastarde.png" width="225"><br><br><br>';
                $html .='<h3>El pedido con referencia '.$order_number.' ha finalizado con error en la respuesta. Gracias por utilizar Paga+Tarde.</h3>';
                $html .= '<h3>Su carrito no se ha borrado, puede reintentar su compra.</h3>';
            }
        }
        return TRUE;
    }
    //*****************************************************************************************


    /**
     * Display stored payment data for an order
     *
     */
    function plgVmOnShowOrderBEPayment ($virtuemart_order_id, $virtuemart_payment_id) {

        if (!$this->selectedThisByMethodId ($virtuemart_payment_id)) {
            return NULL; // Another method was selected, do nothing
        }

        if (!($paymentTable = $this->getDataByOrderId ($virtuemart_order_id))) {
            return NULL;
        }
        VmConfig::loadJLang('com_virtuemart');

        $html = '<table class="adminlist table">' . "\n";
        $html .= $this->getHtmlHeaderBE ();
        $html .= $this->getHtmlRowBE ('COM_VIRTUEMART_PAYMENT_NAME', $paymentTable->payment_name);
        $html .= $this->getHtmlRowBE ('PAGAMASTARDE_PAYMENT_TOTAL_CURRENCY', $paymentTable->payment_order_total . ' ' . $paymentTable->payment_currency);
        if ($paymentTable->email_currency) {
            $html .= $this->getHtmlRowBE ('PAGAMASTARDE_EMAIL_CURRENCY', $paymentTable->email_currency );
        }
        $html .= '</table>' . "\n";
        return $html;
    }

    /**
     * Check if the payment conditions are fulfilled for this payment method
     *
     *
     *
     * @param $cart_prices: cart prices
     * @param $payment
     * @return true: if the conditions are fulfilled, false otherwise
     *
     */
    protected function checkConditions ($cart, $method, $cart_prices) {

        $this->convert_condition_amount($method);
        $amount = $this->getCartAmount($cart_prices);
        $address = (($cart->ST == 0) ? $cart->BT : $cart->ST);

        $amount_cond = ($amount >= $method->min_amount AND $amount <= $method->max_amount
            OR
            ($method->min_amount <= $amount AND ($method->max_amount == 0)));
        if (!$amount_cond) {
            return FALSE;
        }
        $countries = array();
        if (!empty($method->countries)) {
            if (!is_array ($method->countries)) {
                $countries[0] = $method->countries;
            } else {
                $countries = $method->countries;
            }
        }

        // probably did not gave his BT:ST address
        if (!is_array ($address)) {
            $address = array();
            $address['virtuemart_country_id'] = 0;
        }

        if (!isset($address['virtuemart_country_id'])) {
            $address['virtuemart_country_id'] = 0;
        }
        if (count ($countries) == 0 || in_array ($address['virtuemart_country_id'], $countries) ) {
            return TRUE;
        }

        return FALSE;
    }


    /*
    * We must reimplement this triggers for joomla 1.7
    */

    /**
     * Create the table for this plugin if it does not yet exist.
     * This functions checks if the called plugin is active one.
     * When yes it is calling the pagamastarde method to create the tables
     */
    function plgVmOnStoreInstallPaymentPluginTable ($jplugin_id) {

        return $this->onStoreInstallPluginTable ($jplugin_id);
    }

    /**
     * This event is fired after the payment method has been selected. It can be used to store
     * additional payment info in the cart.
     * @param VirtueMartCart $cart: the actual cart
     * @return null if the payment was not selected, true if the data is valid, error message if the data is not valid
     */
    public function plgVmOnSelectCheckPayment (VirtueMartCart $cart, &$msg) {

        return $this->OnSelectCheck ($cart);
    }

    /**
     * plgVmDisplayListFEPayment
     * This event is fired to display the pluginmethods in the cart (edit shipment/payment) for exampel
     *
     * @param object  $cart Cart object
     * @param integer $selected ID of the method selected
     * @return boolean True on succes, false on failures, null when this plugin was not selected.
     * On errors, application->enqueueMessages() must be used to set a message.
     */
    public function plgVmDisplayListFEPayment (VirtueMartCart $cart, $selected = 0, &$htmlIn) {

        return $this->displayListFE ($cart, $selected, $htmlIn);
    }

    /*
    * plgVmonSelectedCalculatePricePayment
    * Calculate the price (value, tax_id) of the selected method
    * It is called by the calculator
    * This function does NOT to be reimplemented. If not reimplemented, then the default values from this function are taken.
    *
    * @cart: VirtueMartCart the current cart
    * @cart_prices: array the new cart prices
    * @return null if the method was not selected, false if the shiiping rate is not valid any more, true otherwise
    */

    public function plgVmonSelectedCalculatePricePayment (VirtueMartCart $cart, array &$cart_prices, &$cart_prices_name) {

        return $this->onSelectedCalculatePrice ($cart, $cart_prices, $cart_prices_name);
    }

    function plgVmgetPaymentCurrency ($virtuemart_paymentmethod_id, &$paymentCurrencyId) {

        if (!($method = $this->getVmPluginMethod ($virtuemart_paymentmethod_id))) {
            return NULL; // Another method was selected, do nothing
        }
        if (!$this->selectedThisElement ($method->payment_element)) {
            return FALSE;
        }
        $this->getPaymentCurrency ($method);

        $paymentCurrencyId = $method->payment_currency;
        return;
    }

    /**
     * plgVmOnCheckAutomaticSelectedPayment
     * Checks how many plugins are available. If only one, the user will not have the choice. Enter edit_xxx page
     * The plugin must check first if it is the correct type
     *
     * @param VirtueMartCart cart: the cart object
     * @return null if no plugin was found, 0 if more then one plugin was found,  virtuemart_xxx_id if only one plugin is found
     */
    function plgVmOnCheckAutomaticSelectedPayment (VirtueMartCart $cart, array $cart_prices = array(), &$paymentCounter) {

        return $this->onCheckAutomaticSelected ($cart, $cart_prices, $paymentCounter);
    }

    /**
     * This method is fired when showing the order details in the frontend.
     * It displays the method-specific data.
     *
     * @param integer $order_id The order ID
     * @return mixed Null for methods that aren't active, text (HTML) otherwise
     */
    public function plgVmOnShowOrderFEPayment ($virtuemart_order_id, $virtuemart_paymentmethod_id, &$payment_name) {

        $this->onShowOrderFE ($virtuemart_order_id, $virtuemart_paymentmethod_id, $payment_name);
    }

    /* TODO ELIMINAR */
    function log($text) {
            // Log
        $logfilename = 'logs/pagamastarde-log.log';
        $fp = @fopen($logfilename, 'a');
        if ($fp) {
            fwrite($fp, date('M d Y G:i:s') . ' -- ' . $text . "\r\n");
            fclose($fp);
        }
    }

    /**
     * @param $orderDetails
     * @param $data
     * @return null
     */
    function plgVmOnUserInvoice ($orderDetails, &$data) {

        if (!($method = $this->getVmPluginMethod ($orderDetails['virtuemart_paymentmethod_id']))) {
            return NULL; // Another method was selected, do nothing
        }
        if (!$this->selectedThisElement ($method->payment_element)) {
            return NULL;
        }
        //vmdebug('plgVmOnUserInvoice',$orderDetails, $method);

        if (!isset($method->send_invoice_on_order_null) or $method->send_invoice_on_order_null==1 or $orderDetails['order_total'] > 0.00){
            return NULL;
        }

        if ($orderDetails['order_salesPrice']==0.00) {
            $data['invoice_number'] = 'reservedByPayment_' . $orderDetails['order_number']; // Nerver send the invoice via email
        }

    }
    /**
     * @param $virtuemart_paymentmethod_id
     * @param $paymentCurrencyId
     * @return bool|null
     */
    function plgVmgetEmailCurrency($virtuemart_paymentmethod_id, $virtuemart_order_id, &$emailCurrencyId) {

        if (!($method = $this->getVmPluginMethod($virtuemart_paymentmethod_id))) {
            return NULL; // Another method was selected, do nothing
        }
        if (!$this->selectedThisElement($method->payment_element)) {
            return FALSE;
        }
        if (!($payments = $this->getDatasByOrderId($virtuemart_order_id))) {

            return '';
        }
        if (empty($payments[0]->email_currency)) {
            $vendorId = 1; //VirtueMartModelVendor::getLoggedVendor();
            $db = JFactory::getDBO();
            $q = 'SELECT vendor_currency FROM #__virtuemart_vendors WHERE virtuemart_vendor_id=' . $vendorId;
            $db->setQuery($q);
            $emailCurrencyId = $db->loadResult();
        } else {
            $emailCurrencyId = $payments[0]->email_currency;
        }

    }
    /**
     * This event is fired during the checkout process. It can be used to validate the
     * method data as entered by the user.
     *
     * @return boolean True when the data was valid, false otherwise. If the plugin is not activated, it should return null.
     *

    public function plgVmOnCheckoutCheckDataPayment(  VirtueMartCart $cart) {
    return null;
    }
     */

    /**
     * This method is fired when showing when priting an Order
     * It displays the the payment method-specific data.
     *
     * @param integer $_virtuemart_order_id The order ID
     * @param integer $method_id  method used for this order
     * @return mixed Null when for payment methods that were not selected, text (HTML) otherwise
     *
     */
    function plgVmonShowOrderPrintPayment ($order_number, $method_id) {

        return $this->onShowOrderPrint ($order_number, $method_id);
    }

    function plgVmDeclarePluginParamsPaymentVM3( &$data) {
        return $this->declarePluginParams('payment', $data);
    }
    function plgVmSetOnTablePluginParamsPayment ($name, $id, &$table) {

        return $this->setOnTablePluginParams ($name, $id, $table);
    }
}


$document = JFactory::getDocument();
$document->addScript(JURI::root().'/plugins/vmpayment/pagamastarde/js/widget.js');
