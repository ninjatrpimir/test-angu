<?php

require_once 'CloverCache.class.php';

class CloverMulti
{
    const DB_HOST = DB_HOST;
    const DB_NAME = DB_NAME;
    const DB_USER = DB_USER;
    const DB_PASS = DB_PASS;
    
    const PROPAGATE_ORDER_REFUND = false;
    const API_TYPE_US = 'US';
    const API_TYPE_EU = 'EU';
    const MULTI_CHUNK_FOR_SINGLE_HIGH = 5;
    const MULTI_CHUNK_FOR_SINGLE_MIDDLE = 3;
    const MULTI_CHUNK_FOR_SINGLE_MIDDLE_EU = 2;
    const MULTI_CHUNK_FOR_SINGLE_LOW = 1;
    const MULTI_CHUNK_FOR_MULTI = 5;
    const MULTI_TRY_COUNT = 3;
    const LIMIT = 1000;
    const SESSION_VALID_FOR = 86400; // 24h
    const DAYS_LAPSED_TOLERATE_FOR = 7; // 7 days
    const DATE_FORMAT_ISO = 'Y-m-d';

    const URL_MERCHANT_TEMPLATE = 'https://api.[APISUFFIX]clover.com/v3/apps/[CLIENTID]/merchants/[MERCHANTID]/billing_info?access_token=[TOKEN]';

    static $test = false;
    
    public $apiType;
    public $apiSuffix;
    public $clientId;

    public $merchantIdCaller;
    public $employeeIdCaller;

    /**
     * @var CloverCache
     */
    public $cache;

    /**
     * @var object
     */
    private $callingData;
    private $get = '';
    private $output = '';
    private $reportName = '';
    private $returnCategories = true;
    private $reportStartDate = '';
    private $reportEndDate = '';
    private $getOpenOrders = false;
    private $cacheCustomers = false;
    private $dateFormat = self::DATE_FORMAT_ISO;
    private $groupFields = [];
    private $groupSplitField = '';
    private $groupFilters = [];
    private $groupFieldForLOV = '';
    private $groupDoGrouping = true;
    private $groupDoFilling = false;
    private $groupingInProcess = false;

    private $useSession = true;
    private $cacheIsValid = false;

    /**
     * @var Clover[]
     */
    public $merchants = array();
    public $merchantIds = array();
    public $merchantNames = array();
    public $tokens = array();
    public $timezones = array();
    public $hourShifts = array();
    public $vats = [];
    public $generalSettings = [];
    public $dayParts = [];
    public $hasCustomers = [];

    public $merchantUpgradeToBasic = array();
    public $merchantUpgradeToAdvanced = array();
    public $merchantUpgradeValidUntil = array();

    /**
     * @param string $apiType
     * @param string $clientId
     * @param object $data
     */
    public function setConfig($apiType, $clientId, $data) {
        if($apiType == CloverMulti::API_TYPE_EU) {
            $this->apiType = CloverMulti::API_TYPE_EU;
            $this->apiSuffix = 'eu.';
        } else {
            $this->apiType = CloverMulti::API_TYPE_US;
            $this->apiSuffix = '';
        }

        $this->clientId = $clientId;

        $this->callingData = $data;

        $this->groupingInProcess = strpos($this->callingData->get, 'group') === 0
                                    || strpos($this->callingData->get, 'lov') === 0
                                    || strpos($this->callingData->get, 'raw') === 0 ? true : false;

        // These merchants are upgraded to BASIC or ADVANCED even if they are really FREE or LAPSED
        // These merchants MUST remain in this array because they have a deal with us:
        // za više informacija o merchantima i njihovim popustima na google drive /qualia materijali/ Analytics app for Clover/:
        // link na folder: https://drive.google.com/drive/folders/1qHGgEbqrAvoLoi5cEPR9cQ77a8mDPo7e
        // link na documt: https://docs.google.com/spreadsheets/d/1CIE_jonsxy_-e_BSproYKkpf-MhYQnW-jZFlpm3o7rg/edit#gid=848825431

        //$this->merchantUpgradeToBasic = array('HRXCGD6XFZWEM','6N2KXZYGGFQ4A', 'F8M1FJFWZRVT2','SS9B3KP2KYF9E','KRD1N26WC91J0','FMJYFYTMGPZP2','1CZSE99KAT60G','BMJT9RPSEQYPA');
        //$this->merchantUpgradeToAdvanced = array('78S91N553E14A','2WHDMGSAN40K6');

        $this->merchantUpgradeToBasic = [];
        $this->merchantUpgradeToAdvanced = [];
        $this->merchantUpgradeValidUntil = [];
        $db = new PDO('mysql:host=' . self::DB_HOST . ';dbname=' . self::DB_NAME . ';charset=utf8', self::DB_USER, self::DB_PASS);

        $sql = "DELETE FROM clover_merchant_tier_upgrade WHERE validUntil < DATE(NOW()) AND validUntil IS NOT NULL";
        $stmt = $db->prepare($sql);
        $stmt->execute();

        $sql = "SELECT merchantID, tierName, validUntil FROM clover_merchant_tier_upgrade
                WHERE (validUntil IS NULL OR validUntil >= DATE(NOW())) AND merchantMarket = :market";
        $stmt = $db->prepare($sql);
        $stmt->bindValue(':market', $this->apiType, PDO::PARAM_STR);
        $stmt->execute();
        $upgradedMerchants = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach($upgradedMerchants as $upgradedMerchant) {
            if($upgradedMerchant['tierName'] == 'BASIC') {
                $this->merchantUpgradeToBasic[] = $upgradedMerchant['merchantID'];
            } elseif($upgradedMerchant['tierName'] == 'ADVANCED') {
                $this->merchantUpgradeToAdvanced[] = $upgradedMerchant['merchantID'];
            }

            if($upgradedMerchant['validUntil'] !== null) {
                $this->merchantUpgradeValidUntil[$upgradedMerchant['merchantID']] = strtotime($upgradedMerchant['validUntil']);
            } else {
                $this->merchantUpgradeValidUntil[$upgradedMerchant['merchantID']] = 0;
            }
        }
    }

