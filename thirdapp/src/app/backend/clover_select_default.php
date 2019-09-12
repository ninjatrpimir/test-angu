<?php

// public function getAllCloverData () {

    require_once 'CloverMulti.class.php';
    require_once 'Clover.class.php';

    error_reporting(0);
    set_error_handler(array('CloverMulti', 'errorHandler'));
    set_exception_handler(array('CloverMulti', 'exceptionHandler'));

    if(session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    if(is_bool($test)) {
        $testBool = $test;
    } else {
        $testBool = ($test == "false") ? false : true;
    }
    CloverMulti::setTesting($testBool);

    $cloverMulti = new CloverMulti;

    $cloverMulti->setConfig($cloverType_select, $client_id, $data);
    $cloverMulti->setMerchantMultiLocation($data->merchant_id, $data->employee_id, $data->filter_merchant);
    $cloverMulti->initializeCache();

    $newOrders = array();
    $payments = array();
    $reportsPayments = array();
    $reportsItems = array();
    $items = array();
    $groupedResult = array();

    $cloverMulti->setMerchantData();
    $cloverMulti->checkSession();
    $cloverMulti->getOrSetSessionData();
    $cloverMulti->run($newOrders, $payments, $reportsPayments, $reportsItems, $items, $groupedResult);

    $returnArrays = array();
    $cloverMulti->prepareReturnData($returnArrays, $newOrders, $payments, $reportsPayments, $reportsItems, $items, $groupedResult);

    $newOrders = null;
    $reportsPayments = null;
    $reportsItems = null;
    $items = null;
    $payments = null;
    $groupedResult = null;

    $cloverMulti->clearCachedData();
    CloverMulti::returnData($returnArrays);

    // FIN.
// }