    /**
     * Based on the calling merchant, it sets up all necessary parameters needed for multi-merchant support: merchant IDs, tokens, timezones and hour shifts.
     *
     * @param string $merchantIdCaller
     * @param string $employeeIdCaller
     * @param array $filterMerchant
     * @param array $filterTier
     * @throws Exception
     */
    public function setMerchantMultiLocation($merchantIdCaller, $employeeIdCaller, $filterMerchant = array(), $filterTier = array()) {
        $this->merchantIdCaller = $merchantIdCaller;
        $this->employeeIdCaller = $employeeIdCaller;

        $db = new PDO('mysql:host=' . self::DB_HOST . ';dbname=' . self::DB_NAME . ';charset=utf8', self::DB_USER, self::DB_PASS);

        // if we are just grouping, a lot of things are not necessary.
        if(!$this->groupingInProcess) {
            // Get all merchants associated with the caller.
            $stmt = $db->prepare("SELECT * FROM clover_multi_location WHERE merchantIDs LIKE :merchantID");
            $stmt->bindValue(':merchantID', '%' . $this->merchantIdCaller . '%', PDO::PARAM_STR);

            if (!$stmt->execute()) {
                throw new Exception('Internal server error (1).');
            }

            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (!empty($results)) {
                $merchantSupervising = json_decode($results[0]['merchantSupervising'], true);
                if (!empty($merchantSupervising)) {
                    if (!empty($merchantSupervising['employees'])) {
                        foreach ($merchantSupervising['employees'] as $employee) {
                            if (!in_array($this->employeeIdCaller, $employee['supervisors'])) {
                                continue;
                            }

                            $merchantIDs = $employee['locations'];
                            break;
                        }
                    }

                    // In case we haven't found the employee as the supervisor.
                    if (!empty($merchantSupervising['merchants']) && empty($merchantIDs)) {
                        foreach ($merchantSupervising['merchants'] as $merchant) {
                            if (!in_array($this->merchantIdCaller, $merchant['supervisors'])) {
                                continue;
                            }

                            $merchantIDs = $merchant['locations'];
                            break;
                        }
                    }
                }
            }

            // If no multi-location setup is found, add calling merchant as the only location.
            if (empty($merchantIDs)) {
                $merchantIDs = array($this->merchantIdCaller);
            }


            // For each merchant, set up their
            $merchant_count = count($merchantIDs);
            $placeholders = array_fill(0, $merchant_count, '?');

            $stmt = $db->prepare("SELECT m.merchantID, m.merchantName, m.tierName, m.merchantTimezone, m.token, m.vat, mp.property_value
						  FROM clover_merchant m LEFT JOIN clover_merchant_properties mp ON m.merchantID = mp.merchant_id AND mp.property_type = 'general'
						  WHERE m.merchantID IN (" . implode(',', $placeholders) . ")");
            for ($i = 1; $i <= $merchant_count; $i++) {
                $stmt->bindValue($i, $merchantIDs[$i - 1], PDO::PARAM_STR);
            }

            if (!$stmt->execute()) {
                throw new Exception('Internal server error (2).');
            }

            $merchants = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Because of 'multi' feature, first check if the caller merchant is FREE.
            $caller_is_free = true;
            foreach ($merchants as &$merchant_ref) {
                // override every merchant's tier.
                if (in_array($merchant_ref['merchantID'], $this->merchantUpgradeToBasic)) {
                    $merchant_ref['tierName'] = 'BASIC';
                } elseif (in_array($merchant_ref['merchantID'], $this->merchantUpgradeToAdvanced)) {
                    $merchant_ref['tierName'] = 'ADVANCED';
                }

                if ($merchant_ref['merchantID'] == $this->merchantIdCaller) {
                    if(!in_array($merchant_ref['tierName'], ['FREE','STARTER'])) {
                        $caller_is_free = false;
                    }
                }
            }

            foreach ($merchants as $merchant) {
                // Filter by merchant ID from frontend.
                if (!empty($filterMerchant) && !in_array($merchant['merchantID'], $filterMerchant)) {
                    continue;
                }

                // First we will check if the merchant is still BASIC and have not downgraded to FREE or have LAPSED in payment.
                // We check all non-free ones. Even the caller.
                if(!in_array($merchant['tierName'], ['FREE','STARTER'])) {
                    $merchantID = $merchant['merchantID'];
                    $now = time();
                    if (!isset($_SESSION[$merchantID]["timestamp"])
                        || $now - $_SESSION[$merchantID]["timestamp"] > Clover::SESSION_VALID_FOR
                        || empty($_SESSION[$merchantID]['merchantInfo'])
                    ) {
                        unset($_SESSION[$merchantID]['merchantInfo']);
                        unset($_SESSION[$merchantID]['timestamp']);

                        $search = array('[APISUFFIX]', '[CLIENTID]', '[MERCHANTID]', '[TOKEN]');
                        $replace = array($this->apiSuffix, $this->clientId, $merchantID, $merchant['token']);
                        $url = str_replace($search, $replace, self::URL_MERCHANT_TEMPLATE);

                        $ch = curl_init($url);
                        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        $returnJSON = curl_exec($ch);
                        $returnJSON = str_replace("&amp;", "&", str_replace("&lt;", "<", str_replace("&gt;", ">", str_replace("&quot;", "'", str_replace("&apos;", "'", $returnJSON)))));
                        $merchantInfo = json_decode($returnJSON);

                        if (((empty($merchantInfo->status) || $merchantInfo->status == 'LAPSED')
                            && (empty($merchantInfo->daysLapsed) || $merchantInfo->daysLapsed >= self::DAYS_LAPSED_TOLERATE_FOR)
                          || $merchantInfo->status == 'INACTIVE')
                        ) {
                            $_SESSION[$merchantID]['merchantInfo']['tierName'] = 'STARTER';
                        } else {
                            $_SESSION[$merchantID]['merchantInfo']['tierName'] = !empty($merchantInfo->appSubscription->name) ? $merchantInfo->appSubscription->name : $merchant['tierName'];
                        }

                        // override tier
                        if (in_array($merchantID, $this->merchantUpgradeToBasic)) {
                            $_SESSION[$merchantID]['merchantInfo']['tierName'] = 'BASIC';
                        } elseif (in_array($merchantID, $this->merchantUpgradeToAdvanced)) {
                            $_SESSION[$merchantID]['merchantInfo']['tierName'] = 'ADVANCED';
                        }

                        $_SESSION[$merchantID]["timestamp"] = $now;
                    }

                    $merchant['tierName'] = $_SESSION[$merchantID]['merchantInfo']['tierName'];
                }


                // For 'multi' feature we always include calling merchant and:
                //  - if the caller is FREE, we ignore his other merchants.
                //  - if the caller is not FREE, we ignore only his other FREE merchants.
                if ($this->merchantIdCaller != $merchant['merchantID'] && ($caller_is_free || ($merchant['tierName'] != 'BASIC' && $merchant['tierName'] != 'ADVANCED'))) {
                    continue;
                }

                // Filter also by tier name if specified.
                if (!empty($filterTier) && !in_array($merchant['tierName'], $filterTier)) {
                    continue;
                }

                $this->merchantIds[$merchant['merchantID']] = $merchant['merchantID'];
                $this->merchantNames[$merchant['merchantID']] = $merchant['merchantName'];
                $this->tokens[$merchant['merchantID']] = $merchant['token'];
                $this->timezones[$merchant['merchantID']] = $merchant['merchantTimezone'];
                $this->vats[$merchant['merchantID']] = $merchant['vat'];
                if (!empty($merchant['property_value'])) {
                    $hourShift = json_decode($merchant['property_value']);
                    $this->hourShifts[$merchant['merchantID']] = $hourShift->businessDayStartId;
                    $this->generalSettings[$merchant['merchantID']] = json_decode($merchant['property_value']);
                } else {
                    $this->hourShifts[$merchant['merchantID']] = 0;
                    $this->generalSettings[$merchant['merchantID']] = null;
                }
                $this->dayParts[$merchant['merchantID']] = $this->getDayPartsFromSettings($this->generalSettings[$merchant['merchantID']], $this->timezones[$merchant['merchantID']]);

                if (!isset($_SESSION[$merchant['merchantID']]['merchantInfo']['hasCustomers'])) {
                    $merchantData = new stdClass;
                    $merchantData->merchant_id = $merchant['merchantID'];
                    $merchantData->merchant_name = $this->merchantNames[$merchant['merchantID']];
                    $merchantData->token = $this->tokens[$merchant['merchantID']];
                    $merchantData->timezone = $this->timezones[$merchant['merchantID']];
                    $merchantData->hour_shift = $this->hourShifts[$merchant['merchantID']];
                    $merchantData->report = $this->callingData->report;
                    $merchantData->return_categories = $this->callingData->return_categories;
                    $merchantData->get = '';
                    $clover = new Clover();
                    $clover->setApiData($this->apiType, $merchantData, $merchant['merchantID']);
                    $_SESSION[$merchant['merchantID']]['merchantInfo']['hasCustomers'] = $clover->hasCustomers();
                }
                $this->hasCustomers[$merchant['merchantID']] = $_SESSION[$merchant['merchantID']]['merchantInfo']['hasCustomers'];
            }
        }

        // we need settings from calling merchant. if he is filtered out, we need to include him.
        if(!isset($this->generalSettings[$this->merchantIdCaller])) {
            $stmt = $db->prepare("SELECT mp.property_value
						  FROM clover_merchant_properties mp
						  WHERE mp.merchant_id = ? AND mp.property_type = 'general'");
            $stmt->bindValue(1, $this->merchantIdCaller, PDO::PARAM_STR);

            if (!$stmt->execute()) {
                throw new Exception('Internal server error (2).');
            }

            $property = $stmt->fetch(PDO::FETCH_ASSOC);

            if(!empty($property) && !empty($property['property_value'])) {
                $this->generalSettings[$this->merchantIdCaller] = json_decode($property['property_value']);
            }
        }
    }

    /**
     * Sets report data and sets data for each merchant/location.
     */
    public function setMerchantData() {
        $this->get = $this->callingData->get;
        $this->reportName = isset($this->callingData->report) ? $this->callingData->report : '';
        $this->returnCategories = true; // we'll set it true every time now.

        $this->output = isset($this->callingData->output) ? $this->callingData->output : '';

        $this->getOpenOrders = isset($this->callingData->openOrders) && $this->callingData->openOrders === true;

        $this->groupFields = isset($this->callingData->groupFields) && is_array($this->callingData->groupFields) ? $this->callingData->groupFields : [];
        // Check if columns are objects, and check column alias.
        foreach($this->groupFields as &$tmpField) {
            if(is_array($tmpField)) {
                $tmpField = (object)$tmpField;
            }
            if(empty($tmpField->alias)) {
                $tmpField->alias = $tmpField->name;
            }
        }
        $this->groupFilters = isset($this->callingData->groupFilters) ? (array)$this->callingData->groupFilters : [];
        $this->groupFieldForLOV = !empty($this->callingData->groupFieldForLOV) ? $this->callingData->groupFieldForLOV : '';
        $this->groupSplitField = !empty($this->callingData->groupSplitField) ? $this->callingData->groupSplitField : '';
        $this->groupDoGrouping = isset($this->callingData->groupDoGrouping) ? (bool)$this->callingData->groupDoGrouping : true;
        $this->groupDoFilling = isset($this->callingData->groupDoFilling) ? (bool)$this->callingData->groupDoFilling : false;

        $this->dateFormat = $this->getGeneralSetting($this->merchantIdCaller, 'dateFormat', self::DATE_FORMAT_ISO);

        // if we are just grouping, a lot of things are not necessary.
        if($this->groupingInProcess) {
            return;
        }

        if (strpos($this->get, 'reports') !== false || $this->get === 'clear_cache') {
            $this->useSession = false;
        } else {
            $this->useSession = true;
        }

        $this->cacheCustomers = $this->getGeneralSetting($this->merchantIdCaller, 'fetchCustomerInfo', false);

        foreach($this->merchantIds as $merchantId) {
            $merchantData = new stdClass;
            $merchantData->merchant_id = $merchantId;
            $merchantData->merchant_name = $this->merchantNames[$merchantId];
            $merchantData->token = $this->tokens[$merchantId];
            $merchantData->timezone = $this->timezones[$merchantId];
            $merchantData->hour_shift = $this->hourShifts[$merchantId];
            $merchantData->report = $this->callingData->report;
            $merchantData->return_categories = $this->callingData->return_categories;
            $merchantData->get = $this->callingData->get;

            if(isset($this->callingData->days_offset)) $merchantData->days_offset = $this->callingData->days_offset;
            if(isset($this->callingData->days_offset_start)) $merchantData->days_offset_start = $this->callingData->days_offset_start;
            if(isset($this->callingData->days_offset_end)) $merchantData->days_offset_end = $this->callingData->days_offset_end;
            if(isset($this->callingData->date_start)) $merchantData->date_start = $this->callingData->date_start;
            if(isset($this->callingData->date_end)) $merchantData->date_end = $this->callingData->date_end;

            $clover = new Clover;
            $clover->setCustomers($this->hasCustomers[$merchantId]); // call before setApiData
            $reportParams = $clover->setApiData($this->apiType, $merchantData, $merchantId);
            // set-up the 'main' start and end date which will be sent to frontend.
            if($merchantId == $this->merchantIdCaller) {
                // we will use calling merchant's dates...
                $this->reportStartDate = $reportParams['startDate_readable'];
                $this->reportEndDate = $reportParams['endDate_readable'];
            } elseif(empty($this->reportStartDate)) {
                // ... but we will initialize it to the first location we find. reason for this is merchant filtering on frontend.
                $this->reportStartDate = $reportParams['startDate_readable'];
                $this->reportEndDate = $reportParams['endDate_readable'];
            }

            $this->merchants[$merchantId] = $clover;
        }
    }

    /**
     * Initialize merchant's cache. Creates new one if the session expired.
     *
     * @param bool $doNotForceNew Can be set to 'true' in non-session environment (CLI).
     * @param string|null $cacheIdentifier Change cache identifier. Used in special environments.
     */
    public function initializeCache($doNotForceNew = false, $cacheIdentifier = null) {
        if($doNotForceNew) {
            $forceNewCache = false;
        } else {
            $forceNewCache = empty($_SESSION[$this->merchantIdCaller]['cacheExist']) ? true : false;
        }

        $this->cache = new CloverCache($this->apiType, $this->merchantIdCaller, $this->merchantIds, $this->merchantNames, $forceNewCache, $cacheIdentifier, $this->getGeneralSetting($this->merchantIdCaller, 'dateFormat', self::DATE_FORMAT_ISO));

        $_SESSION[$this->merchantIdCaller]['cacheExist'] = true;
    }

    /**
     * Checks if session data exist and is still valid.
     *
     * @return bool|null Returns true if session data is valid for all locations, false otherwise. Returns null if no session should be used.
     */
    public function checkSession() {
        if(!$this->useSession) {
            return null;
        }

        // checking session data for all merchants, not just calling merchant (because of merchant filtering on frontend).
        // if one is invalid/missing, we unset data for all merchants.
        $this->cacheIsValid = $this->cache->checkCache();
        if(!$this->cacheIsValid) {
            $this->cache->clearCache(['devices','items','employees', 'customers'], true, $this->cache->merchantIdsExpiredCache);
        }

        return $this->cacheIsValid;
    }

    /**
     * Gets from or sets to SESSION devices and items with categories and tags.
     */
    public function getOrSetSessionData() {
        // if for this request we don't use cache or the cache is valid, there's nothing to do here.
        if(!$this->useSession || $this->cacheIsValid) {
            return;
        }
        
        // get data just for locations that have invalid cache. 
        $cacheFilterMerchantIds = array_fill_keys($this->cache->merchantIdsExpiredCache, '');

        # get devices.
        $cacheDevices = []; // NOTICE: keys won't be device IDs, but concatenation of merchantID and device ID.
        $devicesArray = null;
        $devicesMerchant = null;
        $devices = null;
        $merchantId = null;
        $this->callApi('urlDevices', $devicesArray, self::MULTI_CHUNK_FOR_SINGLE_LOW, $cacheFilterMerchantIds);
        foreach($devicesArray as $merchantId => $devicesMerchant) {
            foreach($devicesMerchant as $devices) {
                if (isset($devices->elements) && is_array($devices->elements)) {
                    foreach ($devices->elements as $item) {
                        $cacheDevices[$merchantId . $item->id]['id'] = $item->id;
                        $cacheDevices[$merchantId . $item->id]['merchantID'] = $merchantId;
                        $cacheDevices[$merchantId . $item->id]['merchantName'] = $this->merchantNames[$merchantId];
                        $cacheDevices[$merchantId . $item->id]['serial'] = !empty($item->serial) ? $item->serial : 'Unknown device';
                        $cacheDevices[$merchantId . $item->id]['model'] = !empty($item->model) ? $item->model : 'Unknown device';
                        $cacheDevices[$merchantId . $item->id]['name'] = !empty($item->name) ? $item->name : $cacheDevices[$merchantId . $item->id]['serial'];
                    }
                }
            }
        }

        $this->cache->insertCacheData('devices', $cacheDevices);
        $cacheDevices = null;

        # get items, categories, tags, stock count.
        if ($this->returnCategories) {
            // Use new API for /items with 'expand' to also get categories, tags and stock count.
            $cacheItems = []; // NOTICE: keys won't be item IDs, but concatenation of merchantID and item ID.
            $itemsArray = null;
            $itemsMerchant = null;
            $items = null;
            $merchantId = null;
            $this->callApi('urlItems', $itemsArray, self::MULTI_CHUNK_FOR_SINGLE_MIDDLE, $cacheFilterMerchantIds);
            foreach ($itemsArray as $merchantId => $itemsMerchant) {
                foreach ($itemsMerchant as $items) {
                    if (isset($items->elements) && is_array($items->elements)) {
                        foreach ($items->elements as $item) {
                            $cacheItems[$merchantId . $item->id]['id'] = $item->id;
                            $cacheItems[$merchantId . $item->id]['merchantID'] = $merchantId;
                            $cacheItems[$merchantId . $item->id]['merchantName'] = $this->merchantNames[$merchantId];
                            $cacheItems[$merchantId . $item->id]['name'] = !empty($item->name) ? preg_replace('/[\x00-\x1F\x7F]/', '', trim($item->name)) : 'N/A';
                            $cacheItems[$merchantId . $item->id]['alternateName'] = !empty($item->alternateName) ? $item->alternateName : 'N/A';
                            $cacheItems[$merchantId . $item->id]['sku'] = !empty($item->sku) ? $item->sku : 'N/A';
                            $cacheItems[$merchantId . $item->id]['price'] = !empty($item->price) ? $item->price : 0;
                            $cacheItems[$merchantId . $item->id]['cost'] = !empty($item->cost) ? $item->cost : 0;
                            $cacheItems[$merchantId . $item->id]['code'] = !empty($item->code) ? $item->code : 'N/A';
                            $cacheItems[$merchantId . $item->id]['priceType'] = !empty($item->priceType) ? $item->priceType : 'N/A';
                            $cacheItems[$merchantId . $item->id]['unitName'] = !empty($item->unitName) ? $item->unitName : '';
                            $cacheItems[$merchantId . $item->id]['stockCount'] = !empty($item->stockCount) ? $item->stockCount : 0;
                            $cacheItems[$merchantId . $item->id]['stockCount_deprecated'] = $cacheItems[$merchantId . $item->id]['stockCount'];

                            $cacheItems[$merchantId . $item->id]['deleted'] = 1; // We will change it in the next API call if necessary.
                            $cacheItems[$merchantId . $item->id]['category'] = 'Uncategorised';
                            $cacheItems[$merchantId . $item->id]['category_1'] = 'Uncategorised';
                            $cacheItems[$merchantId . $item->id]['category_2'] = 'Uncategorised';
                            $cacheItems[$merchantId . $item->id]['category_3'] = 'Uncategorised';
                            $cacheItems[$merchantId . $item->id]['tag'] = 'Unlabeled';
                            $cacheItems[$merchantId . $item->id]['tag_1'] = 'Unlabeled';
                            $cacheItems[$merchantId . $item->id]['tag_2'] = 'Unlabeled';
                            $cacheItems[$merchantId . $item->id]['tag_3'] = 'Unlabeled';

                            // item stock count.
                            if(isset($item->itemStock)) {
                                $cacheItems[$merchantId . $item->id]['stockCount'] = $item->itemStock->quantity;
                            }

                            // categories.
                            if(isset($item->categories->elements) && is_array($item->categories->elements)) {
                                $i = 0;
                                foreach($item->categories->elements as $category) {
                                    // 'category' field has last defined category. other 'category_' fields have 1st, 2nd, 3rd defined category.
                                    if(!empty($category->name)) {
                                        $cacheItems[$merchantId . $item->id]['category'] = $category->name;

                                        if(++$i <= 3) {
                                            $cacheItems[$merchantId . $item->id]['category_' . $i] = $category->name;
                                        }
                                    }
                                }
                            }

                            // tags/labels.
                            if(isset($item->tags->elements) && is_array($item->tags->elements)) {
                                $i = 0;
                                foreach($item->tags->elements as $tag) {
                                    // 'tag' field has last defined tag. other 'tag_' fields have 1st, 2nd, 3rd defined tag.
                                    if(!empty($tag->name)) {
                                        $cacheItems[$merchantId . $item->id]['tag'] = $tag->name;

                                        if(++$i <= 3) {
                                            $cacheItems[$merchantId . $item->id]['tag_' . $i] = $tag->name;
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }

            // get non-deleted items.
            $itemsArray = null;
            $itemsMerchant = null;
            $items = null;
            $merchantId = null;
            $this->callApi('urlItemsNoDeleted', $itemsArray, self::MULTI_CHUNK_FOR_SINGLE_MIDDLE, $cacheFilterMerchantIds);
            foreach ($itemsArray as $merchantId => $itemsMerchant) {
                foreach ($itemsMerchant as $items) {
                    if (isset($items->elements) && is_array($items->elements)) {
                        foreach ($items->elements as $item) {
                            if (isset($cacheItems[$merchantId . $item->id])) {
                                $cacheItems[$merchantId . $item->id]['deleted'] = 0;
                            }
                        }
                    }
                }
            }

            $this->cache->insertCacheData('items', $cacheItems);
            $cacheItems = null;
        }

        # get employees.
        $cacheEmployees = []; // NOTICE: keys won't be employee IDs, but concatenation of merchantID and employee ID.
        $employeesArray = null;
        $employeesMerchant = null;
        $employees = null;
        $merchantId = null;
        $this->callApi('urlEmployees', $employeesArray, self::MULTI_CHUNK_FOR_SINGLE_LOW, $cacheFilterMerchantIds);
        foreach($employeesArray as $merchantId => $employeesMerchant) {
            foreach($employeesMerchant as $employees) {
                if (isset($employees->elements) && is_array($employees->elements)) {
                    foreach ($employees->elements as $item) {
                        $cacheEmployees[$merchantId . $item->id]['id'] = $item->id;
                        $cacheEmployees[$merchantId . $item->id]['merchantID'] = $merchantId;
                        $cacheEmployees[$merchantId . $item->id]['merchantName'] = $this->merchantNames[$merchantId];
                        $cacheEmployees[$merchantId . $item->id]['name'] = !empty($item->name) ? $item->name : 'Unknown';
                        $cacheEmployees[$merchantId . $item->id]['nickname'] = !empty($item->nickname) ? $item->nickname : 'Unknown';
                        $cacheEmployees[$merchantId . $item->id]['email'] = !empty($item->email) ? $item->email : 'Unknown';
                        $cacheEmployees[$merchantId . $item->id]['pin'] = !empty($item->pin) ? $item->pin : 'Unknown';
                        $cacheEmployees[$merchantId . $item->id]['role'] = !empty($item->role) ? $item->role : 'Unknown';
                    }
                }
            }
        }

        $this->cache->insertCacheData('employees', $cacheEmployees);
        $cacheEmployees = null;

        # get customers.
        if($this->cacheCustomers) {
            // additionally filter merchants by those that actually have customers.
            $merchantIdsWithCustomers = [];
            foreach($cacheFilterMerchantIds as $cacheFilterMerchantId => $value) {
                if($this->merchants[$cacheFilterMerchantId]->hasCustomers()) {
                    $merchantIdsWithCustomers[$cacheFilterMerchantId] = '';
                }
            }

            if(!empty($merchantIdsWithCustomers)) {
                $cacheCustomers = []; // NOTICE: keys won't be customer IDs, but concatenation of merchantID and customer ID.
                $customersArray = null;
                $customersMerchant = null;
                $customers = null;
                $merchantId = null;
                $this->callApi('urlCustomersAll', $customersArray, self::MULTI_CHUNK_FOR_SINGLE_LOW, $merchantIdsWithCustomers);
                $unknownPlaceholder = 'N/A';
                foreach($customersArray as $merchantId => $customersMerchant) {
                    foreach($customersMerchant as $customers) {
                        if (isset($customers->elements) && is_array($customers->elements)) {
                            foreach ($customers->elements as $item) {
                                $cacheCustomers[$merchantId . $item->id]['id'] = $item->id;
                                $cacheCustomers[$merchantId . $item->id]['merchantID'] = $merchantId;
                                $cacheCustomers[$merchantId . $item->id]['merchantName'] = $this->merchantNames[$merchantId];
                                $cacheCustomers[$merchantId . $item->id]['name'] = trim($item->firstName . ' ' . $item->lastName);
                                if($cacheCustomers[$merchantId . $item->id]['name'] == 'null') {
                                    $cacheCustomers[$merchantId . $item->id]['name'] = 'N/A';
                                }
                                $cacheCustomers[$merchantId . $item->id]['marketingAllowed'] = (int)$item->marketingAllowed;
                                $cacheCustomers[$merchantId . $item->id]['customerSinceTimestamp'] = !empty($item->customerSince) ? (int)substr($item->customerSince, 0, -3) : 0;
                                $cacheCustomers[$merchantId . $item->id]['note'] = !empty($item->metadata->note) ? $item->metadata->note : 'Unknown';
                                $cacheCustomers[$merchantId . $item->id]['businessName'] = !empty($item->metadata->businessName) ? $item->metadata->businessName : 'Unknown';
                                $cacheCustomers[$merchantId . $item->id]['dobDay'] = !empty($item->metadata->dobDay) ? $item->metadata->dobDay : 0;
                                $cacheCustomers[$merchantId . $item->id]['dobMonth'] = !empty($item->metadata->dobMonth) ? $item->metadata->dobMonth : 0;
                                $cacheCustomers[$merchantId . $item->id]['dobYear'] = !empty($item->metadata->dobYear) ? $item->metadata->dobYear : 0;

                                $cacheCustomers[$merchantId . $item->id]['emailFirst'] = $unknownPlaceholder;
                                $cacheCustomers[$merchantId . $item->id]['emailLast'] = $unknownPlaceholder;
                                $cacheCustomers[$merchantId . $item->id]['phoneNumberFirst'] = $unknownPlaceholder;
                                $cacheCustomers[$merchantId . $item->id]['phoneNumberLast'] = $unknownPlaceholder;
                                $cacheCustomers[$merchantId . $item->id]['addressFirstStreet'] = $unknownPlaceholder;
                                $cacheCustomers[$merchantId . $item->id]['addressFirstCity'] = $unknownPlaceholder;
                                $cacheCustomers[$merchantId . $item->id]['addressFirstCountry'] = $unknownPlaceholder;
                                $cacheCustomers[$merchantId . $item->id]['addressFirstState'] = $unknownPlaceholder;
                                $cacheCustomers[$merchantId . $item->id]['addressFirstZip'] = $unknownPlaceholder;
                                $cacheCustomers[$merchantId . $item->id]['addressLastStreet'] = $unknownPlaceholder;
                                $cacheCustomers[$merchantId . $item->id]['addressLastCity'] = $unknownPlaceholder;
                                $cacheCustomers[$merchantId . $item->id]['addressLastCountry'] = $unknownPlaceholder;
                                $cacheCustomers[$merchantId . $item->id]['addressLastState'] = $unknownPlaceholder;
                                $cacheCustomers[$merchantId . $item->id]['addressLastZip'] = $unknownPlaceholder;
                                if(isset($item->emailAddresses->elements) && is_array($item->emailAddresses->elements)) {
                                    foreach($item->emailAddresses->elements as $itemElement) {
                                        if($cacheCustomers[$merchantId . $item->id]['emailFirst'] == $unknownPlaceholder) {
                                            $cacheCustomers[$merchantId . $item->id]['emailFirst'] = $itemElement->emailAddress;
                                        }
                                        $cacheCustomers[$merchantId . $item->id]['emailLast'] = $itemElement->emailAddress;
                                        $cacheCustomers[$merchantId . $item->id]['emails'] .= $itemElement->emailAddress . ',';
                                    }
                                }
                                if(isset($item->phoneNumbers->elements) && is_array($item->phoneNumbers->elements)) {
                                    foreach($item->phoneNumbers->elements as $itemElement) {
                                        if($cacheCustomers[$merchantId . $item->id]['phoneNumberFirst'] == $unknownPlaceholder) {
                                            $cacheCustomers[$merchantId . $item->id]['phoneNumberFirst'] = $itemElement->phoneNumber;
                                        }
                                        $cacheCustomers[$merchantId . $item->id]['phoneNumberLast'] = $itemElement->phoneNumber;
                                        $cacheCustomers[$merchantId . $item->id]['phoneNumbers'] .= $itemElement->phoneNumber . ',';
                                    }
                                }
                                if(isset($item->addresses->elements) && is_array($item->addresses->elements)) {
                                    foreach($item->addresses->elements as $itemElement) {
                                        if($cacheCustomers[$merchantId . $item->id]['addressFirstStreet'] == $unknownPlaceholder) {
                                            $cacheCustomers[$merchantId . $item->id]['addressFirstStreet'] = trim($itemElement->address1 . ' ' . $itemElement->address2 . ' ' . $itemElement->address3);
                                            $cacheCustomers[$merchantId . $item->id]['addressFirstCity'] = !empty($itemElement->city) ? $itemElement->city : $unknownPlaceholder;
                                            $cacheCustomers[$merchantId . $item->id]['addressFirstCountry'] = !empty($itemElement->country) ? $itemElement->country : $unknownPlaceholder;
                                            $cacheCustomers[$merchantId . $item->id]['addressFirstState'] = !empty($itemElement->state) ? $itemElement->state : $unknownPlaceholder;
                                            $cacheCustomers[$merchantId . $item->id]['addressFirstZip'] = !empty($itemElement->zip) ? $itemElement->zip : $unknownPlaceholder;
                                        }
                                        $cacheCustomers[$merchantId . $item->id]['addressLastStreet'] = trim($itemElement->address1 . ' ' . $itemElement->address2 . ' ' . $itemElement->address3);
                                        $cacheCustomers[$merchantId . $item->id]['addressLastCity'] = !empty($itemElement->city) ? $itemElement->city : $unknownPlaceholder;
                                        $cacheCustomers[$merchantId . $item->id]['addressLastCountry'] = !empty($itemElement->country) ? $itemElement->country : $unknownPlaceholder;
                                        $cacheCustomers[$merchantId . $item->id]['addressLastState'] = !empty($itemElement->state) ? $itemElement->state : $unknownPlaceholder;
                                        $cacheCustomers[$merchantId . $item->id]['addressLastZip'] = !empty($itemElement->zip) ? $itemElement->zip : $unknownPlaceholder;

                                        $cacheCustomers[$merchantId . $item->id]['addressesCity'] = $cacheCustomers[$merchantId . $item->id]['addressLastCity'] . ',';
                                        $cacheCustomers[$merchantId . $item->id]['addressesCountry'] = $cacheCustomers[$merchantId . $item->id]['addressLastCountry'] . ',';
                                        $cacheCustomers[$merchantId . $item->id]['addressesState'] = $cacheCustomers[$merchantId . $item->id]['addressLastState'] . ',';
                                        $cacheCustomers[$merchantId . $item->id]['addressesZip'] = $cacheCustomers[$merchantId . $item->id]['addressLastZip'] . ',';
                                    }
                                }

                                if(!empty($cacheCustomers[$merchantId . $item->id]['emails'])) {
                                    $cacheCustomers[$merchantId . $item->id]['emails'] = rtrim($cacheCustomers[$merchantId . $item->id]['emails'], ',');
                                } else {
                                    $cacheCustomers[$merchantId . $item->id]['emails'] = $unknownPlaceholder;
                                }
                                if(!empty($cacheCustomers[$merchantId . $item->id]['phoneNumbers'])) {
                                    $cacheCustomers[$merchantId . $item->id]['phoneNumbers'] = rtrim($cacheCustomers[$merchantId . $item->id]['phoneNumbers'], ',');
                                } else {
                                    $cacheCustomers[$merchantId . $item->id]['phoneNumbers'] = $unknownPlaceholder;
                                }
                                if(!empty($cacheCustomers[$merchantId . $item->id]['addressesCity'])) {
                                    $cacheCustomers[$merchantId . $item->id]['addressesCity'] = rtrim($cacheCustomers[$merchantId . $item->id]['addressesCity'], ',');
                                } else {
                                    $cacheCustomers[$merchantId . $item->id]['addressesCity'] = $unknownPlaceholder;
                                }
                                if(!empty($cacheCustomers[$merchantId . $item->id]['addressesCountry'])) {
                                    $cacheCustomers[$merchantId . $item->id]['addressesCountry'] = rtrim($cacheCustomers[$merchantId . $item->id]['addressesCountry'], ',');
                                } else {
                                    $cacheCustomers[$merchantId . $item->id]['addressesCountry'] = $unknownPlaceholder;
                                }
                                if(!empty($cacheCustomers[$merchantId . $item->id]['addressesState'])) {
                                    $cacheCustomers[$merchantId . $item->id]['addressesState'] = rtrim($cacheCustomers[$merchantId . $item->id]['addressesState'], ',');
                                } else {
                                    $cacheCustomers[$merchantId . $item->id]['addressesState'] = $unknownPlaceholder;
                                }
                                if(!empty($cacheCustomers[$merchantId . $item->id]['addressesZip'])) {
                                    $cacheCustomers[$merchantId . $item->id]['addressesZip'] = rtrim($cacheCustomers[$merchantId . $item->id]['addressesZip'], ',');
                                } else {
                                    $cacheCustomers[$merchantId . $item->id]['addressesZip'] = $unknownPlaceholder;
                                }
                            }
                        }
                    }
                }
            }
        }

        $this->cache->insertCacheData('customers', $cacheCustomers);
        $cacheCustomers = null;

        $this->cache->updateCacheTime(array_keys($cacheFilterMerchantIds));
    }

    /**
     * Runs report through all merchants.
     *
     * @param array $newOrders
     * @param array $payments
     * @param array $reportsPayments
     * @param array $reportsItems
     * @param array $items
     * @param array $groupedResult
     * @param string|null $groupedResultDecimalSeparator
     * @param bool $addAggToAlias
     * @param bool $addDayNumberToDay
     */
    public function run(&$newOrders, &$payments, &$reportsPayments, &$reportsItems, &$items, &$groupedResult, $groupedResultDecimalSeparator = null, $addAggToAlias = true, $addDayNumberToDay = false) {
        if (strpos($this->get, 'reports') !== false) {
            if (!is_array($reportsPayments)) {
                $reportsPayments = array();
            }
            if (!is_array($reportsItems)) {
                $reportsItems = array();
            }

            // For 'reports' endpoint, it's simple - just get the data and add record in the 'behavior' table.
            if (strpos($this->get, 'reports_payments') !== false) {
                $this->getReportsPayments($reportsPayments);
                $this->auditBehaviour(count($reportsPayments));
            } elseif (strpos($this->get, 'reports_items') !== false) {
                $this->getReportsItems($reportsItems);
                $this->auditBehaviour(count($reportsItems));
            }
        } else {
            switch($this->get) {
                case 'items':
                    if (!is_array($items)) {
                        $items = array();
                    }

                    $this->getItems($items);

                    $this->auditBehaviour(count($items));
                    break;

                case 'lov_orders':
                case 'lov_order_items':
                case 'lov_modifications':
                case 'lov_modification_groups':
                case 'lov_payments':
                    $table = substr($this->get, 4);
                    if(empty($this->groupFieldForLOV)) {
                        self::returnError('No field specified.');
                    }

                    // 'Fool' grouping to give us distinct values - group by dimension with no measure.
                    $groupFields = [(object) ['name' => $this->groupFieldForLOV, 'type' => 'dimension']];
                    $tmpResult = $this->cache->groupCacheData($table, $groupFields);

                    // To give simpler output - instead of array of objects with values, give directly array of values.
                    $groupedResult = [];
                    foreach($tmpResult as $row) {
                        $groupedResult[] = current($row);
                    }

                    // No audit for 'grouping' calls.

                    break;

                case 'group_orders':
                case 'group_order_items':
                case 'group_modifications':
                case 'group_modification_groups':
                case 'group_payments':
                    $table = substr($this->get, 6);
                    if(empty($this->groupFields)) {
                        self::returnError('No fields specified.');
                    }

                    $groupedResult = $this->cache->groupCacheData($table, $this->groupFields, $this->groupSplitField, $this->groupFilters, $this->groupDoGrouping, $this->groupDoFilling, $groupedResultDecimalSeparator, $addAggToAlias);

                    // No audit for 'grouping' calls.

                    break;

                case 'raw_orders':
                case 'raw_order_items':
                case 'raw_payments':
                case 'raw_modifications':
                case 'raw_modification_groups':
                    $table = substr($this->get, 4);

                    if($this->output == 'csv') {
                        $castMeasures = false;
                        if($table == 'orders' || $table == 'order_items') {
                            $selectClause = '
                                        "orders"."merchantName" AS "merchantName"
                                        , "trx_type" AS "trx_type"
                                        , "device_name" AS "device_name"
                                        , "order" AS "order"
                                        , "orderType" AS "orderType"
                                        , "date" AS "date"
                                        , "original_date" AS "original_date"
                                        , "day" AS "day"
                                        , "week" AS "week"
                                        , "hour" AS "hour"
                                        , "daypart" AS "daypart"
                                        , "time" AS "time"
                                        , "employee" AS "employee"
                                        , "payment_tender_labels" AS "payment_type"
                                        , "payment_date" AS "payment_date"
                                        , "payment_time" AS "payment_time"
                                        , "card_type" AS "card_type"
                                        , "total_order" AS "order_amount"
                                        , "total_gross" AS "order_pay_gross_amount"
                                        , "total_refund" AS "order_refund_amount"
                                        , "credit_amount" AS "credit_amount"
                                        , "total" AS "order_gross_minus_refund"
                                        
                                        , "tax_amount_pay_gross" AS "order_tax_amount_gross"
                                        , "total_refund_tax" AS "order_tax_refund_amount"
                                        , "credit_taxamount" AS "credit_taxamount"
                                        , "tax_amount_pay" AS "order_net_tax_amount"
                                        , "total_minus_tax" AS "order_net_minus_tax_amount"
                                        , "discount_order_names" AS "order_discount_names"
                                        , "discount_order_percent" AS "order_discount_percent"
                                        , "discount_order_amount" AS "order_discount_amount"
                                        , "tipAmount" AS "order_tip_amount"
                                        , "serviceCharge" AS "order_serviceCharge"
                                        , CASE "taxRemoved" WHEN 1 THEN \'TRUE\' ELSE \'FALSE\' END AS "taxRemoved"
                                        , CASE "isVat" WHEN 1 THEN \'TRUE\' ELSE \'FALSE\' END AS "isVat"
                                        , "payType" AS "payType"
                                        , CASE "manualTransaction" WHEN 0 THEN \'FALSE\' ELSE \'TRUE\' END AS "manualTransaction"
                                        ';

                            if($table == 'order_items') {
                                $selectClause .= '
                                        , "item_id" AS "item_id"
                                        , "sku" AS "sku"
                                        , "item" AS "item_name"
                                        , "itemCode" AS "item_code"
                                        , "noteItem" AS "note"
                                        , CASE "refunded" WHEN 1 THEN \'TRUE\' ELSE \'FALSE\' END AS "refunded"
                                        , CASE "isRevenue" WHEN 1 THEN \'TRUE\' ELSE \'FALSE\' END AS "is_revenue"
                                        , CASE "exchanged" WHEN 1 THEN \'TRUE\' ELSE \'FALSE\' END AS "exchanged"
                                        , "tag" AS "tag"
                                        , "tag_1" AS "tag1"
                                        , "tag_2" AS "tag2"
                                        , "tag_3" AS "tag3"
                                        , "category" AS "category"
                                        , "category_1" AS "category1"
                                        , "category_2" AS "category2"
                                        , "category_3" AS "category3"
                                        , "modification_names" AS "modifiers"
                                        , "orig_price" AS "item_price"
                                        , "cost" AS "item_cost"
                                        , "modifications" AS "item_modifications_amount"
                                        , "discount_item_names" AS "item_discount_names"
                                        , "discounts" AS "item_discount_in_percent"
                                        , "_itm_disc_from_item_perc" AS "itm_disc_amount_from_perc"
                                        , "discounts_amount" AS "item_discount_in_amount"
                                        , "_itm_disc_from_order_perc" AS "itm_disc_amount_from_perc_on_order"
                                        , "item_total_discount_amount" AS "item_net_discount_amount"
                                        , "tax_amount" AS "item_tax_amount"
                                        , "price_final" AS "item_gross_amount"
                                        , "price_final_minus_tax" AS "item_net_amount"
                                        ';
                            }

                            // add new columns at the back.
                            $selectClause .= '
                                        , IFNULL("customers"."name", \'N/A\') AS "customerName"
                                        ';
                        } else {
                            $selectClause = '*';
                        }
                    } else {
                        $castMeasures = true;
                        $selectClause = '*';
                    }

                    $groupedResult = $this->cache->getRawData($table, $castMeasures, $selectClause);

                    // No audit for 'grouping' calls.

                    break;

                case 'payments_default':
                case 'payments_daily':
                case 'payments_yesterday':
                case 'payments_monthly_MTD':
                case 'payments_monthly_LM':
                case 'payments_defined_dates':
                    if (!isset($cachePayments) || !is_array($cachePayments)) {
                        $cachePayments = array();
                    }

                    $this->getPayments($cachePayments, $payments_customers, $addDayNumberToDay);
                    $this->getPaymentsRefunds($cachePayments, $payments_customers, $addDayNumberToDay);
                    $this->getPaymentsCredits($cachePayments, $addDayNumberToDay);

                    $this->auditBehaviour(count($cachePayments));

                    // let us do some caching.
                    $this->cache->clearCache(['payments'], false);
                    $this->cache->insertCacheData('payments', $cachePayments);
                    
                    $payments['dataRows'] = count($cachePayments);

                    break;

                case 'clear_cache':
                    $this->cache->clearCache(['devices','items','employees','customers']);
                    break;

                default:
                    // get orders.
                    if (!isset($cacheModifications) || !is_array($cacheModifications)) {
                        $cacheModifications = array();
                    }
                    if (!isset($cacheModificationGroups) || !is_array($cacheModificationGroups)) {
                        $cacheModificationGroups = array();
                    }
                    if (!isset($cacheOrderItems) || !is_array($cacheOrderItems)) {
                        $cacheOrderItems = array();
                    }
                    if (!isset($cacheOrders) || !is_array($cacheOrders)) {
                        $cacheOrders = array();
                    }
                    $orders_other_totals = array();
                    $refund_orders_ids = array();
                    $orders_index = []; // contains array index of specific order id (orderId => index).
                    $order_items_index = []; // contains array index of specific order items (orderId => [index1, index2, ...])
                    $orders_refund_no_propagate = []; // orders for which we will not propagate refunds.

                    // start and end dates for order payments.
                    // they are 'original' timestamps, and not shifted for hourShift.
                    $period_for_order_payments = ['startTimestamp' => null, 'endTimestamp' => null];

                    $ordersItemsForCsv = [];

                    $this->getOrders($cacheOrderItems, $cacheOrders, $cacheModifications, $cacheModificationGroups, $orders_other_totals, $refund_orders_ids, $orders_index, $ordersItemsForCsv, $orders_refund_no_propagate, $period_for_order_payments, $order_items_index, $addDayNumberToDay);
                    // so not to hit API rate limit.
                    usleep(500000);

                    // we will not be getting refunds and credits in case we only want open orders.
                    if(!$this->getOpenOrders) {
                        // Gets refunds - add them among orders.
                        $this->getRefunds($cacheOrders, $refund_orders_ids, $ordersItemsForCsv, $addDayNumberToDay);

                        // Gets credits (manual transactions) - add them among orders.
                        $this->getCredits($cacheOrders, $ordersItemsForCsv, $addDayNumberToDay);
                    }

                    if(count($ordersItemsForCsv) && strtolower(php_sapi_name()) != 'cli') {
                        // log interesting orders and items. but only if not coming from cron/cli - write permission problems.
                        $filename = 'log/orders_items_testing-' . date('Y-W') . '.csv';
                        if(!is_file(__DIR__ . '/' . $filename)) {
                            // add header for new file.
                            array_unshift($ordersItemsForCsv, 'market;merchantID;merchantTier;trxType;orderID;orderOriginalDate;orderDiscountPerc;orderDiscountAbs;orderRefunded;orderServiceCharge;orderCreditAmount;paymentOriginalDate;itemDiscountPerc;itemDiscountAbs;itemExchanged;itemRefunded;itemIsRevenue');
                        }

                        $fileCsv = fopen(__DIR__ . '/' . $filename, 'a');
                        fwrite($fileCsv, implode("\n", $ordersItemsForCsv) . "\n");
                        fclose($fileCsv);
                    }

                    // Before sending the data back, we add auditing record.
                    $this->auditBehaviour(count($cacheOrders));

                    // Now we have all the info we need. We just have to sort number rounding.
                    $num_orders = count($cacheOrders);
                    for ($i = 0; $i < $num_orders; $i++) {
                        // $orders_other_totals array was unique for each merchant. Now it holds orders of all merchants in multi-merchant setup.
                        if(isset($orders_other_totals[$cacheOrders[$i]['order']])) {
                            $cacheOrders[$i]['total_cost'] = ((int) round($orders_other_totals[$cacheOrders[$i]['order']]["total_cost"])) / 100;
                            $cacheOrders[$i]['total_price'] = ((int) round($orders_other_totals[$cacheOrders[$i]['order']]["total_price"])) / 100;
                            $cacheOrders[$i]['total_discount'] = ((int) round($orders_other_totals[$cacheOrders[$i]['order']]["total_discount"])) / 100;
                            $cacheOrders[$i]['total_tax'] = ((int) round($orders_other_totals[$cacheOrders[$i]['order']]["total_tax"])) / 100;
                            $cacheOrders[$i]['total_profit'] = ((int) round($orders_other_totals[$cacheOrders[$i]['order']]["total_profit"])) / 100;
                            $cacheOrders[$i]['cost_and_tax'] = $cacheOrders[$i]['total_cost'] + $cacheOrders[$i]['tax_amount'];
                            $cacheOrders[$i]['payment_profit'] = $cacheOrders[$i]['total_minus_tax'] - $cacheOrders[$i]['total_cost'];
                        }
                    }


                    // before caching order items, we have to do some manipulation on them.
                    $this->cache->manipulateItemLevelAmounts($cacheOrderItems, $cacheOrders, $orders_index, $this->vats, $orders_refund_no_propagate);

                    // let us do some caching.
                    $this->cache->clearCache(['orders', 'order_items', 'modifications', 'modification_groups'], false);
                    $this->cache->insertCacheData('orders', $cacheOrders);
                    $this->cache->insertCacheData('order_items', $cacheOrderItems);
                    $this->cache->insertCacheData('modifications', $cacheModifications);
                    $this->cache->insertCacheData('modification_groups', $cacheModificationGroups);
                    
                    $newOrders['dataRows'] = count($cacheOrders);

                    break;
            }
        }
    }
    
    private function getOrders(&$cacheOrderItems, &$cacheOrders, &$cacheModifications, &$cacheModificationGroups, &$orders_other_totals, &$refund_orders_ids, &$orders_index, &$ordersItemsForCsv, &$orders_refund_no_propagate, &$period_for_order_payments, &$order_items_index, $addDayNumberToDay = false) {
        // cached data we will use.
        $this->cache->updateLocalCacheData('devices');
        $this->cache->updateLocalCacheData('employees');
        $this->cache->updateLocalCacheData('customers');
        $this->cache->updateLocalCacheData('items');

        $ordersArray = null;
        $ordersMerchant = null;
        $orders = null;
        $merchantId = null;
        $this->callApi('urlOrdersWithItems', $ordersArray, self::MULTI_CHUNK_FOR_SINGLE_LOW);
        foreach($ordersArray as $merchantId => $ordersMerchant) {
            $startDate_clover = $this->merchants[$merchantId]->reportParams['startDate'] . '000';
            $endDate_clover = $this->merchants[$merchantId]->reportParams['endDate'] . '000';

            $this->merchants[$merchantId]->setTimezone();

            foreach($ordersMerchant as $orders) {
                if(isset($orders->elements)) {
                    foreach($orders->elements as $order) {
                        // komentiramo zbog CREDIT-a da se mogu povezati sa takvim ORDER-ima.
                        //if ($order->payments->elements[0]->tender->label == "") {
                        //    continue;
                        //}

                        if ((!$this->getOpenOrders && $order->state == "open") || ($this->getOpenOrders && $order->state != "open")) {
                            continue;
                        }

                        $cacheOrder = [];
                        $cacheOrder['trx_type'] = "ORDER";
                        $cacheOrder['order'] = $order->id;
                        $cacheOrder['merchantID'] = $merchantId;
                        $cacheOrder['merchantName'] = $this->merchantNames[$merchantId];
                        $cacheOrder['employee_id'] = !empty($order->employee->id) ? $order->employee->id : 'N/A';
                        $cacheOrder['employee'] = !empty($order->employee->id) && !empty($this->cache->cacheEmployees[$merchantId . $order->employee->id]['name']) ? $this->cache->cacheEmployees[$merchantId . $order->employee->id]['name'] : 'N/A';
                        $cacheOrder['device_id'] = !empty($order->device->id) ? $order->device->id : 'N/A';
                        $cacheOrder['device_name'] = !empty($order->device->id) && !empty($this->cache->cacheDevices[$merchantId . $order->device->id]['name']) ? $this->cache->cacheDevices[$merchantId . $order->device->id]['name'] : 'N/A';
                        $cacheOrder['device_model'] = !empty($order->device->id) && !empty($this->cache->cacheDevices[$merchantId . $order->device->id]['model']) ? $this->cache->cacheDevices[$merchantId . $order->device->id]['model'] : 'N/A';
                        $cacheOrder['device_serial'] = !empty($order->device->id) && !empty($this->cache->cacheDevices[$merchantId . $order->device->id]['serial']) ? $this->cache->cacheDevices[$merchantId . $order->device->id]['serial'] : 'N/A';
                        $cacheOrder['customerId'] = !empty($order->customers->elements[0]->id) ? $order->customers->elements[0]->id : 'N/A';
                        $cacheOrder['customerName'] = !empty($order->customers->elements[0]->id) && !empty($this->cache->cacheCustomers[$merchantId . $order->customers->elements[0]->id]['name']) ? $this->cache->cacheCustomers[$merchantId . $order->customers->elements[0]->id]['name'] : 'N/A';
                        $cacheOrder['customerEmail'] = !empty($order->customers->elements[0]->id) && !empty($this->cache->cacheCustomers[$merchantId . $order->customers->elements[0]->id]['emails']) ? $this->cache->cacheCustomers[$merchantId . $order->customers->elements[0]->id]['emails'] : 'N/A';
                        $cacheOrder['customerPhoneNumber'] = !empty($order->customers->elements[0]->id) && !empty($this->cache->cacheCustomers[$merchantId . $order->customers->elements[0]->id]['phoneNumbers']) ? $this->cache->cacheCustomers[$merchantId . $order->customers->elements[0]->id]['phoneNumbers'] : 'N/A';
                        $cacheOrder['customerSince'] = !empty($order->customers->elements[0]->id) && !empty($this->cache->cacheCustomers[$merchantId . $order->customers->elements[0]->id]['customerSinceTimestamp']) ? $this->cache->cacheCustomers[$merchantId . $order->customers->elements[0]->id]['customerSinceTimestamp'] : 0;
                        $cacheOrder['orderType'] = isset($order->orderType) ? $order->orderType->label : "N/A";
                        $cacheOrder['orderTitle'] = isset($order->title) ? $order->title : "N/A";
                        $orderCreatedTimestamp = substr($order->clientCreatedTime, 0, strlen($order->clientCreatedTime) - 3);
                        $cacheOrder['original_date'] = date($this->dateFormat, $orderCreatedTimestamp);
                        $cacheOrder['date_iso_format'] = date(self::DATE_FORMAT_ISO, $orderCreatedTimestamp - 3600 * $this->hourShifts[$merchantId]);
                        $cacheOrder['date'] = date($this->dateFormat, $orderCreatedTimestamp - 3600 * $this->hourShifts[$merchantId]);
                        $cacheOrder['hour'] = date("H", $orderCreatedTimestamp);
                        $cacheOrder['hour_numeric'] = (int)$cacheOrder['hour'];
                        $cacheOrder['hour'] = $this->transform_hour_to_AM_PM($cacheOrder['hour']);
                        $cacheOrder['time'] = date("H:i", $orderCreatedTimestamp);
                        $cacheOrder['half_hour'] = $this->getHourPart(substr($cacheOrder['time'], -2));
                        $cacheOrder['daypart'] = $this->getDayPart($cacheOrder['time'], $cacheOrder['merchantID']);
                        $cacheOrder['day'] = date("l", $orderCreatedTimestamp - 3600 * $this->hourShifts[$merchantId]);
                        $cacheOrder['day_of_week'] = date("w", $orderCreatedTimestamp);
                        if($addDayNumberToDay) {
                            $cacheOrder['day'] = $cacheOrder['day_of_week'] . '-' . $cacheOrder['day'];
                        }
                        $cacheOrder['week'] = $this->getWeekNumberInYear($orderCreatedTimestamp - 3600 * $this->hourShifts[$merchantId]);
                        $cacheOrder['year'] = date("Y", $orderCreatedTimestamp - 3600 * $this->hourShifts[$merchantId]);

                        $cacheOrder['payment_id'] = !empty($order->payments->elements[0]->id) ? $order->payments->elements[0]->id : 'N/A';
                        $cacheOrder['payment_last4'] = !empty($order->payments->elements[0]->cardTransaction->last4) ? $order->payments->elements[0]->cardTransaction->last4 : 'N/A';
                        $cacheOrder['paymentLabel'] = !empty($order->payments->elements[0]->tender->label) ? $order->payments->elements[0]->tender->label : 'N/A';
                        $cacheOrder['payment_tender_labels'] = ''; // Napunit cemo ispod kada idemo po payments-ima
                        $cacheOrder['total_order'] = $order->total / 100;
                        $cacheOrder['dummy'] = 1;
                        $cacheOrder['cost_and_tax'] = 0;
                        $cacheOrder['payment_profit'] = 0;
                        $cacheOrder['taxRemoved'] = isset($order->taxRemoved) ? (int)$order->taxRemoved : 0;
                        $cacheOrder['isVat'] = isset($order->isVat) ? (int)$order->isVat : 0;
                        $cacheOrder['payType'] = !empty($order->payType) ? $order->payType : 'N/A';
                        $cacheOrder['manualTransaction'] = isset($order->manualTransaction) ? (int)$order->manualTransaction : 0;

                        $cacheOrder['note'] = isset($order->note) ? str_replace(["\r\n","\n","\r"], ' ', $order->note) : '';

                        $cacheOrder['total'] = 0;
                        $cacheOrder['tipAmount'] = 0;
                        $cacheOrder['serviceCharge'] = 0;
                        $cacheOrder['tax_amount_pay'] = 0;
                        $cacheOrder['total_gross'] = 0;
                        $cacheOrder['tax_amount_pay_gross'] = 0;
                        $cacheOrder['total_refund'] = 0;
                        $cacheOrder['total_refund_tax'] = 0;
                        $cacheOrder['credit_amount'] = 0;
                        $cacheOrder['credit_taxamount'] = 0;

                        $orderForCsv = [
                            'market' => $this->apiType,
                            'merchantID' => $cacheOrder['merchantID'],
                            'merchantTier' => !empty($_SESSION[$cacheOrder['merchantID']]['merchantInfo']['tierName']) ? (string)$_SESSION[$cacheOrder['merchantID']]['merchantInfo']['tierName'] : 'STARTER',
                            'trxType' => $cacheOrder['trx_type'],
                            'orderID' => $cacheOrder['order'],
                            'orderOriginalDate' => $cacheOrder['original_date'],
                            'orderDiscountPerc' => 'NO',
                            'orderDiscountAbs' => 'NO',
                            'orderRefunded' => 'NO',
                            'orderServiceCharge' => 'NO',
                            'orderCreditAmount' => 'NO',
                            'paymentOriginalDate' => '',
                            'itemDiscountPerc' => 'NO',
                            'itemDiscountAbs' => 'NO',
                            'itemExchanged' => 'NO',
                            'itemRefunded' => 'NO',
                            'itemIsRevenue' => 'YES',
                        ];
                        $interestingOrderItemsForCsv = false;


                        $taxRemoved = $order->taxRemoved;

                        if (isset($order->payments) && isset($order->payments->elements) && is_array($order->payments->elements)) {
                            $tmp_order_total = $order->total; // used for checking sum of payments.
                            $tmp_order_payments_amount = 0;
                            $tmp_order_refund_amount = 0;
                            $orderCreatedDateTimestamp = strtotime($cacheOrder['original_date']);
                            foreach ($order->payments->elements as $pay) {
                                $tmp_order_payments_amount += $pay->amount;
                                $cacheOrder['payment_ids'] .= !empty($pay->id) ? $pay->id . ', ' : '';
                                $cacheOrder['payment_last4s'] .= !empty($pay->cardTransaction->last4) ? $pay->cardTransaction->last4 . ', ' : '';
                                $cacheOrder['total_gross'] += $pay->amount / 100;
                                $cacheOrder['tipAmount'] += $pay->tipAmount / 100;
                                $cacheOrder['serviceCharge'] += isset($pay->serviceCharge->amount) ? $pay->serviceCharge->amount / 100 : 0;
                                $cacheOrder['tax_amount_pay_gross'] += $pay->taxAmount / 100;
                                $cacheOrder['card_type'] = $pay->cardTransaction->cardType && $cacheOrder['card_type'] === null ? $pay->cardTransaction->cardType : 'N/A';
                                $cacheOrder['entryType'] = $pay->cardTransaction->entryType && $cacheOrder['entryType'] === null ? $pay->cardTransaction->entryType : 'N/A';
                                $cacheOrder['type'] = $pay->cardTransaction->type && $cacheOrder['type'] === null ? $pay->cardTransaction->type : 'N/A';
                                $cacheOrder['state'] = $pay->cardTransaction->state && $cacheOrder['state'] === null ? $pay->cardTransaction->state : 'N/A';
                                $paymentCreatedTimestamp = substr($pay->clientCreatedTime, 0, strlen($pay->clientCreatedTime) - 3);
                                $cacheOrder['payment_date_iso_format'] = date(self::DATE_FORMAT_ISO, $paymentCreatedTimestamp);
                                $cacheOrder['payment_date'] = date($this->dateFormat, $paymentCreatedTimestamp);
                                $cacheOrder['payment_time'] = date("H:i", $paymentCreatedTimestamp);
                                $cacheOrder['payment_tender_labels'] .= !empty($pay->tender->label) ? $pay->tender->label . ', ' : '';
                                // check time period in which payments were created...
                                if(!isset($period_for_order_payments['startTimestamp']) || $paymentCreatedTimestamp < $period_for_order_payments['startTimestamp']) {
                                    $period_for_order_payments['startTimestamp'] = $paymentCreatedTimestamp;
                                }
                                if(!isset($period_for_order_payments['endTimestamp']) || $paymentCreatedTimestamp > $period_for_order_payments['endTimestamp']) {
                                    $period_for_order_payments['endTimestamp'] = $paymentCreatedTimestamp;
                                }
                                // ... and also specifically for each location.
                                if(!isset($period_for_order_payments[$merchantId]['startTimestamp']) || $paymentCreatedTimestamp < $period_for_order_payments[$merchantId]['startTimestamp']) {
                                    $period_for_order_payments[$merchantId]['startTimestamp'] = $paymentCreatedTimestamp;
                                }
                                if(!isset($period_for_order_payments[$merchantId]['endTimestamp']) || $paymentCreatedTimestamp > $period_for_order_payments[$merchantId]['endTimestamp']) {
                                    $period_for_order_payments[$merchantId]['endTimestamp'] = $paymentCreatedTimestamp;
                                }

                                $paymentCreatedDateTimestamp = strtotime($cacheOrder['payment_date']);
                                if($orderCreatedDateTimestamp != $paymentCreatedDateTimestamp) {
                                    $orderForCsv['paymentOriginalDate'] = $cacheOrder['payment_date'];
                                    $interestingOrderItemsForCsv = true;
                                }

                                if (isset($pay->refunds->elements) && is_array($pay->refunds->elements)) {
                                    foreach ($pay->refunds->elements as $refund) {
                                        if ($refund->clientCreatedTime >= $startDate_clover && $refund->clientCreatedTime <= $endDate_clover) {
                                            $tmp_order_refund_amount += $refund->amount;
                                            $cacheOrder['total_refund'] += $refund->amount / 100;
                                            $cacheOrder['total_refund_tax'] += $refund->taxAmount / 100;
                                            $cacheOrder['serviceCharge'] -= isset($refund->serviceChargeAmount->amount) ? $refund->serviceChargeAmount->amount / 100 : 0;

                                            $refund_orders_ids[$refund->id] = $refund->id;
                                        }
                                    }
                                }
                            }

                            $cacheOrder['payment_ids'] = !empty($cacheOrder['payment_ids']) ? rtrim($cacheOrder['payment_ids'], ', ') : 'N/A';
                            $cacheOrder['payment_last4s'] = !empty($cacheOrder['payment_last4s']) ? rtrim($cacheOrder['payment_last4s'], ', ') : 'N/A';
                            $cacheOrder['payment_tender_labels'] = !empty($cacheOrder['payment_tender_labels']) ? rtrim($cacheOrder['payment_tender_labels'], ', ') : 'N/A';

                            if(($tmp_order_total != $tmp_order_payments_amount) && ($tmp_order_total != $tmp_order_payments_amount - $tmp_order_refund_amount)) {
                                // if total on order is different to sum of payments - something strange is happening like overpaying.
                                // 1st IF is for case when items *are not* refunded individually ($atLeastOneRefundedItem == false), there is just order level refund.
                                // 2nd IF is for case when items *are* also refunded individually ($atLeastOneRefundedItem == true). then $order->total is modified by the number of line items being refunded.
                                $orders_refund_no_propagate[$cacheOrder['order']] = $cacheOrder['order'];
                            }
                        }

                        $cacheOrder['total'] = $cacheOrder['total_gross'] - $cacheOrder['total_refund'];
                        $cacheOrder['tax_amount_pay'] = $cacheOrder['tax_amount_pay_gross'] - $cacheOrder['total_refund_tax'];
                        $cacheOrder['total_minus_tax'] = $cacheOrder['total'] - $cacheOrder['tax_amount_pay'] - $cacheOrder['serviceCharge'];

                        $cacheOrder['discount_order_percent'] = 0; // percent na orderu
                        $cacheOrder['discount_order_amount'] = 0; // apsolutni iznos na orderu
                        $cacheOrder['discount_order_names'] = ''; // Imena svih ORDER popusta
                        if (isset($order->discounts) && isset($order->discounts->elements) && is_array($order->discounts->elements)) {
                            foreach ($order->discounts->elements as $discount) {
                                $cacheOrder['discount_order_percent'] += $discount->percentage;
                                $cacheOrder['discount_order_amount'] += $discount->amount;
                                $cacheOrder['discount_order_names'] .= !empty($discount->name) ? $discount->name . ', ' : '';
                            }
                            $cacheOrder['discount_order_names'] = rtrim($cacheOrder['discount_order_names'], ', ');
                            if($cacheOrder['discount_order_percent'] > 100) {
                                $cacheOrder['discount_order_percent'] = 100;
                            }
                        }
                        $cacheOrder['discount_order_amount'] = $cacheOrder['discount_order_amount'] / 100;

                        $orders_other_totals[$order->id] = array();
                        $orders_other_totals[$order->id]["total_cost"] = 0;
                        $orders_other_totals[$order->id]["total_price"] = 0;
                        $orders_other_totals[$order->id]["total_discount"] = 0;
                        $orders_other_totals[$order->id]["total_tax"] = 0;
                        $orders_other_totals[$order->id]["total_profit"] = 0;

                        // whether order is interesting for our testing.
                        if($cacheOrder['discount_order_percent'] != 0) {
                            $orderForCsv['orderDiscountPerc'] = 'YES';
                            $interestingOrderItemsForCsv = true;
                        }
                        if($cacheOrder['discount_order_amount'] != 0) {
                            $orderForCsv['orderDiscountAbs'] = 'YES';
                            $interestingOrderItemsForCsv = true;
                        }
                        if($cacheOrder['total_refund'] != 0) {
                            $orderForCsv['orderRefunded'] = 'YES';
                            $interestingOrderItemsForCsv = true;
                        }
                        if($cacheOrder['serviceCharge'] != 0) {
                            $orderForCsv['orderServiceCharge'] = 'YES';
                            $interestingOrderItemsForCsv = true;
                        }
                        if($cacheOrder['credit_amount'] != 0) {
                            $orderForCsv['orderCreditAmount'] = 'YES';
                            $interestingOrderItemsForCsv = true;
                        }

                        if (isset($order->lineItems) && isset($order->lineItems->elements) && is_array($order->lineItems->elements)) {
                            // Ove varijable su potrebne u IF-u odmah ispod za racunanje udijela ITEM-a u DISCOUNT_AMOUNT-u ORDER-a
                            $sum_price_final = 0;
                            $order_items_coefficient = array();
                            // oznacavamo sve item-e u order-u da je barem jedan (taj ili drugi) item refundiran u tom order-u
                            $atLeastOneRefundedItem = false;
                            if (!empty($cacheOrder["discount_order_amount"]) || (!empty($cacheOrder["total_refund"]) && !isset($orders_refund_no_propagate[$cacheOrder['order']]))) {
                                // prolazak kroz ITEM-e i cijelu logiku samo da bi dobili sumu price_final_minus_tax.
                                // neke stvari su nepotrebne u ovom koraku, ali jednostavnije je copy/paste
                                foreach ($order->lineItems->elements as $item) {
                                    $row = $cacheOrder;

                                    $row["refunded"] = $item->refunded;
                                    if ($row["refunded"]) {
                                        $atLeastOneRefundedItem = true;
                                    }
                                    $row["isRevenue"] = $item->isRevenue;
                                    $row["exchanged"] = $item->exchanged;

                                    $row["item_id"] = $item->item->id;
                                    $row["item"] = trim($item->name);
                                    $row["note"] = $item->note;
                                    $row["price"] = $item->price;
                                    if (isset($item->unitQty)) {
                                        // za slucaj da unitQty postoji, treba modificirati cijenu - unitQty se dijeli sa 1000 jer mislimo da je tako dobro...
                                        $row["price"] = round($item->price * $item->unitQty / 1000);
                                    }


                                    $row["discounts"] = 0; // percent na itemu
                                    $row["discounts_amount"] = 0; // absolutni iznos na itemu
                                    $row["discount_amount"] = 0; // ukupni absplutni iznos popusta
                                    $row["itm_disc_amount_from_perc_on_order"] = 0; // iznos discounta po itemu zbog % discounta sa ordera
                                    if (isset($item->discounts) && isset($item->discounts->elements) && is_array($item->discounts->elements)) {
                                        foreach ($item->discounts->elements as $discount) {
                                            $row["discounts"] += $discount->percentage;
                                            $row["discounts_amount"] += abs($discount->amount);
                                        }
                                    }
                                    if ($row["discounts"] > 100) {
                                        $row["discounts"] = 100;
                                    }


                                    // $row["discounts"]    percent na itemu
                                    // $row["discounts_amount"]   absolutni iznos na itemu
                                    //
                                    // iznos popusta na itemu iz discounta izraženog u percent NA ITEMU
                                    //             $row["itm_disc_amount_from_perc"] = round(($row["price"] + $row["modifications"]) / 100 * $row["discounts"] / 100, 2);
                                    // UKUPNI iznos popusta na itemu iz discounta izraženog u apsolutnoj vrijednosti na ITEMU i iz discounta izraženog u percent NA ITEMU
                                    //             $row["discount_amount_total_from_item_discounts"] = ( round(($row["price"] + $row["modifications"]) * $row["discounts"] / 100 + $row["discounts_amount"]) ) / 100;
                                    //             $row["discount_amount"] = $row["discount_amount_total_from_item_discounts"];


                                    $row["itm_disc_amount_from_perc"] = round(($row["price"] + $row["modifications"]) / 100 * $row["discounts"] / 100, 2); // iznos popusta na itemu iz discounta izraženog u percent
                                    $row["discount_amount"] = round(($row["price"] + $row["modifications"]) * $row["discounts"] / 100 + $row["discounts_amount"]);
                                    $tmp_price_final = round(($row["price"] + $row["modifications"]) - $row["discount_amount"], 2);
                                    $tmp_price_final = round($tmp_price_final - ($tmp_price_final * $cacheOrder["discount_order_percent"] / 100), 2);


                                    // ALTERNATIVNO
                                    $_tmp_price_final = round(($row["price"] + $row["modifications"]) / 100, 2);
                                    if ($row["refunded"] == "TRUE" || $row["exchanged"] == "TRUE") {
                                        $tmp_price_final = 0;
                                    }
                                    $row["_itm_disc_from_item_perc"] = round($_tmp_price_final * $row["discounts"] / 100, 2);
                                    $row["_itm_disc_from_item_abs"] = $row["discounts_amount"] / 100;
                                    $_tmp_price_final = $_tmp_price_final - $row["_itm_disc_from_item_perc"] - $row["_itm_disc_from_item_abs"];
                                    $row["_itm_disc_from_order_perc"] = round($_tmp_price_final * $row["discount_order_percent"] / 100, 2);
                                    $_tmp_price_final = $_tmp_price_final - $row["_itm_disc_from_order_perc"];
                                    $row["_tax_amount"] = 0;

                                    if ($this->apiType == self::API_TYPE_EU) {
                                        if (!$taxRemoved && isset($item->taxRates) && isset($item->taxRates->elements) && is_array($item->taxRates->elements)) {
                                            $row["_tax_rate"] = 0;
                                            $row["_tax_rates"] = array();
                                            foreach ($item->taxRates->elements as $taxRate) {
                                                $row["_tax_rate"] += $taxRate->rate / 10000000;
                                                $row["_tax_rates"][] = $taxRate->rate / 10000000;
                                            }
                                            $row["_tax_amount"] = round($_tmp_price_final - ($_tmp_price_final / (1 + $row["tax_rate"])), 2);
                                        }
                                        // ZA EU OVO JE GROSS AMOUNT
                                        $row["_price_final"] = $_tmp_price_final;
                                        // ZA EU OVO JE NET AMOUNT
                                        $row["_price_final_minus_tax"] = round(($_tmp_price_final - $row["_tax_amount"]), 2);
                                    } else if ($this->apiType == self::API_TYPE_US) {
                                        if (!$taxRemoved && isset($item->taxRates) && isset($item->taxRates->elements) && is_array($item->taxRates->elements)) {
                                            $row["_tax_rate"] = 0;
                                            $row["_tax_rates"] = array();
                                            foreach ($item->taxRates->elements as $taxRate) {
                                                $row["_tax_rate"] += $taxRate->rate / 10000000;
                                                $row["_tax_rates"][] = $taxRate->rate / 10000000;
                                            }
                                            $row["_tax_amount"] = round($_tmp_price_final * $row["_tax_rate"], 2);
                                        }
                                        // ZA USA OVO JE NET AMOUNT
                                        $row["_price_final_minus_tax"] = $_tmp_price_final;
                                        // ZA USA OVO JE GROSS AMOUNT
                                        $row["_price_final"] = round(($_tmp_price_final + $row["_tax_amount"]), 2);
                                    }


                                    // IZRAČUN CIJENE.
                                    // prvo price + modifications - TOTAL popust na ITEMU izražen
                                    //             $tmp_price_final = round(($row["price"] + $row["modifications"]) - $row["discount_amount"], 2);
                                    // onda još od toga maknuti iznos popusta sa ORDER level percent discounta
                                    //             $tmp_price_final = round($tmp_price_final - ( $tmp_price_final * $tmp["discount_order_percent"] / 100 ), 2);
                                    // iznos discounta po itemu zbog % discounta sa ordera
                                    //      $row["itm_disc_amount_from_perc_on_order"] = round($tmp_price_final / 100 * $tmp["discount_order_percent"] / 100, 2);
                                    // OVO POSTAJE UKUPNI POPUST
                                    // item level % discount
                                    // item level absolute amount
                                    // irder level % discount
                                    //              $row["discount_amount"] = $row["discount_amount_total_from_item_discounts"] + $row["itm_disc_amount_from_perc_on_order"];


                                    if ($row["refunded"] == "TRUE") {
                                        $tmp_price_final = 0;
                                    }
                                    if ($row["exchanged"] == "TRUE") {
                                        $tmp_price_final = 0;
                                    }

                                    $row["tmp_price_final"] = $tmp_price_final;


                                    // IZRAČUN TAX
                                    //                        $row["tax_amount"] = 0;
                                    //                        if (!$taxRemoved && isset($item->taxRates) && isset($item->taxRates->elements) && is_array($item->taxRates->elements)) {
                                    //                            $row["tax_amount"] = round($tmp_price_final * ((int) $item->taxRates->elements[0]->rate / 10000000));
                                    //                            $row["tax_rate"] = (int) $item->taxRates->elements[0]->rate / 10000000;
                                    //                        }

                                    $row["tax_amount"] = 0;
                                    if (!$taxRemoved && isset($item->taxRates) && isset($item->taxRates->elements) && is_array($item->taxRates->elements)) {
                                        if ($this->apiType == self::API_TYPE_EU) {
                                            $row["tax_rate"] = 0;
                                            $row["tax_rates"] = array();
                                            foreach ($item->taxRates->elements as $taxRate) {
                                                $row["tax_rate"] += $taxRate->rate / 10000000;
                                                $row["tax_rates"][] = $taxRate->rate / 10000000;
                                            }
                                            $row["tax_amount"] = round($tmp_price_final - ($tmp_price_final / (1 + $row["tax_rate"])), 2);
                                        } else if ($this->apiType == self::API_TYPE_US) {
                                            $row["tax_rate"] = 0;
                                            $row["tax_rates"] = array();
                                            foreach ($item->taxRates->elements as $taxRate) {
                                                $row["tax_rate"] += $taxRate->rate / 10000000;
                                                $row["tax_rates"][] = $taxRate->rate / 10000000;
                                            }
                                            $row["tax_amount"] = round($tmp_price_final * $row["tax_rate"], 2);
                                        }
                                    }


                                    $row["price_final_minus_tax"] = $tmp_price_final;

                                    $row["itm_disc_amount_from_perc_on_order"] = round($row["price_final_minus_tax"] / 100 * $cacheOrder["discount_order_percent"] / 100, 2); // iznos discounta po itemu zbog % discounta sa ordera
                                    $row["discount_amount"] = $row["discount_amount"] / 100 + $row["itm_disc_amount_from_perc_on_order"];


                                    if ($this->apiType == self::API_TYPE_EU) {
                                        // ZA EU OVO JE GROSS AMOUNT
                                        $row["price_final"] = $tmp_price_final;
                                        // ZA EU OVO JE NET AMOUNT
                                        $row["price_final_minus_tax"] = round(($tmp_price_final - $row["tax_amount"]), 2);
                                    } else if ($this->apiType == self::API_TYPE_US) {
                                        // ZA USA OVO JE NET AMOUNT
                                        $row["price_final_minus_tax"] = $tmp_price_final;
                                        // ZA USA OVO JE GROSS AMOUNT
                                        $row["price_final"] = round(($tmp_price_final + $row["tax_amount"]), 2);
                                    }

                                    $sum_price_final += $row["price_final_minus_tax"];
                                    // $item->id => uzmi za kljuc ID od ITEM-a unutar ORDER-a jer isti ITEM moze biti u vise ORDER-a.
                                    $order_items_coefficient[$item->id]['price_final_minus_tax'] = $row["price_final_minus_tax"];
                                }

                                // racunaj udio svakog ITEM-a u ORDER-u.
                                foreach ($order_items_coefficient as &$item_price_data) {
                                    $item_price_data['sum_price_final'] = $sum_price_final;
                                    if (!empty($sum_price_final)) {
                                        $item_price_data['coefficient'] = $item_price_data['price_final_minus_tax'] / $sum_price_final;
                                    } else {
                                        $item_price_data['coefficient'] = 0;
                                    }

                                    if (self::PROPAGATE_ORDER_REFUND) {
                                        // nema zakruzivanja jer ce biti naknadno?
                                        // minus (-) kod total_refund je jer je discount_order_amount negativan.
                                        $item_price_data['order_discount_amount_share'] = -1 * 100 * $item_price_data['coefficient'] * ($cacheOrder["discount_order_amount"] - $cacheOrder["total_refund"] + $cacheOrder["total_refund_tax"]);
                                    } else {
                                        $item_price_data['order_discount_amount_share'] = -1 * 100 * $item_price_data['coefficient'] * ($cacheOrder["discount_order_amount"]);
                                    }
                                }
                            } else {
                                // if we didn't go through line items, check just in case if there are refunded items.
                                foreach ($order->lineItems->elements as $item) {
                                    if ($item->refunded) {
                                        $atLeastOneRefundedItem = true;
                                        break;
                                    }
                                }
                            }

                            // sada idemo 'za pravo'.
                            foreach ($order->lineItems->elements as $item) {
                                $itemForCsv = $orderForCsv;

                                $cacheOrderItem = [];
                                $cacheOrderItem['order__id'] = $cacheOrder['order'];

                                $cacheOrderItem['item_id'] = $item->item->id;
                                if(!empty($this->cache->cacheItems[$merchantId . $item->item->id]['name'])) {
                                    $cacheOrderItem['item'] = $this->cache->cacheItems[$merchantId . $item->item->id]['name'];
                                } else {
                                    $cacheOrderItem['item'] = isset($item->name) ? trim($item->name) : 'N/A';
                                }
                                $cacheOrderItem['atLeastOneRefundedItem'] = (int)$atLeastOneRefundedItem;
                                $cacheOrderItem['refunded'] = isset($item->refunded) ? (int)$item->refunded : 0;
                                $cacheOrderItem['isRevenue'] = isset($item->isRevenue) ? (int)$item->isRevenue : 0;
                                $cacheOrderItem['exchanged'] = isset($item->exchanged) ? (int)$item->exchanged : 0;
                                $cacheOrderItem['noteItem'] = isset($item->note) ? str_replace(["\r\n","\n","\r"], ' ', $item->note) : '';

                                $cacheOrderItem['alternateName'] = !empty($this->cache->cacheItems[$merchantId . $item->item->id]['alternateName']) ? $this->cache->cacheItems[$merchantId . $item->item->id]['alternateName'] : 'N/A';
                                $cacheOrderItem['sku'] = !empty($this->cache->cacheItems[$merchantId . $item->item->id]['sku']) ? $this->cache->cacheItems[$merchantId . $item->item->id]['sku'] : 'N/A';
                                $cacheOrderItem['itemCode'] = !empty($this->cache->cacheItems[$merchantId . $item->item->id]['code']) ? $this->cache->cacheItems[$merchantId . $item->item->id]['code'] : 'N/A';
                                $cacheOrderItem['stockCount'] = !empty($this->cache->cacheItems[$merchantId . $item->item->id]['stockCount']) ? $this->cache->cacheItems[$merchantId . $item->item->id]['stockCount'] : 0;
                                $cacheOrderItem['category'] = !empty($this->cache->cacheItems[$merchantId . $item->item->id]['category']) ? $this->cache->cacheItems[$merchantId . $item->item->id]['category'] : 'Uncategorised';
                                $cacheOrderItem['category_1'] = !empty($this->cache->cacheItems[$merchantId . $item->item->id]['category_1']) ? $this->cache->cacheItems[$merchantId . $item->item->id]['category_1'] : 'Uncategorised';
                                $cacheOrderItem['category_2'] = !empty($this->cache->cacheItems[$merchantId . $item->item->id]['category_2']) ? $this->cache->cacheItems[$merchantId . $item->item->id]['category_2'] : 'Uncategorised';
                                $cacheOrderItem['category_3'] = !empty($this->cache->cacheItems[$merchantId . $item->item->id]['category_3']) ? $this->cache->cacheItems[$merchantId . $item->item->id]['category_3'] : 'Uncategorised';
                                $cacheOrderItem['tag'] = !empty($this->cache->cacheItems[$merchantId . $item->item->id]['tag']) ? $this->cache->cacheItems[$merchantId . $item->item->id]['tag'] : 'Unlabeled';
                                $cacheOrderItem['tag_1'] = !empty($this->cache->cacheItems[$merchantId . $item->item->id]['tag_1']) ? $this->cache->cacheItems[$merchantId . $item->item->id]['tag_1'] : 'Unlabeled';
                                $cacheOrderItem['tag_2'] = !empty($this->cache->cacheItems[$merchantId . $item->item->id]['tag_2']) ? $this->cache->cacheItems[$merchantId . $item->item->id]['tag_2'] : 'Unlabeled';
                                $cacheOrderItem['tag_3'] = !empty($this->cache->cacheItems[$merchantId . $item->item->id]['tag_3']) ? $this->cache->cacheItems[$merchantId . $item->item->id]['tag_3'] : 'Unlabeled';

                                // za slucaj da unitQty postoji, treba modificirati cijenu - unitQty se dijeli sa 1000 jer mislimo da je tako dobro...
                                $cacheOrderItem['price'] = isset($item->unitQty) ? round($item->price * $item->unitQty / 1000) : $item->price;
                                // HRVOJE - let's rock on refunds!!!
                                $cacheOrderItem['orig_price'] = $cacheOrderItem['price'] / 100;
                                /// HRVOJE - do tuda
                                $cacheOrderItem['cost'] = isset($this->cache->cacheItems[$merchantId . $item->item->id]['cost']) ? $this->cache->cacheItems[$merchantId . $item->item->id]['cost'] : 0;
                                $cacheOrderItem['cost'] = isset($item->unitQty) ? round($cacheOrderItem['cost'] * $item->unitQty / 1000) : $cacheOrderItem['cost'];
                                $cacheOrderItem['quantity'] = isset($item->unitQty) ? $item->unitQty / 1000 : 1;

                                $cacheOrderItem['modifications'] = 0;
                                $cacheOrderItem['modification_names'] = ''; // string with names of all item's modifications.
                                $cacheOrderItem['discounts'] = 0; // percent na itemu
                                $cacheOrderItem['discounts_amount'] = 0; // absolutni iznos na itemu
                                $cacheOrderItem['discount_amount'] = 0; // ukupni absplutni iznos popusta
                                $cacheOrderItem['discount_item_names'] = ''; // Imena svih ITEM popusta
                                $cacheOrderItem['itm_disc_amount_from_perc_on_order'] = 0; // iznos discounta po itemu zbog % discounta sa ordera


                                if (isset($item->discounts) && isset($item->discounts->elements) && is_array($item->discounts->elements)) {
                                    foreach ($item->discounts->elements as $discount) {
                                        $cacheOrderItem['discounts'] += $discount->percentage;
                                        $cacheOrderItem['discounts_amount'] += abs($discount->amount);
                                        $cacheOrderItem['discount_item_names'] .= !empty($discount->name) ? $discount->name . ', ' : '';
                                    }
                                    $cacheOrderItem['discount_item_names'] = rtrim($cacheOrderItem['discount_item_names'], ', ');
                                    if($cacheOrderItem['discounts'] > 100) {
                                        $cacheOrderItem['discounts'] = 100;
                                    }
                                }
                                if (isset($item->modifications) && isset($item->modifications->elements) && is_array($item->modifications->elements)) {
                                    $modification_group_send = array();
                                    $modification_group_send['id'] = "";
                                    $modification_group_send['name'] = "";
                                    $modification_group_send['alternateName'] = "";
                                    $modification_group_send['amount'] = 0;
                                    $modification_group_send['merchant_id'] = $merchantId;
                                    $modification_group_send['merchantName'] = $this->merchantNames[$merchantId];
                                    foreach ($item->modifications->elements as $modification) {
                                        $cacheOrderItem['modifications'] += $modification->amount;

                                        $modification_send = array();
                                        $modification_send['id'] = isset($modification->modifier->id) ? $modification->modifier->id : 'N/A';
                                        $modification_send['name'] = isset($modification->name) ? $modification->name : 'N/A';
                                        $modification_send['merchant_id'] = $merchantId;
                                        $modification_send['item_id'] = $cacheOrderItem['item_id'];
                                        $modification_send['merchantName'] = $this->merchantNames[$merchantId];
                                        $modification_send['itemName'] = isset($item->name) ? trim($item->name) : 'N/A';
                                        $modification_send['itemSKU'] = isset($this->cache->cacheItems[$merchantId . $cacheOrderItem['item_id']]['sku']) ? $this->cache->cacheItems[$merchantId . $cacheOrderItem['item_id']]['sku'] : 'N/A';
                                        $modification_send['alternateName'] = isset($modification->alternateName) ? $modification->alternateName : 'N/A';
                                        $modification_send['amount'] = isset($modification->amount) ? $modification->amount / 100 : 0;

                                        $modification_group_send['id'] .= $modification_send['id'] . ', ';
                                        $modification_group_send['name'] .= $modification_send['name'] . ', ';
                                        $modification_group_send['alternateName'] .= $modification_send['alternateName'] . ', ';
                                        $modification_group_send['amount'] += $modification_send['amount'];

                                        // sends each appearance od modification as a seperate row.
                                        $cacheModifications[] = $modification_send;

                                        $cacheOrderItem['modification_names'] .= $modification_send['name'] . ', ';
                                    }
                                    // sends modifications grouped by an order item they are attached to.
                                    $modification_group_send['id'] = rtrim($modification_group_send['id'], ', ');
                                    $modification_group_send['name'] = rtrim($modification_group_send['name'], ', ');
                                    $modification_group_send['alternateName'] = rtrim($modification_group_send['alternateName'], ', ');
                                    $cacheModificationGroups[] = $modification_group_send;

                                    $cacheOrderItem['modification_names'] = rtrim($cacheOrderItem['modification_names'], ', ');
                                }


                                // HRVOJE - let's rock on refunds!!!
                                $cacheOrderItem['orig_modifications'] = $cacheOrderItem['modifications'];
                                /// HRVOJE - do tuda
                                $cacheOrderItem['discounts'] = $cacheOrderItem['discounts'] > 100 ? 100 : $cacheOrderItem['discounts'];


                                // $row["discounts"]    percent na itemu
                                // $row["discounts_amount"]   absolutni iznos na itemu
                                //
                                // iznos popusta na itemu iz discounta izraženog u percent NA ITEMU
                                //             $row["itm_disc_amount_from_perc"] = round(($row["price"] + $row["modifications"]) / 100 * $row["discounts"] / 100, 2);
                                // UKUPNI iznos popusta na itemu iz discounta izraženog u apsolutnoj vrijednosti na ITEMU i iz discounta izraženog u percent NA ITEMU
                                //             $row["discount_amount_total_from_item_discounts"] = ( round(($row["price"] + $row["modifications"]) * $row["discounts"] / 100 + $row["discounts_amount"]) ) / 100;
                                //             $row["discount_amount"] = $row["discount_amount_total_from_item_discounts"];


                                $tmp_price_final = 0;
                                if ($this->apiType == self::API_TYPE_US) {
                                    $cacheOrderItem["itm_disc_amount_from_perc"] = round(($cacheOrderItem["price"] + $cacheOrderItem["modifications"]) / 100 * $cacheOrderItem["discounts"] / 100, 2); // iznos popusta na itemu iz discounta izraženog u percent
                                    // izuzeci za refunde koji su preplaceni! Crazy Apes...
                                    if ($merchantId == "6J4DNCH3ZGMSJ" && $order->id == "2J2GKRQT1KFM0") {
                                        $cacheOrderItem["discount_amount"] = round(($cacheOrderItem["price"] + $cacheOrderItem["modifications"]) * $cacheOrderItem["discounts"] / 100 + $cacheOrderItem["discounts_amount"]);
                                    } else {
                                        // za sve ostale
                                        $cacheOrderItem["discount_amount"] = round(($cacheOrderItem["price"] + $cacheOrderItem["modifications"]) * $cacheOrderItem["discounts"] / 100 +
                                            $cacheOrderItem["discounts_amount"] + $order_items_coefficient[$item->id]['order_discount_amount_share']);
                                    }

                                    $tmp_price_final = round(($cacheOrderItem["price"] + $cacheOrderItem["modifications"]) - $cacheOrderItem["discount_amount"], 2);
                                    $tmp_price_final = round($tmp_price_final - ($tmp_price_final * $cacheOrder["discount_order_percent"] / 100), 2);
                                } else if ($this->apiType == self::API_TYPE_EU) {
                                    $cacheOrderItem["itm_disc_amount_from_perc"] = round(($cacheOrderItem["price"] + $cacheOrderItem["modifications"]) / 100 * $cacheOrderItem["discounts"] / 100, 2); // iznos popusta na itemu iz discounta izraženog u percent
                                    $cacheOrderItem["discount_amount"] = round(($cacheOrderItem["price"] + $cacheOrderItem["modifications"]) * $cacheOrderItem["discounts"] / 100 +
                                        $cacheOrderItem["discounts_amount"] + $order_items_coefficient[$item->id]['order_discount_amount_share']);
                                    $tmp_price_final = round(($cacheOrderItem["price"] + $cacheOrderItem["modifications"]) - $cacheOrderItem["discount_amount"], 2);
                                    $tmp_price_final = round($tmp_price_final - ($tmp_price_final * $cacheOrder["discount_order_percent"] / 100), 2);
                                }

                                // nekad daju merchanti više popusta pa da ne ide iznos u minus
                                if ($tmp_price_final < 0) {
                                    $tmp_price_final = 0;
                                }


                                // ALTERNATIVNO
                                $_tmp_price_final = round(($cacheOrderItem["price"] + $cacheOrderItem["modifications"]) / 100, 2);
                                if ($cacheOrderItem["refunded"] == "TRUE" || $cacheOrderItem["exchanged"] == "TRUE") {
                                    $tmp_price_final = 0;
                                }
                                $cacheOrderItem["_itm_disc_from_item_perc"] = round($_tmp_price_final * $cacheOrderItem["discounts"] / 100, 2);
                                $cacheOrderItem["_itm_disc_from_item_abs"] = $cacheOrderItem["discounts_amount"] / 100;
                                $_tmp_price_final = $_tmp_price_final - $cacheOrderItem["_itm_disc_from_item_perc"] - $cacheOrderItem["_itm_disc_from_item_abs"];
                                $cacheOrderItem["_itm_disc_from_order_perc"] = round($_tmp_price_final * $cacheOrder["discount_order_percent"] / 100, 2);
                                $_tmp_price_final = $_tmp_price_final - $cacheOrderItem["_itm_disc_from_order_perc"];

                                $cacheOrderItem["_tax_amount"] = 0;
                                $cacheOrderItem["_tax_rate"] = 0;
                                $cacheOrderItem["_tax_rates"] = 0;
                                if ($this->apiType == self::API_TYPE_EU) {
                                    if (!$taxRemoved && isset($item->taxRates) && isset($item->taxRates->elements) && is_array($item->taxRates->elements)) {
                                        $cacheOrderItem["_tax_rate"] = 0;
                                        $cacheOrderItem["_tax_rates"] = '';
                                        foreach ($item->taxRates->elements as $taxRate) {
                                            $cacheOrderItem["_tax_rate"] += $taxRate->rate / 10000000;
                                            $cacheOrderItem["_tax_rates"] .= $taxRate->rate / 10000000 . ', ';
                                        }
                                        $cacheOrderItem["_tax_amount"] = round($_tmp_price_final - ($_tmp_price_final / (1 + $cacheOrderItem["_tax_rate"])), 2);
                                        $cacheOrderItem["_tax_rates"] = rtrim($cacheOrderItem["_tax_rates"], ', ');
                                    }
                                    // ZA EU OVO JE GROSS AMOUNT
                                    $cacheOrderItem["_price_final"] = $_tmp_price_final;
                                    // ZA EU OVO JE NET AMOUNT
                                    $cacheOrderItem["_price_final_minus_tax"] = round(($_tmp_price_final - $cacheOrderItem["_tax_amount"]), 2);
                                } else if ($this->apiType == self::API_TYPE_US) {
                                    if (!$taxRemoved && isset($item->taxRates) && isset($item->taxRates->elements) && is_array($item->taxRates->elements)) {
                                        $cacheOrderItem["_tax_rate"] = 0;
                                        $cacheOrderItem["_tax_rates"] = '';
                                        foreach ($item->taxRates->elements as $taxRate) {
                                            $cacheOrderItem["_tax_rate"] += $taxRate->rate / 10000000;
                                            $cacheOrderItem["_tax_rates"] .= $taxRate->rate / 10000000 . ', ';
                                        }
                                        $cacheOrderItem["_tax_amount"] = round($_tmp_price_final * $cacheOrderItem["_tax_rate"], 2);
                                        $cacheOrderItem["_tax_rates"] = rtrim($cacheOrderItem["_tax_rates"], ', ');
                                    }
                                    // ZA USA OVO JE NET AMOUNT
                                    $cacheOrderItem["_price_final_minus_tax"] = $_tmp_price_final;
                                    // ZA USA OVO JE GROSS AMOUNT
                                    $cacheOrderItem["_price_final"] = round(($_tmp_price_final + $cacheOrderItem["_tax_amount"]), 2);
                                }


                                // IZRAČUN CIJENE.
                                // prvo price + modifications - TOTAL popust na ITEMU izražen
                                //             $tmp_price_final = round(($row["price"] + $row["modifications"]) - $row["discount_amount"], 2);
                                // onda još od toga maknuti iznos popusta sa ORDER level percent discounta
                                //             $tmp_price_final = round($tmp_price_final - ( $tmp_price_final * $tmp["discount_order_percent"] / 100 ), 2);
                                // iznos discounta po itemu zbog % discounta sa ordera
                                //      $row["itm_disc_amount_from_perc_on_order"] = round($tmp_price_final / 100 * $tmp["discount_order_percent"] / 100, 2);
                                // OVO POSTAJE UKUPNI POPUST
                                // item level % discount
                                // item level absolute amount
                                // irder level % discount
                                //              $row["discount_amount"] = $row["discount_amount_total_from_item_discounts"] + $row["itm_disc_amount_from_perc_on_order"];

                                if ($cacheOrderItem["refunded"]) {
                                    $tmp_price_final = 0;
                                    $cacheOrderItem["price"] = 0;
                                    $cacheOrderItem["cost"] = 0;
                                }
                                if ($cacheOrderItem["exchanged"]) {
                                    $tmp_price_final = 0;
                                    $cacheOrderItem["price"] = 0;
                                    $cacheOrderItem["cost"] = 0;
                                }

                                $cacheOrderItem["tmp_price_final"] = $tmp_price_final;


                                $cacheOrderItem["tax_amount"] = 0;
                                $cacheOrderItem["tax_rate"] = 0;
                                $cacheOrderItem["tax_rates"] = 0;
                                $cacheOrderItem["tax_name"] = '';
                                $cacheOrderItem["tax_names"] = '';
                                if (!$taxRemoved && isset($item->taxRates) && isset($item->taxRates->elements) && is_array($item->taxRates->elements)) {
                                    if ($this->apiType == self::API_TYPE_EU) {
                                        $cacheOrderItem["tax_rate"] = 0;
                                        $cacheOrderItem["tax_rates"] = '';
                                        foreach ($item->taxRates->elements as $taxRate) {
                                            $cacheOrderItem["tax_rate"] += $taxRate->rate / 10000000;
                                            $cacheOrderItem["tax_rates"] .= $taxRate->rate / 10000000 . ', ';
                                            $cacheOrderItem["tax_name"] = $taxRate->name;
                                            $cacheOrderItem["tax_names"] .= $taxRate->name . ', ';
                                        }
                                        $cacheOrderItem["tax_amount"] = round($tmp_price_final - ($tmp_price_final / (1 + $cacheOrderItem["tax_rate"])), 2);
                                        $cacheOrderItem["tax_rates"] = rtrim($cacheOrderItem["tax_rates"], ', ');
                                        $cacheOrderItem["tax_names"] = rtrim($cacheOrderItem["tax_names"], ', ');
                                    } else if ($this->apiType == self::API_TYPE_US) {
                                        $cacheOrderItem["tax_rate"] = 0;
                                        $cacheOrderItem["tax_rates"] = '';
                                        foreach ($item->taxRates->elements as $taxRate) {
                                            $cacheOrderItem["tax_rate"] += $taxRate->rate / 10000000;
                                            $cacheOrderItem["tax_rates"] .= $taxRate->rate / 10000000 . ', ';
                                            $cacheOrderItem["tax_name"] = $taxRate->name;
                                            $cacheOrderItem["tax_names"] .= $taxRate->name . ', ';
                                        }
                                        $cacheOrderItem["tax_amount"] = round($tmp_price_final * $cacheOrderItem["tax_rate"], 2);
                                        $cacheOrderItem["tax_rates"] = rtrim($cacheOrderItem["tax_rates"], ', ');
                                        $cacheOrderItem["tax_names"] = rtrim($cacheOrderItem["tax_names"], ', ');
                                    }
                                }


                                $cacheOrderItem["price_final_minus_tax"] = $tmp_price_final;

                                $cacheOrderItem["itm_disc_amount_from_perc_on_order"] = round($cacheOrderItem["price_final_minus_tax"] / 100 * $cacheOrder["discount_order_percent"] / 100, 2); // iznos discounta po itemu zbog % discounta sa ordera
                                $cacheOrderItem["discount_amount"] = $cacheOrderItem["discount_amount"] / 100 + $cacheOrderItem["itm_disc_amount_from_perc_on_order"];


                                if ($this->apiType == self::API_TYPE_EU) {
                                    // ZA EU OVO JE GROSS AMOUNT
                                    $cacheOrderItem["price_final"] = $tmp_price_final;
                                    // ZA EU OVO JE NET AMOUNT
                                    $cacheOrderItem["price_final_minus_tax"] = round(($tmp_price_final - $cacheOrderItem["tax_amount"]), 2);
                                } else if ($this->apiType == self::API_TYPE_US) {
                                    // ZA USA OVO JE NET AMOUNT
                                    $cacheOrderItem["price_final_minus_tax"] = $tmp_price_final;
                                    // ZA USA OVO JE GROSS AMOUNT
                                    $cacheOrderItem["price_final"] = round(($tmp_price_final + $cacheOrderItem["tax_amount"]), 2);
                                }


                                $cacheOrderItem["profit"] = $cacheOrderItem["price_final"] - $cacheOrderItem["cost"] - $cacheOrderItem["tax_amount"];

                                if ($cacheOrderItem["refunded"] || $cacheOrderItem["exchanged"]) {
                                    // do nothing
                                } else {
                                    $orders_other_totals[$order->id]["total_cost"] += $cacheOrderItem["cost"];
                                    $orders_other_totals[$order->id]["total_price"] += $cacheOrderItem["price"];
                                    $orders_other_totals[$order->id]["total_discount"] += $cacheOrderItem["discount_amount"];
                                    $orders_other_totals[$order->id]["total_tax"] += $cacheOrderItem["tax_amount"];
                                    $orders_other_totals[$order->id]["total_profit"] += $cacheOrderItem["profit"];
                                }


                                $cacheOrderItem["modifications"] = $cacheOrderItem["modifications"] / 100;
                                $cacheOrderItem["cost"] = $cacheOrderItem["cost"] / 100;
                                $cacheOrderItem["price"] = $cacheOrderItem["price"] / 100;
                                $cacheOrderItem["discounts_amount"] = $cacheOrderItem["discounts_amount"] / 100;
                                $cacheOrderItem["tax_amount"] = round(($cacheOrderItem["tax_amount"] / 100), 2);
                                $cacheOrderItem["price_final"] = $cacheOrderItem["price_final"] / 100;
                                $cacheOrderItem["price_minus_tax"] = round(($cacheOrderItem["price_final_minus_tax"] / 100), 2);
                                $cacheOrderItem["price_final_minus_tax"] = $cacheOrderItem["price_minus_tax"]; // - $cacheOrderItem["itm_disc_amount_from_perc_on_order"];
                                $cacheOrderItem["profit"] = $cacheOrderItem["profit"] / 100;


                                //ALTERNATIVE
                                // $cacheOrderItem["tax_amount"] = $cacheOrderItem["_tax_amount"] ;
                                // $cacheOrderItem["price_final"] = $cacheOrderItem["_price_final"];
                                // $cacheOrderItem["price_final_minus_tax"] =  $cacheOrderItem["_price_final_minus_tax"];
                                $cacheOrderItem["item_total_discount_amount"] = $cacheOrderItem["_itm_disc_from_item_perc"] + $cacheOrderItem["_itm_disc_from_item_abs"] + $cacheOrderItem["_itm_disc_from_order_perc"];

                                if ($merchantId == "6J4DNCH3ZGMSJ" && $order->id == "2J2GKRQT1KFM0") {
                                    $cacheOrderItem["discount_amount"] = $cacheOrderItem["item_total_discount_amount"];
                                } else {
                                    // za sve ostale
                                    $cacheOrderItem["discount_amount"] = $cacheOrderItem["item_total_discount_amount"] + round($order_items_coefficient[$item->id]['order_discount_amount_share'] / 100, 2);
                                }


                                if ($order->id == "X4XZSK5ZBPXFP") {
                                }

                                // whether item is interesting for our testing.
                                if($cacheOrderItem['discounts'] != 0) {
                                    $itemForCsv['itemDiscountPerc'] = 'YES';
                                    $interestingOrderItemsForCsv = true;
                                }
                                if($cacheOrderItem['discounts_amount'] != 0) {
                                    $itemForCsv['itemDiscountAbs'] = 'YES';
                                    $interestingOrderItemsForCsv = true;
                                }
                                if($cacheOrderItem['exchanged'] != 0) {
                                    $itemForCsv['itemExchanged'] = 'YES';
                                    $interestingOrderItemsForCsv = true;
                                }
                                if($cacheOrderItem['refunded'] != 0) {
                                    $itemForCsv['itemRefunded'] = 'YES';
                                    $interestingOrderItemsForCsv = true;
                                }
                                if($cacheOrderItem['isRevenue'] == 0) {
                                    $itemForCsv['itemIsRevenue'] = 'NO';
                                    $interestingOrderItemsForCsv = true;
                                }

                                if($interestingOrderItemsForCsv) {
                                    $ordersItemsForCsv[] = implode(';', $itemForCsv);
                                }

                                $cacheOrderItems[] = $cacheOrderItem;
                                $order_items_index[$cacheOrder['order']][] = count($cacheOrderItems) - 1;
                                $cacheOrderItem = null;
                                $item = null;
                            }
                        }

                        $cacheOrders[] = $cacheOrder;
                        $orders_index[$cacheOrder['order']] = count($cacheOrders) - 1;
                        $cacheOrder = null;
                        $order = null;
                    }
                }
            }
        }
        
    }

    private function getRefunds(&$cacheOrders, $refund_orders_ids, &$ordersItemsForCsv, $addDayNumberToDay = false) {
        // cached data we will use.
        $this->cache->updateLocalCacheData('devices');
        $this->cache->updateLocalCacheData('employees');

        $refundsArray = null;
        $refundsMerchant = null;
        $refunds = null;
        $merchantId = null;
        $this->callApi('urlRefunds', $refundsArray, self::MULTI_CHUNK_FOR_SINGLE_LOW);
        foreach($refundsArray as $merchantId => $refundsMerchant) {

            $this->merchants[$merchantId]->setTimezone();

            foreach($refundsMerchant as $refunds) {
                if (isset($refunds->elements) && is_array($refunds->elements)) {
                    foreach ($refunds->elements as $refund) {
                        // if ($refund->result != "SUCCESS") {
                        //     continue;
                        // }

                        if (isset($refund_orders_ids[$refund->id])) {
                            // ako smo ovaj REFUND vec ukljucili u ORDERS, nemoj ga duplati i ovdje.
                            continue;
                        }

                        $cacheOrder = [];
                        $cacheOrder['trx_type'] = "REFUND";
                        $cacheOrder['merchantID'] = $merchantId;
                        $cacheOrder['merchantName'] = $this->merchantNames[$merchantId];
                        $cacheOrder['employee_id'] = $refund->employee->id;
                        $cacheOrder['employee'] = !empty($refund->employee->id) && !empty($this->cache->cacheEmployees[$merchantId . $refund->employee->id]['name']) ? $this->cache->cacheEmployees[$merchantId . $refund->employee->id]['name'] : 'N/A';
                        $cacheOrder['device_id'] = !empty($refund->device->id) ? $refund->device->id : '';
                        $cacheOrder['device_name'] = !empty($refund->device->id) && !empty($this->cache->cacheDevices[$merchantId . $refund->device->id]['name']) ? $this->cache->cacheDevices[$merchantId . $refund->device->id]['name'] : 'N/A';
                        $cacheOrder['device_model'] = !empty($refund->device->id) && !empty($this->cache->cacheDevices[$merchantId . $refund->device->id]['model']) ? $this->cache->cacheDevices[$merchantId . $refund->device->id]['model'] : 'N/A';
                        $cacheOrder['device_serial'] = !empty($refund->device->id) && !empty($this->cache->cacheDevices[$merchantId . $refund->device->id]['serial']) ? $this->cache->cacheDevices[$merchantId . $refund->device->id]['serial'] : 'N/A';
                        $cacheOrder['customerName'] = 'N/A';
                        $cacheOrder['customerSince'] = 0;
                        $cacheOrder['orderType'] = "N/A";
                        $cacheOrder['orderTitle'] = "N/A";
                        $orderCreatedTimestamp = substr($refund->clientCreatedTime, 0, strlen($refund->clientCreatedTime) - 3);
                        $cacheOrder['original_date'] = date($this->dateFormat, $orderCreatedTimestamp);
                        $cacheOrder['date_iso_format'] = date(self::DATE_FORMAT_ISO, $orderCreatedTimestamp - 3600 * $this->hourShifts[$merchantId]);
                        $cacheOrder['date'] = date($this->dateFormat, $orderCreatedTimestamp - 3600 * $this->hourShifts[$merchantId]);
                        $cacheOrder['hour'] = date("H", $orderCreatedTimestamp);
                        $cacheOrder['hour_numeric'] = (int)$cacheOrder['hour'];
                        $cacheOrder['hour'] = $this->transform_hour_to_AM_PM($cacheOrder['hour']);
                        $cacheOrder['time'] = date("H:i", $orderCreatedTimestamp);
                        $cacheOrder['half_hour'] = $this->getHourPart(substr($cacheOrder['time'], -2));
                        $cacheOrder['daypart'] = $this->getDayPart($cacheOrder['time'], $cacheOrder['merchantID']);
                        $cacheOrder['day'] = date("l", $orderCreatedTimestamp - 3600 * $this->hourShifts[$merchantId]);
                        $cacheOrder['day_of_week'] = date("w", $orderCreatedTimestamp);
                        if($addDayNumberToDay) {
                            $cacheOrder['day'] = $cacheOrder['day_of_week'] . '-' . $cacheOrder['day'];
                        }
                        $cacheOrder['week'] = date("W", $orderCreatedTimestamp);
                        $cacheOrder['year'] = date("Y", $orderCreatedTimestamp);

                        $cacheOrder['paymentLabel'] = isset($refund->payment->tender->label) ? $refund->payment->tender->label : 'N/A';
                        $cacheOrder['total_order'] = 0;
                        $cacheOrder['dummy'] = 1;
                        $cacheOrder['cost_and_tax'] = 0;
                        $cacheOrder['payment_profit'] = 0;

                        $cacheOrder['order'] = isset($refund->orderRef->id) ? $refund->orderRef->id : 'N/A';
                        $cacheOrder['taxRemoved'] = isset($refund->orderRef->taxRemoved) ? (int)$refund->orderRef->taxRemoved : 0;
                        $cacheOrder['isVat'] = isset($refund->orderRef->isVat) ? (int)$refund->orderRef->isVat : 0;
                        $cacheOrder['payType'] = !empty($refund->orderRef->payType) ? $refund->orderRef->payType : 'N/A';
                        $cacheOrder['manualTransaction'] = isset($refund->orderRef->manualTransaction) ? (int)$refund->orderRef->manualTransaction : 0;

                        $cacheOrder['total'] = 0;
                        $cacheOrder['tipAmount'] = 0;
                        $cacheOrder['serviceCharge'] = 0;
                        $cacheOrder['tax_amount_pay'] = 0;
                        $cacheOrder['total_gross'] = 0;
                        $cacheOrder['tax_amount_pay_gross'] = 0;
                        $cacheOrder['total_refund'] = $refund->amount / 100;
                        $cacheOrder['total_refund_tax'] = $refund->taxAmount / 100;
                        $cacheOrder['credit_amount'] = 0;
                        $cacheOrder['credit_taxamount'] = 0;

                        $cacheOrder['discount_order_percent'] = 0;
                        $cacheOrder['discount_order_amount'] = 0;

                        $cacheOrder['payment_date'] = date("Y-m-d", $orderCreatedTimestamp);
                        $cacheOrder['payment_time'] = date("H:i", $orderCreatedTimestamp);

                        $cacheOrder['card_type'] = !empty($refund->payment->cardTransaction->cardType) ? $refund->payment->cardTransaction->cardType : 'N/A';
                        $cacheOrder['entryType'] = !empty($refund->payment->cardTransaction->entryType) ? $refund->payment->cardTransaction->entryType : 'N/A';
                        $cacheOrder['type'] = !empty($refund->payment->cardTransaction->type) ? $refund->payment->cardTransaction->type : 'N/A';
                        $cacheOrder['state'] = !empty($refund->payment->cardTransaction->state) ? $refund->payment->cardTransaction->state : 'N/A';

                        $cacheOrder['total'] = $cacheOrder['total_gross'] - $cacheOrder['total_refund'];
                        $cacheOrder['tax_amount_pay'] = $cacheOrder['tax_amount_pay_gross'] - $cacheOrder['total_refund_tax'];
                        $cacheOrder['total_minus_tax'] = $cacheOrder['total'] - $cacheOrder['tax_amount_pay'] - $cacheOrder['serviceCharge'];


                        $orderForCsv = [
                            'market' => $this->apiType,
                            'merchantID' => $cacheOrder['merchantID'],
                            'merchantTier' => !empty($_SESSION[$cacheOrder['merchantID']]['merchantInfo']['tierName']) ? (string)$_SESSION[$cacheOrder['merchantID']]['merchantInfo']['tierName'] : 'STARTER',
                            'trxType' => $cacheOrder['trx_type'],
                            'orderID' => $cacheOrder['order'],
                            'orderOriginalDate' => $cacheOrder['original_date'],
                            'orderDiscountPerc' => 'NO',
                            'orderDiscountAbs' => 'NO',
                            'orderRefunded' => 'YES',
                            'orderServiceCharge' => 'NO',
                            'orderCreditAmount' => 'NO',
                            'paymentOriginalDate' => '',
                            'itemDiscountPerc' => 'NO',
                            'itemDiscountAbs' => 'NO',
                            'itemExchanged' => 'NO',
                            'itemRefunded' => 'NO',
                            'itemIsRevenue' => 'NO',
                        ];
                        $ordersItemsForCsv[] = implode(';', $orderForCsv);

                        $cacheOrders[] = $cacheOrder;

                        $cacheOrder = null;
                        $refund = null;
                    }
                }
            }
        }
    }

    private function getCredits(&$cacheOrders, &$ordersItemsForCsv, $addDayNumberToDay = false) {
        // cached data we will use.
        $this->cache->updateLocalCacheData('devices');
        $this->cache->updateLocalCacheData('employees');

        $creditsArray = null;
        $creditsMerchant = null;
        $credits = null;
        $merchantId = null;
        $this->callApi('urlCredits', $creditsArray, self::MULTI_CHUNK_FOR_SINGLE_LOW);
        foreach($creditsArray as $merchantId => $creditsMerchant) {

            $this->merchants[$merchantId]->setTimezone();

            foreach($creditsMerchant as $credits) {
                if (isset($credits->elements) && is_array($credits->elements)) {
                    foreach ($credits->elements as $credit) {
                        $cacheOrder = [];
                        $cacheOrder['trx_type'] = "CREDIT";
                        $cacheOrder['merchantID'] = $merchantId;
                        $cacheOrder['merchantName'] = $this->merchantNames[$merchantId];
                        $cacheOrder['employee_id'] = $credit->employee->id;
                        $cacheOrder['employee'] = !empty($credit->employee->id) && !empty($this->cache->cacheEmployees[$merchantId . $credit->employee->id]['name']) ? $this->cache->cacheEmployees[$merchantId . $credit->employee->id]['name'] : 'N/A';
                        $cacheOrder['device_id'] = !empty($credit->device->id) ? $credit->device->id : '';
                        $cacheOrder['device_name'] = !empty($credit->device->id) && !empty($this->cache->cacheDevices[$merchantId . $credit->device->id]['name']) ? $this->cache->cacheDevices[$merchantId . $credit->device->id]['name'] : 'N/A';
                        $cacheOrder['device_model'] = !empty($credit->device->id) && !empty($this->cache->cacheDevices[$merchantId . $credit->device->id]['model']) ? $this->cache->cacheDevices[$merchantId . $credit->device->id]['model'] : 'N/A';
                        $cacheOrder['device_serial'] = !empty($credit->device->id) && !empty($this->cache->cacheDevices[$merchantId . $credit->device->id]['serial']) ? $this->cache->cacheDevices[$merchantId . $credit->device->id]['serial'] : 'N/A';
                        $cacheOrder['customerName'] = !empty($credit->order->customers->elements[0]->firstName) ? $credit->order->customers->elements[0]->firstName . ' ' . $credit->order->customers->elements[0]->lastName : 'N/A';
                        $cacheOrder['customerSince'] = !empty($credit->order->customers->elements[0]->customerSince) ? (int)substr($credit->order->customers->elements[0]->customerSince, 0, -3) : 0;
                        $cacheOrder['orderType'] = "N/A";
                        $cacheOrder['orderTitle'] = "N/A";
                        $orderCreatedTimestamp = substr($credit->clientCreatedTime, 0, strlen($credit->clientCreatedTime) - 3);
                        $cacheOrder['original_date'] = date($this->dateFormat, $orderCreatedTimestamp);
                        $cacheOrder['date_iso_format'] = date(self::DATE_FORMAT_ISO, $orderCreatedTimestamp - 3600 * $this->hourShifts[$merchantId]);
                        $cacheOrder['date'] = date($this->dateFormat, $orderCreatedTimestamp - 3600 * $this->hourShifts[$merchantId]);
                        $cacheOrder['hour'] = date("H", $orderCreatedTimestamp);
                        $cacheOrder['hour_numeric'] = (int)$cacheOrder['hour'];
                        $cacheOrder['hour'] = $this->transform_hour_to_AM_PM($cacheOrder['hour']);
                        $cacheOrder['time'] = date("H:i", $orderCreatedTimestamp);
                        $cacheOrder['half_hour'] = $this->getHourPart(substr($cacheOrder['time'], -2));
                        $cacheOrder['daypart'] = $this->getDayPart($cacheOrder['time'], $cacheOrder['merchantID']);
                        $cacheOrder['day'] = date("l", $orderCreatedTimestamp - 3600 * $this->hourShifts[$merchantId]);
                        $cacheOrder['day_of_week'] = date("w", $orderCreatedTimestamp);
                        if($addDayNumberToDay) {
                            $cacheOrder['day'] = $cacheOrder['day_of_week'] . '-' . $cacheOrder['day'];
                        }
                        $cacheOrder['week'] = date("W", $orderCreatedTimestamp);
                        $cacheOrder['year'] = date("Y", $orderCreatedTimestamp);

                        $cacheOrder['paymentLabel'] = isset($credit->tender->label) ? $credit->tender->label : 'N/A';
                        $cacheOrder['total_order'] = 0;
                        $cacheOrder['dummy'] = 1;
                        $cacheOrder['cost_and_tax'] = 0;
                        $cacheOrder['payment_profit'] = 0;

                        $cacheOrder['order'] = isset($credit->orderRef->id) ? $credit->orderRef->id : 'N/A';
                        $cacheOrder['taxRemoved'] = isset($credit->orderRef->taxRemoved) ? (int)$credit->orderRef->taxRemoved : 0;
                        $cacheOrder['isVat'] = isset($credit->orderRef->isVat) ? (int)$credit->orderRef->isVat : 0;
                        $cacheOrder['payType'] = !empty($credit->orderRef->payType) ? $credit->orderRef->payType : 'N/A';
                        $cacheOrder['manualTransaction'] = isset($credit->orderRef->manualTransaction) ? (int)$credit->orderRef->manualTransaction : 0;

                        $cacheOrder['total'] = 0;
                        $cacheOrder['tipAmount'] = 0;
                        $cacheOrder['serviceCharge'] = 0;
                        $cacheOrder['tax_amount_pay'] = 0;
                        $cacheOrder['total_gross'] = 0;
                        $cacheOrder['tax_amount_pay_gross'] = 0;
                        $cacheOrder['total_refund'] = 0;
                        $cacheOrder['total_refund_tax'] = 0;
                        $cacheOrder['credit_amount'] = $credit->amount / 100;
                        $cacheOrder['credit_taxamount'] = $credit->taxAmount / 100;

                        $cacheOrder['discount_order_percent'] = 0;
                        $cacheOrder['discount_order_amount'] = 0;

                        $cacheOrder['payment_date'] = date("Y-m-d", $orderCreatedTimestamp);
                        $cacheOrder['payment_time'] = date("H:i", $orderCreatedTimestamp);

                        $cacheOrder['card_type'] = !empty($credit->cardTransaction->cardType) ? $credit->cardTransaction->cardType : 'N/A';
                        $cacheOrder['entryType'] = !empty($credit->cardTransaction->entryType) ? $credit->cardTransaction->entryType : 'N/A';
                        $cacheOrder['type'] = !empty($credit->cardTransaction->type) ? $credit->cardTransaction->type : 'N/A';
                        $cacheOrder['state'] = !empty($credit->cardTransaction->state) ? $credit->cardTransaction->state : 'N/A';

                        $cacheOrder['total'] = $cacheOrder['total_gross'] - $cacheOrder['total_refund'] - $cacheOrder['credit_amount'];
                        $cacheOrder['tax_amount_pay'] = $cacheOrder['tax_amount_pay_gross'] - $cacheOrder['total_refund_tax'] - $cacheOrder['credit_taxamount'];
                        $cacheOrder['total_minus_tax'] = $cacheOrder['total'] - $cacheOrder['tax_amount_pay'] - $cacheOrder['serviceCharge'];


                        $orderForCsv = [
                            'market' => $this->apiType,
                            'merchantID' => $cacheOrder['merchantID'],
                            'merchantTier' => !empty($_SESSION[$cacheOrder['merchantID']]['merchantInfo']['tierName']) ? (string)$_SESSION[$cacheOrder['merchantID']]['merchantInfo']['tierName'] : 'STARTER',
                            'trxType' => $cacheOrder['trx_type'],
                            'orderID' => $cacheOrder['order'],
                            'orderOriginalDate' => $cacheOrder['original_date'],
                            'orderDiscountPerc' => 'NO',
                            'orderDiscountAbs' => 'NO',
                            'orderRefunded' => 'NO',
                            'orderServiceCharge' => 'NO',
                            'orderCreditAmount' => 'YES',
                            'paymentOriginalDate' => '',
                            'itemDiscountPerc' => 'NO',
                            'itemDiscountAbs' => 'NO',
                            'itemExchanged' => 'NO',
                            'itemRefunded' => 'NO',
                            'itemIsRevenue' => 'NO',
                        ];
                        $ordersItemsForCsv[] = implode(';', $orderForCsv);

                        $cacheOrders[] = $cacheOrder;

                        $cacheOrder = null;
                        $credit = null;
                    }
                }
            }
        }
    }

    private function getPayments(&$cachePayments, &$payments_customers, $addDayNumberToDay = false) {
        // cached data we will use.
        $this->cache->updateLocalCacheData('employees');
        $this->cache->updateLocalCacheData('customers');

        if(!is_array($payments_customers)) {
            $payments_customers = array();
        }

        $paymentsArray = null;
        $paymentsMerchant = null;
        $payments = null;
        $merchantId = null;
        $this->callApi('urlPayments', $paymentsArray, self::MULTI_CHUNK_FOR_SINGLE_HIGH);
        foreach($paymentsArray as $merchantId => $paymentsMerchant) {

            $this->merchants[$merchantId]->setTimezone();

            foreach($paymentsMerchant as $payments) {
                if (isset($payments->elements) && is_array($payments->elements)) {
                    foreach ($payments->elements as $payment) {
                        if ($payment->result != "SUCCESS") {
                            continue;
                        }

                        $cachePayment = [];
                        $cachePayment["trx_type"] = "PAYMENT";
                        $cachePayment["payment"] = $payment->id;
                        $cachePayment["merchantID"] = $merchantId;
                        $cachePayment['merchantName'] = $this->merchantNames[$merchantId];
                        $cachePayment["employee_id"] = !empty($payment->employee->id) ? $payment->employee->id : 'N/A';
                        $cachePayment['employee'] = !empty($payment->employee->id) && !empty($this->cache->cacheEmployees[$merchantId . $payment->employee->id]['name']) ? $this->cache->cacheEmployees[$merchantId . $payment->employee->id]['name'] : 'N/A';
                        $cachePayment["payment_title"] = !empty($payment->order->title) ? $payment->order->title : 'N/A';
                        $cachePayment["order"] = $payment->order->id;
                        $paymentCreatedTimestamp = substr($payment->clientCreatedTime, 0, strlen($payment->clientCreatedTime) - 3);
                        $cachePayment['original_date'] = date($this->dateFormat, $paymentCreatedTimestamp);
                        $cachePayment['date_iso_format'] = date(self::DATE_FORMAT_ISO, $paymentCreatedTimestamp - 3600 * $this->hourShifts[$merchantId]);
                        $cachePayment['date'] = date($this->dateFormat, $paymentCreatedTimestamp - 3600 * $this->hourShifts[$merchantId]);
                        $cachePayment["hour"] = date("H", $paymentCreatedTimestamp);
                        $cachePayment['hour_numeric'] = (int)$cachePayment['hour'];
                        $cachePayment['hour'] = $this->transform_hour_to_AM_PM($cachePayment['hour']);
                        $cachePayment["time"] = date("H:i", $paymentCreatedTimestamp);
                        $cachePayment['half_hour'] = $this->getHourPart(substr($cachePayment['time'], -2));
                        $cachePayment["daypart"] = $this->getDayPart($cachePayment['time'], $cachePayment['merchantID']);
                        $cachePayment["day"] = date("l", $paymentCreatedTimestamp - 3600 * $this->hourShifts[$merchantId]);
                        $cachePayment["day_of_week"] = date("w", $paymentCreatedTimestamp);
                        if($addDayNumberToDay) {
                            $cachePayment['day'] = $cachePayment['day_of_week'] . '-' . $cachePayment['day'];
                        }
                        $cachePayment['week'] = date("W", $paymentCreatedTimestamp);
                        $cachePayment['year'] = date("Y", $paymentCreatedTimestamp);

                        $cachePayment['customerId'] = !empty($payment->order->customers->elements[0]->id) ? $payment->order->customers->elements[0]->id : 'N/A';
                        $cachePayment['customerName'] = !empty($payment->order->customers->elements[0]->id) && !empty($this->cache->cacheCustomers[$merchantId . $payment->order->customers->elements[0]->id]['name']) ? $this->cache->cacheCustomers[$merchantId . $payment->order->customers->elements[0]->id]['name'] : 'N/A';
                        $cachePayment['customerEmail'] = !empty($payment->order->customers->elements[0]->id) && !empty($this->cache->cacheCustomers[$merchantId . $payment->order->customers->elements[0]->id]['emails']) ? $this->cache->cacheCustomers[$merchantId . $payment->order->customers->elements[0]->id]['emails'] : 'N/A';
                        $cachePayment['customerPhoneNumber'] = !empty($payment->order->customers->elements[0]->id) && !empty($this->cache->cacheCustomers[$merchantId . $payment->order->customers->elements[0]->id]['phoneNumbers']) ? $this->cache->cacheCustomers[$merchantId . $payment->order->customers->elements[0]->id]['phoneNumbers'] : 'N/A';
                        $cachePayment['customerSince'] = !empty($payment->order->customers->elements[0]->id) && !empty($this->cache->cacheCustomers[$merchantId . $payment->order->customers->elements[0]->id]['customerSinceTimestamp']) ? $this->cache->cacheCustomers[$merchantId . $payment->order->customers->elements[0]->id]['customerSinceTimestamp'] : 0;
                        //if we don't have customerName meaning its n/a, we will try and add cardholdername if it's available
                        if($cachePayment['customerName'] == 'N/A'){
                            if(!empty($payment->cardTransaction->cardholderName)) {
                                $cachePayment['customerName'] = $payment->cardTransaction->cardholderName;
                            }
                        }
                        if(!empty($cachePayment['customerId'])) {
                            $payments_customers[$payment->id] = array(
                                'customerId' => $cachePayment['customerId'],
                                'customerName' => $cachePayment['customerName'],
                                'customerSince' => $cachePayment['customerSince'],
                            );
                        }

                        $cachePayment["paymentLabel"] = !empty($payment->tender->label) ? $payment->tender->label : 'N/A';
                        $cachePayment["dummy"] = 1;
                        $cachePayment["amount"] = $payment->amount / 100;
                        $cachePayment["taxAmount"] = $payment->taxAmount / 100;
                        $cachePayment["credit_amount"] = 0;
                        $cachePayment["credit_taxamount"] = 0;
                        $cachePayment["result"] = !empty($payment->result) ? $payment->result : 'N/A';

                        // $payment->refunds->elements
                        $cachePayment["refund_amount"] = 0;
                        $cachePayment["refund_taxamount"] = 0;

                        $cachePayment["cardType"] = !empty($payment->cardTransaction->cardType) ? $payment->cardTransaction->cardType : 'N/A';
                        $cachePayment["entryType"] = !empty($payment->cardTransaction->entryType) ? $payment->cardTransaction->entryType : 'N/A';
                        $cachePayment["type"] = !empty($payment->cardTransaction->type) ? $payment->cardTransaction->type : 'N/A';
                        $cachePayment["state"] = !empty($payment->cardTransaction->state) ? $payment->cardTransaction->state : 'N/A';
                        $cachePayment["last4"] = !empty($payment->cardTransaction->last4) ? $payment->cardTransaction->last4 : 'N/A';
                        $cachePayment["authCode"] = !empty($payment->cardTransaction->authCode) ? $payment->cardTransaction->authCode : 'N/A';

                        $cachePayment["tipAmount"] = isset($payment->tipAmount) ? $payment->tipAmount / 100 : 0;

                        $cachePayments[] = $cachePayment;

                        $cachePayment = null;
                        $payment = null;
                    }
                }
            }
        }
    }

    private function getPaymentsRefunds(&$cachePayments, &$payments_customers, $addDayNumberToDay = false) {
        // cached data we will use.
        $this->cache->updateLocalCacheData('employees');

        $refundsArray = null;
        $refundsMerchant = null;
        $refunds = null;
        $merchantId = null;
        $this->callApi('urlRefunds', $refundsArray, self::MULTI_CHUNK_FOR_SINGLE_LOW);
        foreach($refundsArray as $merchantId => $refundsMerchant) {

            $this->merchants[$merchantId]->setTimezone();

            foreach($refundsMerchant as $refunds) {
                if (isset($refunds->elements) && is_array($refunds->elements)) {
                    foreach ($refunds->elements as $refund) {
                        // if ($refund->result != "SUCCESS") {
                        //     continue;
                        // }

                        $cachePayment = [];
                        $cachePayment["trx_type"] = "REFUND";
                        $cachePayment["payment"] = $refund->payment->id;
                        $cachePayment["merchantID"] = $merchantId;
                        $cachePayment['merchantName'] = $this->merchantNames[$merchantId];
                        $cachePayment["employee_id"] = !empty($refund->employee->id) ? $refund->employee->id : 'N/A';
                        $cachePayment['employee'] = !empty($refund->employee->id) && !empty($this->cache->cacheEmployees[$merchantId . $refund->employee->id]['name']) ? $this->cache->cacheEmployees[$merchantId . $refund->employee->id]['name'] : 'N/A';
                        $cachePayment["payment_title"] = !empty($refund->payment->order->title) ? $refund->payment->order->title : 'N/A';
                        $cachePayment["order"] = $refund->payment->order->id;
                        $paymentCreatedTimestamp = substr($refund->clientCreatedTime, 0, strlen($refund->clientCreatedTime) - 3);
                        $cachePayment['original_date'] = date($this->dateFormat, $paymentCreatedTimestamp);
                        $cachePayment['date_iso_format'] = date(self::DATE_FORMAT_ISO, $paymentCreatedTimestamp - 3600 * $this->hourShifts[$merchantId]);
                        $cachePayment['date'] = date($this->dateFormat, $paymentCreatedTimestamp - 3600 * $this->hourShifts[$merchantId]);
                        $cachePayment["hour"] = date("H", $paymentCreatedTimestamp);
                        $cachePayment['hour_numeric'] = (int)$cachePayment['hour'];
                        $cachePayment['hour'] = $this->transform_hour_to_AM_PM($cachePayment['hour']);
                        $cachePayment["time"] = date("H:i", $paymentCreatedTimestamp);
                        $cachePayment['half_hour'] = $this->getHourPart(substr($cachePayment['time'], -2));
                        $cachePayment["daypart"] = $this->getDayPart($cachePayment['time'], $cachePayment['merchantID']);
                        $cachePayment["day"] = date("l", $paymentCreatedTimestamp - 3600 * $this->hourShifts[$merchantId]);
                        $cachePayment["day_of_week"] = date("w", $paymentCreatedTimestamp);
                        if($addDayNumberToDay) {
                            $cachePayment['day'] = $cachePayment['day_of_week'] . '-' . $cachePayment['day'];
                        }
                        $cachePayment['week'] = date("W", $paymentCreatedTimestamp);
                        $cachePayment['year'] = date("Y", $paymentCreatedTimestamp);
                        
						$cachePayment['customerId'] = isset($payments_customers[$refund->payment->id]['customerId']) ? $payments_customers[$refund->payment->id]['customerId'] : 'N/A';
                        $cachePayment['customerName'] = isset($payments_customers[$refund->payment->id]['customerName']) ? $payments_customers[$refund->payment->id]['customerName'] : 'N/A';
                        $cachePayment['customerSince'] = isset($payments_customers[$refund->payment->id]['customerSince']) ? $payments_customers[$refund->payment->id]['customerSince'] : 0;

                        $cachePayment["paymentLabel"] = !empty($refund->payment->tender->label) ? $refund->payment->tender->label : 'N/A';
                        $cachePayment["dummy"] = 1;
                        $cachePayment["amount"] = 0;
                        $cachePayment["taxAmount"] = 0;
                        $cachePayment["refund_amount"] = $refund->amount / 100;
                        $cachePayment["refund_taxamount"] = $refund->taxAmount / 100;
                        $cachePayment["credit_amount"] = 0;
                        $cachePayment["credit_taxamount"] = 0;
                        $cachePayment["result"] = !empty($refund->result) ? $refund->result : 'N/A';

                        $cachePayment["cardType"] = !empty($refund->payment->cardTransaction->cardType) ? $refund->payment->cardTransaction->cardType : 'N/A';
                        $cachePayment["entryType"] = !empty($refund->payment->cardTransaction->entryType) ? $refund->payment->cardTransaction->entryType : 'N/A';
                        $cachePayment["type"] = !empty($refund->payment->cardTransaction->type) ? $refund->payment->cardTransaction->type : 'N/A';
                        $cachePayment["state"] = !empty($refund->payment->cardTransaction->state) ? $refund->payment->cardTransaction->state : 'N/A';
                        $cachePayment["last4"] = !empty($refund->payment->cardTransaction->last4) ? $refund->payment->cardTransaction->last4 : 'N/A';
                        $cachePayment["authCode"] = !empty($refund->payment->cardTransaction->authCode) ? $refund->payment->cardTransaction->authCode : 'N/A';

                        $cachePayment["tipAmount"] = isset($refund->payment->tipAmount) ? $refund->payment->tipAmount / 100 : 0;

                        $cachePayments[] = $cachePayment;

                        $cachePayment = null;
                        $refund = null;
                    }
                }
            }
        }
    }

    private function getPaymentsCredits(&$cachePayments, $addDayNumberToDay = false) {
        // cached data we will use.
        $this->cache->updateLocalCacheData('employees');

        $creditsArray = null;
        $creditsMerchant = null;
        $credits = null;
        $merchantId = null;
        $this->callApi('urlCredits', $creditsArray, self::MULTI_CHUNK_FOR_SINGLE_LOW);
        foreach($creditsArray as $merchantId => $creditsMerchant) {

            $this->merchants[$merchantId]->setTimezone();

            foreach($creditsMerchant as $credits) {
                if (isset($credits->elements) && is_array($credits->elements)) {
                    foreach ($credits->elements as $credit) {
                        $cachePayment = [];
                        $cachePayment["trx_type"] = "CREDIT";
                        $cachePayment["payment"] = 'N/A';
                        $cachePayment["merchantID"] = $merchantId;
                        $cachePayment['merchantName'] = $this->merchantNames[$merchantId];
                        $cachePayment["employee_id"] = !empty($credit->employee->id) ? $credit->employee->id : 'N/A';
                        $cachePayment['employee'] = !empty($credit->employee->id) && !empty($this->cache->cacheEmployees[$merchantId . $credit->employee->id]['name']) ? $this->cache->cacheEmployees[$merchantId . $credit->employee->id]['name'] : 'N/A';
                        $cachePayment["payment_title"] = !empty($credit->order->title) ? $credit->order->title : 'N/A';
                        $cachePayment["order_id"] = $credit->orderRef->id;
                        $paymentCreatedTimestamp = substr($credit->clientCreatedTime, 0, strlen($credit->clientCreatedTime) - 3);
                        $cachePayment['original_date'] = date($this->dateFormat, $paymentCreatedTimestamp);
                        $cachePayment['date_iso_format'] = date(self::DATE_FORMAT_ISO, $paymentCreatedTimestamp - 3600 * $this->hourShifts[$merchantId]);
                        $cachePayment['date'] = date($this->dateFormat, $paymentCreatedTimestamp - 3600 * $this->hourShifts[$merchantId]);
                        $cachePayment["hour"] = date("H", $paymentCreatedTimestamp);
                        $cachePayment['hour_numeric'] = (int)$cachePayment['hour'];
                        $cachePayment['hour'] = $this->transform_hour_to_AM_PM($cachePayment['hour']);
                        $cachePayment["time"] = date("H:i", $paymentCreatedTimestamp);
                        $cachePayment['half_hour'] = $this->getHourPart(substr($cachePayment['time'], -2));
                        $cachePayment["daypart"] = $this->getDayPart($cachePayment['time'], $cachePayment['merchantID']);
                        $cachePayment["day"] = date("l", $paymentCreatedTimestamp - 3600 * $this->hourShifts[$merchantId]);
                        $cachePayment["day_of_week"] = date("w", $paymentCreatedTimestamp);
                        if($addDayNumberToDay) {
                            $cachePayment['day'] = $cachePayment['day_of_week'] . '-' . $cachePayment['day'];
                        }
                        $cachePayment['week'] = date("W", $paymentCreatedTimestamp);
                        $cachePayment['year'] = date("Y", $paymentCreatedTimestamp);

                        if(is_object($credit->customers)) {
                            $customer = $credit->customers;
                        } elseif(is_array($credit->customers) && count($credit->customers)) {
                            $customer = $credit->customers[0];
                        } elseif(!empty($credit->order->customers->elements)) {
                            $customer = $credit->order->customers->elements[0];
                        } else {
                            $customer = null;
                        }
                        $cachePayment['customerId'] = isset($customer->id) ? $customer->id : 'N/A';
                        $cachePayment['customerName'] = isset($customer->firstName) ? $customer->firstName . ' ' . $customer->lastName : 'N/A';
                        $cachePayment['customerSince'] = isset($customer->customerSince) ? (int)substr($customer->customerSince, 0, -3) : 0;

                        $cachePayment["paymentLabel"] = !empty($credit->tender->label) ? $credit->tender->label : 'N/A';
                        $cachePayment["dummy"] = 1;
                        $cachePayment["amount"] = 0;
                        $cachePayment["taxAmount"] = 0;
                        $cachePayment["refund_amount"] = 0;
                        $cachePayment["refund_taxamount"] = 0;
                        $cachePayment["credit_amount"] = $credit->amount / 100;
                        $cachePayment["credit_taxamount"] = $credit->taxAmount / 100;
                        $cachePayment["result"] = !empty($credit->result) ? $credit->result : 'N/A';

                        $cachePayment["cardType"] = !empty($credit->cardTransaction->cardType) ? $credit->cardTransaction->cardType : 'N/A';
                        $cachePayment["entryType"] = !empty($credit->cardTransaction->entryType) ? $credit->cardTransaction->entryType : 'N/A';
                        $cachePayment["type"] = !empty($credit->cardTransaction->type) ? $credit->cardTransaction->type : 'N/A';
                        $cachePayment["state"] = !empty($credit->cardTransaction->state) ? $credit->cardTransaction->state : 'N/A';
                        $cachePayment["last4"] = !empty($credit->cardTransaction->last4) ? $credit->cardTransaction->last4 : 'N/A';
                        $cachePayment["authCode"] = !empty($credit->cardTransaction->authCode) ? $credit->cardTransaction->authCode : 'N/A';

                        $cachePayment["tipAmount"] = isset($credit->payment->tipAmount) ? $credit->payment->tipAmount / 100 : 0;

                        $cachePayments[] = $cachePayment;

                        $cachePayment = null;
                        $credit = null;
                    }
                }
            }
        }
    }

    /**
     * Deprecated notice - Customers are now cached.
     * @deprecated
     */
    private function getPaymentsForOrderCustomers(&$cacheOrders, &$orders_index, &$period_for_order_payments) {
        $backupMerchants = $this->merchants;
        $backupMerchantIDs = $this->merchantIds;

        // setup new merchants because we need new time periods.
        $this->merchants = [];
        foreach($this->merchantIds as $merchantId) {
            if(!isset($period_for_order_payments[$merchantId]) || !$this->hasCustomers[$merchantId]) {
                // no payments for this location or no customers.
                continue;
            }

            $merchantData = new stdClass;
            $merchantData->merchant_id = $merchantId;
            $merchantData->merchant_name = $this->merchantNames[$merchantId];
            $merchantData->token = $this->tokens[$merchantId];
            $merchantData->timezone = $this->timezones[$merchantId];
            $merchantData->hour_shift = $this->hourShifts[$merchantId];
            $merchantData->report = $this->callingData->report;
            $merchantData->return_categories = $this->callingData->return_categories;
            $merchantData->get = 'payments_defined_timestamps';
            $merchantData->timestamp_start = $period_for_order_payments[$merchantId]['startTimestamp'];
            $merchantData->timestamp_end = $period_for_order_payments[$merchantId]['endTimestamp'];

            $clover = new Clover;
            $clover->setCustomers($this->hasCustomers[$merchantId]); // call before setApiData
            $clover->setApiData($this->apiType, $merchantData, $merchantId);

            $this->merchants[$merchantId] = $clover;
        }
        if(!count($this->merchants)) {
            // no payments for all locations, no customers for all locations.
            return;
        }
        $this->merchantIds = array_combine(array_keys($this->merchants), array_keys($this->merchants));

        $cachePayments = [];
        $this->getPayments($cachePayments, $payments_customers);
        $this->getPaymentsRefunds($cachePayments, $payments_customers);
        $this->getPaymentsCredits($cachePayments);
        // add customers to orders.
        foreach($cachePayments as &$cachePayment) {
            if(!empty($cachePayment['customerName']) && $cachePayment['customerName'] != 'N/A') {
                if(isset($orders_index[$cachePayment['order']])) {
                    $index = $orders_index[$cachePayment['order']];
                    $cacheOrders[$index]['customerName'] = $cachePayment['customerName'];
                    $cacheOrders[$index]['customerSince'] = $cachePayment['customerSince'];
                }
            }
        }

        // return 'real' merchants.
        $this->merchants = $backupMerchants;
        $this->merchantIds = $backupMerchantIDs;
    }

    private function getItems(&$items) {
        // cached data we will use.
        $this->cache->updateLocalCacheData('items');
        foreach($this->cache->cacheItems as $merchantIdItemId => $item) {
            $item['itemID'] = $item['id'];
            $item['price'] = $item['price'] / 100;
            $item['cost'] = $item['cost'] / 100;
            $items[] = $item;
        }
    }

    private function getReportsItems(&$reportsItems) {
        if (!defined('DENOMINATOR')) {
            define('DENOMINATOR', 100);
        }

        $apiItemsArray = null;
        $apiItemsMerchant = null;
        $apiItems = null;
        $merchantId = null;
        $tmpItemIdToArrayKey = [];
        $this->callApi('urlReportItems', $apiItemsArray);
        foreach($apiItemsArray as $merchantId => $apiItemsMerchant) {
            foreach($apiItemsMerchant as $apiItems) {
                if (!empty($apiItems->categories->elements)) {
                    foreach ($apiItems->categories->elements as $category) {
                        if (!empty($category->items->elements)) {
                            foreach ($category->items->elements as $apiItem) {
                                if(isset($tmpItemIdToArrayKey[$apiItem->id])) {
                                    // already have that item - over 90 days API slices.
                                    $arrayKey = $tmpItemIdToArrayKey[$apiItem->id];
                                    $item = $reportsItems[$arrayKey];
                                } else {
                                    $arrayKey = count($reportsItems);
                                    $tmpItemIdToArrayKey[$apiItem->id] = $arrayKey;
                                    $item = [];
                                    $item['merchantId'] = $merchantId;
                                    $item['merchantName'] = $this->merchants[$merchantId]->merchantName;
                                    $item['id'] = $apiItem->id;
                                    $item['name'] = $apiItem->name;
                                    $item['category'] = $category->name;
                                    $item['numberSold'] = 0;
                                    $item['amountSold'] = 0;
                                    $item['numberRefunds'] = 0;
                                    $item['amountRefunds'] = 0;
                                    $item['numberExchanges'] = 0;
                                    $item['amountExchanged'] = 0;
                                    $item['discounts_numberSold'] = 0;
                                    $item['discounts_amountSold'] = 0;
                                    $item['modifiers_numberSold'] = 0;
                                    $item['modifiers_amountSold'] = 0;
                                }

                                $item['numberSold'] += $apiItem->numberSold;
                                $item['amountSold'] += $apiItem->amountSold / DENOMINATOR;
                                $item['numberRefunds'] += $apiItem->numberRefunds;
                                $item['amountRefunds'] += $apiItem->amountRefunds / DENOMINATOR;
                                $item['numberExchanges'] += $apiItem->numberExchanges;
                                $item['amountExchanged'] += $apiItem->amountExchanged / DENOMINATOR;

                                if (!empty($apiItem->discounts->elements)) {
                                    foreach ($apiItem->discounts->elements as $discount) {
                                        $item['discounts_numberSold'] += $discount->numberSold;
                                        $item['discounts_amountSold'] += $discount->amountSold / DENOMINATOR;
                                    }
                                }

                                if (!empty($apiItem->modifiers->elements)) {
                                    foreach ($apiItem->modifiers->elements as $modifier) {
                                        $item['modifiers_numberSold'] += $modifier->numberSold;
                                        $item['modifiers_amountSold'] += $modifier->amountSold / DENOMINATOR;
                                    }
                                }

                                $reportsItems[$arrayKey] = $item;
                            }
                        }
                    }
                }
            }
        }
    }
    
    private function getReportsPayments(&$reportsPayments) {
        if (!defined('DENOMINATOR')) {
            define('DENOMINATOR', 100);
        }

        $apiPaymentsArray = null;
        $apiPaymentsMerchant = null;
        $apiPayments = null;
        $merchantId = null;
        $this->callApi('urlReportPayments', $apiPaymentsArray);
        foreach($apiPaymentsArray as $merchantId => $apiPaymentsMerchant) {
            $paymentSummary = array();
            $refundSummary = array();
            $creditSummary = array();
            $employeeSummary = array();

            foreach($apiPaymentsMerchant as $apiPayments) {
                if (isset($apiPayments->paymentSummary)) {
                    $summary = $apiPayments->paymentSummary;

                    $paymentSummary['num'] += $summary->num;
                    $paymentSummary['amount'] += $summary->amount / DENOMINATOR;
                    $paymentSummary['tipAmount'] += $summary->tipAmount / DENOMINATOR;
                    $paymentSummary['taxAmount'] += $summary->taxAmount / DENOMINATOR;
                    $paymentSummary['serviceChargeAmount'] += $summary->serviceChargeAmount / DENOMINATOR;

                    if (!empty($summary->byTender->elements)) {
                        foreach ($summary->byTender->elements as $element) {
                            $paymentSummary['byTender'][] = array(
                                'type' => $element->type,
                                'amount' => $element->amount / DENOMINATOR,
                            );
                        }
                    }

                    if (!empty($summary->byCardType->elements)) {
                        foreach ($summary->byCardType->elements as $element) {
                            $paymentSummary['byCardType'][] = array(
                                'type' => $element->type,
                                'amount' => $element->amount / DENOMINATOR,
                            );
                        }
                    }
                }

                if (isset($apiPayments->refundSummary)) {
                    $summary = $apiPayments->refundSummary;

                    $refundSummary['num'] += $summary->num;
                    $refundSummary['amount'] += $summary->amount / DENOMINATOR;
                    $refundSummary['taxAmount'] += $summary->taxAmount / DENOMINATOR;
                    $refundSummary['serviceChargeAmount'] += $summary->serviceChargeAmount / DENOMINATOR;

                    if (!empty($summary->byTender->elements)) {
                        foreach ($summary->byTender->elements as $element) {
                            $refundSummary['byTender'][] = array(
                                'type' => $element->type,
                                'amount' => $element->amount / DENOMINATOR,
                            );
                        }
                    }
                }

                if (isset($apiPayments->creditSummary)) {
                    $summary = $apiPayments->creditSummary;

                    $creditSummary['num'] += $summary->num;
                    $creditSummary['amount'] += $summary->amount / DENOMINATOR;
                    $creditSummary['taxAmount'] += $summary->taxAmount / DENOMINATOR;

                    if (!empty($summary->byTender->elements)) {
                        foreach ($summary->byTender->elements as $element) {
                            $creditSummary['byTender'][] = array(
                                'type' => $element->type,
                                'amount' => $element->amount / DENOMINATOR,
                            );
                        }
                    }
                }

                if (isset($apiPayments->employeeSummary)) {
                    $summary = $apiPayments->employeeSummary;

                    if (!empty($summary->elements)) {
                        foreach ($summary->elements as $element) {
                            $employeeSummary[] = array(
                                'id' => $element->employee->id,
                                'name' => $element->name,
                                'numPayments' => $element->numPayments,
                                'paymentsAmount' => $element->paymentsAmount / DENOMINATOR,
                                'numRefunds' => $element->numRefunds,
                                'refundsAmount' => $element->refundsAmount / DENOMINATOR,
                                'numCredits' => $element->numCredits,
                                'creditsAmount' => $element->creditsAmount / DENOMINATOR,
                                'tipsDue' => $element->tipsDue / DENOMINATOR,
                                'serviceCharges' => $element->serviceCharges / DENOMINATOR,
                            );
                        }
                    }
                }
            }

            if(empty($paymentSummary) && empty($refundSummary) && empty($creditSummary) && empty($employeeSummary)) {
                // location is inactive or uninstalled so we will ignore it.
                continue;
            }

            $reportsPayments[$merchantId] = array(
                'merchantId' => $merchantId,
                'merchantName' => $this->merchants[$merchantId]->merchantName,
                'summary' => compact('paymentSummary', 'refundSummary', 'creditSummary', 'employeeSummary'),
            );
        }


        # GETTING LINE ITEM DISCOUNTS IS REMOVED! IT'S SLOW AND IT'S NOT USED ANYWHERE!
        /* // Add line item discounts.
        $apiDiscountsArray = null;
        $apiDiscountsMerchant = null;
        $apiDiscounts = null;
        $merchantId = null;
        $this->callApi('urlReportItemDiscounts', $apiDiscountsArray);
        foreach($apiDiscountsArray as $merchantId => $apiDiscountsMerchant) {
            $line_item_discounts = array();

            foreach($apiDiscountsMerchant as $apiDiscounts) {
                if (!empty($apiDiscounts)) {
                    foreach ($apiDiscounts as $discount) {
                        if (!isset($line_item_discounts[$discount->name])) {
                            $discount->numDiscounts = isset($discount->numDiscounts) ? $discount->numDiscounts : 0;
                            $discount->discountAmount = isset($discount->discountAmount) ? $discount->discountAmount / DENOMINATOR : 0;
                            $line_item_discounts[$discount->name] = $discount;
                        } else {
                            $line_item_discounts[$discount->name]->numDiscounts += $discount->numDiscounts;
                            $line_item_discounts[$discount->name]->discountAmount += $discount->discountAmount / DENOMINATOR;
                        }
                    }
                }
            }

            $reportsPayments[$merchantId]['line_item_discounts'] = array_values($line_item_discounts);
        }*/

        $reportsPayments = array_values($reportsPayments);
    }

    /**
     * Prepares return array based on wanted report.
     *
     * @param array $returnArrays
     * @param array $newOrders
     * @param array $payments
     * @param array $reportsPayments
     * @param array $reportsItems
     * @param array $items
     * @param array $groupedResult
     */
    public function prepareReturnData(&$returnArrays, &$newOrders, &$payments, &$reportsPayments, &$reportsItems, &$items, &$groupedResult) {
        switch ($this->get) {
            case 'orders_default':
            case 'orders_defined_dates':
            case 'orders_yesterday':
            case 'orders_daily':
            case 'orders_monthly_MTD':
            case 'orders_monthly_LM':
                $returnArrays = array();
                $returnArrays['dataRows'] = $newOrders['dataRows'];
                $returnArrays['dates'] = array('start' => $this->reportStartDate, 'end' => $this->reportEndDate);
                break;

            case 'payments_default':
            case 'payments_defined_dates':
            case 'payments_yesterday':
            case 'payments_daily':
            case 'payments_monthly_MTD':
            case 'payments_monthly_LM':
                $returnArrays = array();
                $returnArrays['dataRows'] = $payments['dataRows'];
                $returnArrays['dates'] = array('start' => $this->reportStartDate, 'end' => $this->reportEndDate);
                break;

            case 'reports_payments':
            case 'reports_payments_defined_dates':
            case 'reports_payments_default':
            case 'reports_payments_yesterday':
            case 'reports_payments_monthly_MTD':
            case 'reports_payments_monthly_LM':
                $returnArrays = array();
                $returnArrays[] = $reportsPayments;
                $returnArrays[] = array($this->reportStartDate, $this->reportEndDate);
                break;

            case 'reports_items':
            case 'reports_items_defined_dates':
            case 'reports_items_default':
            case 'reports_items_yesterday':
            case 'reports_items_monthly_MTD':
            case 'reports_items_monthly_LM':
                $returnArrays = array();
                $returnArrays[] = $reportsItems;
                $returnArrays[] = array($this->reportStartDate, $this->reportEndDate);
                break;

            case 'items':
                $returnArrays = array();
                $returnArrays[] = $items;
                break;

            case 'lov_orders':
            case 'lov_order_items':
            case 'lov_modifications':
            case 'lov_modification_groups':
            case 'lov_payments':
            case 'group_orders':
            case 'group_order_items':
            case 'group_modifications':
            case 'group_modification_groups':
            case 'group_payments':
            case 'raw_orders':
            case 'raw_order_items':
            case 'raw_payments':
            case 'raw_modifications':
            case 'raw_modification_groups':
                switch($this->output) {
                    case 'csv':
                        if(!empty($this->callingData->report)) {
                            $reportName = $this->callingData->report;
                        } else {
                            if ($this->get == 'raw_orders') {
                                $reportName = 'orders';
                            } elseif ($this->get == 'raw_order_items') {
                                $reportName = 'orders_with_items_details';
                            } else {
                                $reportName = 'report_data';
                            }
                        }

                        if(!empty($this->callingData->date_start) && !empty($this->callingData->date_end)) {
                            $reportDates = "_for_period_{$this->callingData->date_start}_until_{$this->callingData->date_end}";
                        } else {
                            $reportDates = '';
                        }

                        $filename = "{$reportName}{$reportDates}.csv";

                        ob_start();
                        $csv = fopen("php://output", 'w');
                        if(count($groupedResult)) {
                            fputcsv($csv, array_keys(reset($groupedResult)));
                            foreach ($groupedResult as $row) {
                                fputcsv($csv, $row);
                            }
                        }
                        fclose($csv);
                        $csv = ob_get_clean();

                        header("Content-Type: text/csv");
                        header("Content-Disposition: attachment; filename=\"$filename\"");
                        header("Expires: 0");
                        header("Cache-Control: must-revalidate, post-check=0, pre-check=0");
                        echo $csv;
                        exit;
                        break;
                    default:
                        $returnArrays = array();
                        $returnArrays['data'] = $groupedResult;
                        break;
                }
                break;
        }
    }

    /**
     * @param string $getUrls
     * @param array|object $returnObject
     * @param int $chunkSizeForSingle
     * @param array|null $merchantExtraUrlData
     */
    private function callApi($getUrls, &$returnObject, $chunkSizeForSingle = self::MULTI_CHUNK_FOR_SINGLE_HIGH, $merchantExtraUrlData = null) {
        if(!is_array($merchantExtraUrlData)) {
            // no extra data, no filtering.
            $merchants = $this->merchants;
            $merchantsLeft = $this->merchantIds;
            $merchantExtraUrlData = array();
            foreach($this->merchantIds as $merchantId) {
                $merchantExtraUrlData[$merchantId] = '';
            }
        } else {
            // we only use those merchants that have defined extra url data.
            $merchants = array();
            $merchantsLeft = array();
            foreach($merchantExtraUrlData as $merchantId => $value) {
                $merchants[$merchantId] = $this->merchants[$merchantId];
                $merchantsLeft[$merchantId] = $merchantId;
            }
        }
        
        $doCyclesAndOffsets = true;
        $urls = array();
        $urlsMerchantsIndexes = array();
        $merchantOffsets = array();
        $i = 0;
        foreach($merchants as $merchant) {
            if(is_array($merchant->urls[$getUrls])) {
                // special case for /reports endpoint
                $doCyclesAndOffsets = false;
                foreach($merchant->urls[$getUrls] as $url) {
                    $urls[$i] = $url . $merchantExtraUrlData[$merchant->merchantId];
                    $urlsMerchantsIndexes[$i] = $merchant->merchantId;
                    $i++;
                }
            } else {
                // other endpoints
                $merchantOffsets[$merchant->merchantId] = 0;
                foreach(range(1, $chunkSizeForSingle) as $j) {
                    $urls[$i] = $merchant->urls[$getUrls] . $merchantExtraUrlData[$merchant->merchantId] . "&offset=" . $merchantOffsets[$merchant->merchantId];
                    $merchantOffsets[$merchant->merchantId] += self::LIMIT;
                    $urlsMerchantsIndexes[$i] = $merchant->merchantId;
                    $i++;
                }
            }
        }
        
        $cycle = 0;
        $failedAttemptsCount = 0;
        while(count($urls)) {
            $urlsChunks = array_chunk($urls, self::MULTI_CHUNK_FOR_MULTI, true);
            $urlsBackup = $urls;
            $urls = array();
            foreach ($urlsChunks as $urlsChunk) {
                $failedAttempt = false;
                $this->callApiRaw($urlsChunk, $mh, $chs);
                // delete used urls?
                foreach ($chs as $i => $value) {
                    $code = curl_getinfo($chs[$i], CURLINFO_HTTP_CODE);
                    if ($code != 429) {
                        $returnJSON = curl_multi_getcontent($chs[$i]);
                        $returnJSON = str_replace("&amp;", "&", str_replace("&lt;", "<", str_replace("&gt;", ">", str_replace("&quot;", "'", str_replace("&apos;", "'", $returnJSON)))));
                        if (!isset($returnObject)) {
                            $returnObject = array();
                        }

                        $jsonObject = json_decode($returnJSON);
                        $returnObject[$urlsMerchantsIndexes[$i]][] = $jsonObject;

                        if((isset($jsonObject->elements) && count($jsonObject->elements) < self::LIMIT) || !isset($jsonObject->elements)) {
                            // it means we got all the data for a merchant. there's no more data for them. or, there are no elements in object, so nothing more to get.
                            unset($merchantsLeft[$urlsMerchantsIndexes[$i]]);
                        }
                    } else {
                        $urls[$i] = curl_getinfo($chs[$i], CURLINFO_EFFECTIVE_URL);
                        $this->auditError('error', $urls[$i]);
                        $failedAttempt = true;
                    }
                }
                
                // free resources.
                foreach($chs as $i => $value) {
                    curl_multi_remove_handle($mh, $chs[$i]);
                    curl_close($chs[$i]);
                    $chs[$i] = null;
                }
                curl_multi_close($mh);
                $mh = null;
                $chs = null;
                
                if($failedAttempt) {
                    if(++$failedAttemptsCount >= self::MULTI_TRY_COUNT && count($urls)) {
                        // if there are still some URLs left and we've reached the number of failed attempts
                        self::returnError('Server is probably under heavy traffic. Please try again.');
                    }
                }
            }

            // if there are still some merchants that have more data to fetch.
            if($doCyclesAndOffsets && count($merchantsLeft)) {
                // reset indexes of 'failed' urls. new urls will be appended.
                $urls = array_values($urls);
                $urlsMerchantsIndexes = array_values($urlsMerchantsIndexes);
                $i = count($urls);
                foreach($merchantsLeft as $merchantId) {
                    $merchant = $this->merchants[$merchantId];
                    foreach(range(1, $chunkSizeForSingle) as $j) {
                        $urls[$i] = $merchant->urls[$getUrls] . $merchantExtraUrlData[$merchant->merchantId] . "&offset=" . $merchantOffsets[$merchant->merchantId];
                        $merchantOffsets[$merchant->merchantId] += self::LIMIT;
                        $urlsMerchantsIndexes[$i] = $merchant->merchantId;
                        $i++;
                    }
                }
            }

            // if there are some URLs left (failed or new), we will sleep a bit and try again.
            if(count($urls)) {
                usleep(1000000);
            }
        }
    }

    /**
     * @param array $urls
     * @param resource|null $mh
     * @param resource[]|null $chs
     */
    private function callApiRaw($urls, &$mh, &$chs) {
        $mh = curl_multi_init();
	    $chs = array();
        foreach($urls as $i => $url) {
            $chs[$i] = curl_init($url);
            curl_setopt($chs[$i], CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($chs[$i], CURLOPT_RETURNTRANSFER, true);
            curl_multi_add_handle($mh, $chs[$i]);
        }

        do {
            curl_multi_exec($mh, $active);
            curl_multi_select($mh);
        } while($active > 0);
    }

    /**
     * Nulls all cached data - arrays and objects - also for each merchant.
     */
    public function clearCachedData() {
        foreach($this->hourShifts as $key => $value) {
            $this->hourShifts[$key] = null;
        }
        $this->hourShifts = null;

        foreach($this->timezones as $key => $value) {
            $this->timezones[$key] = null;
        }
        $this->timezones = null;

        foreach($this->tokens as $key => $value) {
            $this->tokens[$key] = null;
        }
        $this->tokens = null;

        foreach($this->merchantNames as $key => $value) {
            $this->merchantNames[$key] = null;
        }
        $this->merchantNames = null;

        foreach($this->merchantIds as $key => $value) {
            $this->merchantIds[$key] = null;
        }
        $this->merchantIds = null;

        foreach($this->merchants as $key => $merchant) {
            $merchant->clearCachedData();
            $this->merchants[$key] = null;
        }
        $this->merchants = null;
    }

    /**
     * @param bool $test
     */
    public static function setTesting($test = false) {
        self::$test = $test;
    }
    
    /**
     * Inserts auditing record into 'behaviour' table.
     *
     * @param int $nmbOfOrders
     */
    private function auditBehaviour($nmbOfOrders) {
        // We audit only in case we're not testing and the current merchant is the calling merchant (for the purpose of multi-location).
        if (!self::$test) {
            $db = new PDO('mysql:host=' . self::DB_HOST . ';dbname=' . self::DB_NAME . ';charset=utf8', self::DB_USER, self::DB_PASS);
            try {
                $stmt = $db->prepare("INSERT INTO clover_merchant_behaviour (merchantID, merchantMarket, report, query, startdate, enddate, ordersItemsNo, inserted) VALUES (:merchantID, :merchantMarket, :report, :query, :startdate, :enddate, :ordersItemsNo, now() )");
                $stmt->bindValue(':merchantID', $this->merchantIdCaller, PDO::PARAM_STR);
                $stmt->bindValue(':merchantMarket', $this->apiType, PDO::PARAM_STR);
                $stmt->bindValue(':report', $this->reportName, PDO::PARAM_STR);
                $stmt->bindValue(':query', $this->get, PDO::PARAM_STR);
                $stmt->bindValue(':startdate', $this->reportStartDate, PDO::PARAM_STR);
                $stmt->bindValue(':enddate', $this->reportEndDate, PDO::PARAM_STR);
                $stmt->bindValue(':ordersItemsNo', $nmbOfOrders, PDO::PARAM_STR);
                $stmt->execute();
            } catch (PDOException $ex) {
                //var_dump($ex);
            }
        }
    }
    
    /**
     * @param string $error `report` field
     * @param string $query `query` field
     */
    public function auditError($error, $query) {
        if(self::$test) {
            $query = 'TEST | ' . $query;
        }
        if (!self::$test || true) {
            $db = new PDO('mysql:host=' . self::DB_HOST . ';dbname=' . self::DB_NAME . ';charset=utf8', self::DB_USER, self::DB_PASS);
            try {
                $stmt = $db->prepare("INSERT INTO clover_merchant_behaviour (merchantID, merchantMarket, report, query, startdate, enddate, ordersItemsNo, inserted) VALUES (:merchantID, :merchantMarket, :report, :query, :startdate, :enddate, :ordersItemsNo, now() )");
                $stmt->bindValue(':merchantID', $this->merchantIdCaller, PDO::PARAM_STR);
                $stmt->bindValue(':merchantMarket', $this->apiType, PDO::PARAM_STR);
                $stmt->bindValue(':report', $error, PDO::PARAM_STR);
                $stmt->bindValue(':query', $query, PDO::PARAM_STR);
                $stmt->bindValue(':startdate', $this->reportStartDate, PDO::PARAM_STR);
                $stmt->bindValue(':enddate', $this->reportEndDate, PDO::PARAM_STR);
                $stmt->bindValue(':ordersItemsNo', '0', PDO::PARAM_STR);
                $stmt->execute();
            } catch (PDOException $ex) {
                //var_dump($ex);
            }
        }
    }
    
    public static function log($filename, $msg, $create = false) {
        if ($create) {
            $file = fopen($filename, "w");
        } else {
            $file = fopen($filename, "a");
        }

        fwrite($file, $msg);
        fclose($file);
    }
    
    public static function returnData(&$returnData, $exit = true, $code = 200) {
        http_response_code($code);
        
        echo json_encode($returnData);

        if ($exit) {
            exit;
        }
    }

    public static function returnError($msg) {
        $returnData = array();
        $returnData['error'] = 1;
        $returnData['message'] = $msg;
        self::returnData($returnData, true, 500);
    }

    public static function errorHandler($error_no, $message, $file, $line, $context) {
        if (self::$test) {
            $file = basename($file);
            $msg = "Error: $message (File: $file, Line: $line)";
        } else {
            $msg = 'Unexpected error.';
        }

        switch ($error_no) {
            // ignore warnings and notices
            case E_WARNING:
                self::returnError($msg);
                break;
            case E_NOTICE:
            case E_USER_NOTICE:
            case E_USER_WARNING:
                break;
            // log PHP and user errors
            case E_ERROR:
                self::returnError($msg);
                break;
            case E_USER_ERROR:
                self::returnError($msg);
                break;
        }
    }

    /**
     * @param Exception $exception
     */
    public static function exceptionHandler($exception) {
        self::errorHandler(E_ERROR, $exception->getMessage(), $exception->getFile(), $exception->getLine(), null);
    }
    
    private function transform_hour_to_AM_PM($hour_orig) {
        return self::transformHourToAMPM($hour_orig, $this->apiType);
    }
    
    public static function transformHourToAMPM($hour_orig, $api_type) {
        if ($api_type != self::API_TYPE_US) {
            return $hour_orig;
        }

        $hour_return = '';
        switch ($hour_orig) {
            case '00': $hour_return = '00 (12AM)';
                break;
            case '01': $hour_return = '01 (1AM)';
                break;
            case '02': $hour_return = '02 (2AM)';
                break;
            case '03': $hour_return = '03 (3AM)';
                break;
            case '04': $hour_return = '04 (4AM)';
                break;
            case '05': $hour_return = '05 (5AM)';
                break;
            case '06': $hour_return = '06 (6AM)';
                break;
            case '07': $hour_return = '07 (7AM)';
                break;
            case '08': $hour_return = '08 (8AM)';
                break;
            case '09': $hour_return = '09 (9AM)';
                break;
            case '10': $hour_return = '10 (10AM)';
                break;
            case '11': $hour_return = '11 (11AM)';
                break;
            case '12': $hour_return = '12 (12PM)';
                break;
            case '13': $hour_return = '13 (1PM)';
                break;
            case '14': $hour_return = '14 (2PM)';
                break;
            case '15': $hour_return = '15 (3PM)';
                break;
            case '16': $hour_return = '16 (4PM)';
                break;
            case '17': $hour_return = '17 (5PM)';
                break;
            case '18': $hour_return = '18 (6PM)';
                break;
            case '19': $hour_return = '19 (7PM)';
                break;
            case '20': $hour_return = '20 (8PM)';
                break;
            case '21': $hour_return = '21 (9PM)';
                break;
            case '22': $hour_return = '22 (10PM)';
                break;
            case '23': $hour_return = '23 (11PM)';
                break;
            case '24': $hour_return = '24 (12AM)';
                break;
        }
        return $hour_return;
    }

    /**
     * @param string|int $minutes
     * @return string
     */
    private function getHourPart($minutes) {
        return (int)$minutes < 30 ? '00-29' : '30-59';
    }

    /**
     * @param string $time
     * @param string $merchantID
     * @return string
     */
    private function getDayPart($time, $merchantID) {
        $calculatedDayPart = '';
        $referenceDay = 0;
        while(true) {
            $referenceDay++;
            $rowTime = strtotime("2000-01-{$referenceDay} {$time}:00");
            foreach ($this->dayParts[$merchantID] as $dayPart) {
                if ($rowTime >= $dayPart['from'] && $rowTime < $dayPart['to']) {
                    $calculatedDayPart = $dayPart['name'] . ' (' . date('H:i', $dayPart['from']) . '-' . date('H:i', $dayPart['to']) . ')';
                    break;
                }
            }

            if (!empty($calculatedDayPart)) {
                break;
            }
        }

        return $calculatedDayPart;
    }

    /**
     * @param array $data
     * @param bool $unsetMerchantIdFromData
     * @throws Exception
     * @deprecated
     */
    private function dayParts(&$data, $unsetMerchantIdFromData = true) {
        if(empty($data) || !isset($data[0]['time']) || !isset($data[0]['merchantID'])) {
            return;
        }

        // We need to manually fill general settings.
        // Get all merchants associated with the caller.
        $db = new PDO('mysql:host=' . self::DB_HOST . ';dbname=' . self::DB_NAME . ';charset=utf8', self::DB_USER, self::DB_PASS);
        $stmt = $db->prepare("SELECT * FROM clover_multi_location WHERE merchantIDs LIKE :merchantID");
        $stmt->bindValue(':merchantID', '%' . $this->merchantIdCaller . '%', PDO::PARAM_STR);

        if (!$stmt->execute()) {
            throw new Exception('Internal server error (1).');
        }

        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if(!empty($results)) {
            $merchantIDs = explode(';', $results[0]['merchantIDs']);
        } else {
            $merchantIDs = array($this->merchantIdCaller);
        }

        // For each merchant, set up their
        $merchant_count = count($merchantIDs);
        $placeholders = array_fill(0, $merchant_count, '?');

        $stmt = $db->prepare("SELECT m.merchantID, m.merchantName, m.tierName, m.merchantTimezone, m.token, m.vat, mp.property_value
						  FROM clover_merchant m LEFT JOIN clover_merchant_properties mp ON m.merchantID = mp.merchant_id AND mp.property_type = 'general'
						  WHERE m.merchantID IN (" . implode(',', $placeholders) . ")");
        for ($i = 1; $i <= $merchant_count; $i++) {
            $stmt->bindValue($i, $merchantIDs[$i - 1], PDO::PARAM_STR);
        }

        if (!$stmt->execute()) {
            throw new Exception('Internal server error (2).');
        }

        $merchants = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $generalSettingsMulti = [];
        foreach($merchants as $merchant) {
            if(!empty($merchant['property_value'])) {
                $generalSettingsMulti[$merchant['merchantID']] = json_decode($merchant['property_value']);
            } else {
                $generalSettingsMulti[$merchant['merchantID']] = null;
            }
        }

        $dayParts = [];
        foreach($generalSettingsMulti as $merchantId => $generalSettings) {
            $dayParts[$merchantId] = [];
            if(isset($generalSettings->dayParts)) {
                foreach($generalSettings->dayParts as $dayPartId => $baseDayPart) {
                    if(empty($baseDayPart->name)) {
                        $baseDayPart->name = 'Part ' . substr($dayPartId, 1);
                    }

                    $fromHour = floor($baseDayPart->from);
                    $fromMinutes = 100 * 0.6 * ($baseDayPart->from - $fromHour);
                    $toHour = floor($baseDayPart->to);
                    $toMinutes = 100 * 0.6 * ($baseDayPart->to - $toHour);

                    $fromTS = strtotime("2000-01-01 {$fromHour}:{$fromMinutes}:00");
                    $toTS = strtotime("2000-01-01 {$toHour}:{$toMinutes}:00");
                    if($fromTS > $toTS) {
                        // day part spills to another day.
                        $toTS += 86400;
                    }

                    $dayParts[$merchantId][] = [
                        'name' => $baseDayPart->name,
                        'from' => $fromTS,
                        'to' => $toTS,
                    ];
                }
            } else {
                // default day parts.
                $dayParts[$merchantId][] = [
                    'name' => 'Part 1',
                    'from' => strtotime("2000-01-01 00:00:00"),
                    'to' => strtotime("2000-01-01 08:00:00"),
                ];
                $dayParts[$merchantId][] = [
                    'name' => 'Part 2',
                    'from' => strtotime("2000-01-01 08:00:00"),
                    'to' => strtotime("2000-01-01 12:00:00"),
                ];
                $dayParts[$merchantId][] = [
                    'name' => 'Part 3',
                    'from' => strtotime("2000-01-01 12:00:00"),
                    'to' => strtotime("2000-01-01 20:00:00"),
                ];
                $dayParts[$merchantId][] = [
                    'name' => 'Part 4',
                    'from' => strtotime("2000-01-01 20:00:00"),
                    'to' => strtotime("2000-01-01 24:00:00"), // 24:00 is 00:00 next day.
                ];
            }
        }

        foreach($data as &$row) {
            $referenceDay = 0;
            while(true) {
                $referenceDay++;
                $rowTime = strtotime("2000-01-{$referenceDay} {$row['time']}:00");
                foreach ($dayParts[$row['merchantID']] as $dayPart) {
                    if ($rowTime >= $dayPart['from'] && $rowTime < $dayPart['to']) {
                        $row['daypart'] = $dayPart['name'] . ' (' . date('H:i', $dayPart['from']) . '-' . date('H:i', $dayPart['to']) . ')';
                        break;
                    }
                }

                if(!empty($row['daypart'])) {
                    break;
                }
            }

            if($unsetMerchantIdFromData) {
                unset($row['merchantID']);
            }
        }
    }

    /**
     * @param object|null $generalSettings
     * @return array
     */
    private function getDayPartsFromSettings($generalSettings, $timezoneNew) {
        $timezoneBackup = date_default_timezone_get();
        date_default_timezone_set($timezoneNew);

        $dayParts = [];
        if(isset($generalSettings) && isset($generalSettings->dayParts)) {
            $index = 0;
            foreach ($generalSettings->dayParts as $dayPartId => $baseDayPart) {
                if (empty($baseDayPart->name)) {
                    $baseDayPart->name = 'Part ' . substr($dayPartId, 1);
                }
                $baseDayPart->name = (++$index) . '. ' . $baseDayPart->name;

                $fromHour = floor($baseDayPart->from);
                $fromMinutes = 100 * 0.6 * ($baseDayPart->from - $fromHour);
                $toHour = floor($baseDayPart->to);
                $toMinutes = 100 * 0.6 * ($baseDayPart->to - $toHour);

                $fromTS = strtotime("2000-01-01 {$fromHour}:{$fromMinutes}:00");
                $toTS = strtotime("2000-01-01 {$toHour}:{$toMinutes}:00");
                if ($fromTS > $toTS) {
                    // day part spills to another day.
                    $toTS += 86400;
                }

                $dayParts[] = [
                    'name' => $baseDayPart->name,
                    'from' => $fromTS,
                    'to' => $toTS,
                ];
            }
        } else {
            // default day parts.
            $dayParts[] = [
                'name' => 'Part 1',
                'from' => strtotime("2000-01-01 00:00:00"),
                'to' => strtotime("2000-01-01 08:00:00"),
            ];
            $dayParts[] = [
                'name' => 'Part 2',
                'from' => strtotime("2000-01-01 08:00:00"),
                'to' => strtotime("2000-01-01 10:00:00"),
            ];
            $dayParts[] = [
                'name' => 'Part 3',
                'from' => strtotime("2000-01-01 10:00:00"),
                'to' => strtotime("2000-01-01 14:00:00"),
            ];
            $dayParts[] = [
                'name' => 'Part 4',
                'from' => strtotime("2000-01-01 14:00:00"),
                'to' => strtotime("2000-01-01 16:00:00"),
            ];
            $dayParts[] = [
                'name' => 'Part 5',
                'from' => strtotime("2000-01-01 16:00:00"),
                'to' => strtotime("2000-01-01 19:00:00"),
            ];
            $dayParts[] = [
                'name' => 'Part 6',
                'from' => strtotime("2000-01-01 19:00:00"),
                'to' => strtotime("2000-01-01 20:00:00"),
            ];
            $dayParts[] = [
                'name' => 'Part 7',
                'from' => strtotime("2000-01-01 20:00:00"),
                'to' => strtotime("2000-01-01 22:00:00"),
            ];
            $dayParts[] = [
                'name' => 'Part 8',
                'from' => strtotime("2000-01-01 22:00:00"),
                'to' => strtotime("2000-01-01 24:00:00"), // 24:00 is 00:00 next day.
            ];
        }

        date_default_timezone_set($timezoneBackup);

        return $dayParts;
    }

    /**
     * @param int $timestamp
     * @param string|null $merchantID
     * @return int
     */
    private function getWeekNumberInYear($timestamp, $merchantID = null) {
        if(!isset($merchantID) || !isset($this->generalSettings[$merchantID])) {
            $merchantID = $this->merchantIdCaller;
        }

        if(isset($this->generalSettings[$merchantID]->startOfWeekMonday) && $this->generalSettings[$merchantID]->startOfWeekMonday === false) {
            $startOfWeekMonday = false;
        } else {
            $startOfWeekMonday = true;
        }

        if($startOfWeekMonday) {
            $weekNumber = date('W', $timestamp);
        } else {
            $weekNumber = date("W", $timestamp + 86400); // just add a full day.
            $month = date('m', $timestamp);
            $day = date('d', $timestamp);
            if($month == 12 && $day >= 28) {
                // but there is a special case if the Sunday falls on 28th December.
                // ISO (week start with Monday) 28th December always falls in last week of that year.
                // But if Sunday is first day of the week, everything is shifted and we have to manually fix it.
                // We have to manually fix week in case Sunday, Monday, Tuesday, Wednesday ('w' => 0, 1, 2, 3) fall on 28th, 29th, 30th and 31st December.
                $dayInWeek = date('w', $timestamp);
                if($day - $dayInWeek == 28) {
                    $year = date('Y', $timestamp);
                    $lastWeekNumberISO = date('W', strtotime("{$year}-12-28")); // 28th December is always in the last week of its year for ISO.
                    $weekNumber = $lastWeekNumberISO + 1;
                }
            } elseif($month == 1 && $day <= 3) {
                // ... and, continuing for that week, if Thursday, Friday, Saturday ('w' => 4, 5, 6) fall on 1st, 2nd, 3rd January.
                $dayInWeek = date('w', $timestamp);
                if ($day - $dayInWeek <= -3) {
                    $year = date('Y', $timestamp) - 1;
                    $lastWeekNumberISO = date('W', strtotime("{$year}-12-28")); // 28th December is always in the last week of its year for ISO.
                    // check if Thursday, Friday, Saturday ('w' => 4, 5, 6) fall on 1st, 2nd, 3rd January
                    // or Friday and/or Saturday fall on 1st and 2nd.
                    if ($day - $dayInWeek == -3) {
                        $weekNumber = $lastWeekNumberISO + 1;
                    } elseif (date('w', strtotime($year . '-01-01')) == 4) {
                        // ... and for all following weeks in the year that starts with Thursday, we have to lower the week number by one.
                        $weekNumber = $lastWeekNumberISO - 1;
                    } else {
                        $weekNumber = $lastWeekNumberISO;
                    }
                }
            }

            if(date('w', strtotime(date('Y', $timestamp) . '-01-01')) == 4 && ($month != 1 || $day > 3)) {
                // ... and for all following weeks in the year that starts with Thursday, we have to lower the week number by one.
                $weekNumber--;
            }
        }

        return (int)$weekNumber;
    }

    /**
     * @param float $number
     * @param int $precision
     * @param bool $onlyTruncate
     * @return float
     */
    public static function round($number, $precision = 2, $onlyTruncate = true) {
        if(!$onlyTruncate) {
            // do proper round.
            return round($number, $precision);
        } else {
            // just truncate trailing decimals.
            if(($pos = strpos($number, '.')) !== false) {
                $number = floatval(substr($number, 0, $pos + 1 + $precision));
            }
            return $number;
        }
    }

    /**
     * @param string $merchantID
     * @param string $propertyName
     * @param mixed $defaultValue
     * @return mixed
     */
    protected function getGeneralSetting($merchantID, $propertyName, $defaultValue) {
        if(isset($this->generalSettings[$merchantID]) && property_exists($this->generalSettings[$merchantID], $propertyName)) {
            return $this->generalSettings[$merchantID]->{$propertyName};
        } else {
            return $defaultValue;
        }
    }
}
