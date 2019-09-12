<?php

class CloverCache
{
    const CACHE_DIR_PATH = __DIR__ . '/cache';
    const CACHE_HASH_ALG = 'md5';
    const CACHE_SECRET = 'B511CECBC03700F94B5052C3';

    private $apiType = '';

    private $merchantIdCaller = '';
    private $merchantIds = [];
    private $merchantNames = [];
    public $merchantIdsExpiredCache = []; // Should be used after the call to CloverCache::checkCache()

    private $dateFormat = '';

    private $cacheIdentifier = null;

    public $cacheDevices = [];
    public $cacheEmployees = [];
    public $cacheCustomers = [];
    public $cacheItems = [];
    public $cacheMerchants = [];
    public $cacheModifications = [];
    public $cacheModificationGroups = [];
    public $cacheOrders = [];
    public $cacheOrderItems = [];
    public $cachePayments = [];

    // Hardcoded names of 'id' columns by tables.
    public static $cacheTableColumnId = [
        'devices' => ['merchantID', 'id'],
        'employees' => ['merchantID', 'id'],
        'customers' => ['merchantID', 'id'],
        'items' => ['merchantID', 'id'],
        'merchants' => ['id'],
        'orders' => ['order'],
        'order_items' => ['_id'],
        'modifications' => ['id'],
        'modification_groups' => ['id'],
        'payments' => ['payment'],
    ];

    // Hardcoded names of merchant ID column in tables. Needed only for basic cache tables.
    public static $cacheTableMerchantId = [
        'devices' => 'merchantID',
        'employees' => 'merchantID',
        'customers' => 'merchantID',
        'items' => 'merchantID',
        'merchants' => 'id',
        'orders' => null, //'merchantID',
        'order_items' => null,
        'modifications' => null, //'merchant_id',
        'modification_groups' => null,
        'payments' => null, //'merchantID',
    ];

    // Hardcoded 'date' fields.
    public static $cacheDateFields = [
        'date_iso_format' => 'date-iso',
        'date' => 'date',
        'hour' => 'hour',
    ];

    public static $dateFormatExpressions = [
        'Y-m-d' => '/^\d{4}-\d{1,2}-\d{1,2}$/',
        'd.m.Y' => '/^\d{1,2}\.\d{1,2}\.\d{4}$/',
        'm/d/Y' => '/^\d{1,2}\/\d{1,2}\/\d{4}$/',
    ];

    /**
     * @var PDO
     */
    private $cache;


    /**
     * Creates cache object, and creates new cache file if it does not exist or if 'forced' to.
     *
     * @param string $apiType
     * @param string $merchantIdCaller
     * @param array $merchantIds
     * @param array $merchantNames
     * @param bool $forceNew
     * @param string|null $cacheIdentifier String that will be a part of the cache file name. Defaults to merchantIdCaller.
     * @param string $dateFormat
     */
    public function __construct($apiType, $merchantIdCaller, $merchantIds, $merchantNames, $forceNew = false, $cacheIdentifier = null, $dateFormat = CloverMulti::DATE_FORMAT_ISO) {
        $this->apiType = $apiType;
        $this->merchantIdCaller = $merchantIdCaller;
        $this->merchantIds = $merchantIds;
        $this->merchantNames = $merchantNames;
        $this->dateFormat = $dateFormat;
        $this->cacheIdentifier = isset($cacheIdentifier) ? $cacheIdentifier : $this->merchantIdCaller;
        if(isset($cacheIdentifier)) {
            $this->cacheIdentifier = $cacheIdentifier;
        } else {
            $this->cacheIdentifier = $this->merchantIdCaller;
            if(!empty($_SERVER['REMOTE_ADDR'])) {
                $this->cacheIdentifier .= '-' . preg_replace('/[^0-9a-zA-Z]/', '_', $_SERVER['REMOTE_ADDR']);
            }
        }

        $filename = $this->getCachePath();
        if(!is_file($filename) || $forceNew) {
            // it will create a new cache.
            $this->createCacheStructure();
        } else {
            // we will use the existing cache.
            $this->cache = new PDO("sqlite:$filename");
            $this->cache->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, false);
            $this->cache->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
        }
    }

    /**
     * @return string
     */
    protected function getCachePath() {
        $identifier = $this->cacheIdentifier;

        $filename = "cache-{$identifier}";
        $hash = hash(self::CACHE_HASH_ALG, $filename . self::CACHE_SECRET);
        $filename = self::CACHE_DIR_PATH . "/{$filename}-{$hash}.sqlite";

        return $filename;
    }

    /**
     * Creates tables and INSERTS merchants.
     */
    protected function createCacheStructure() {
        $filename = $this->getCachePath();

        // delete old cache.
        if(is_file($filename)) {
            unlink($filename);
        }

        // create tables.
        $this->cache = new PDO("sqlite:$filename");
        $this->cache->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, false);
        $this->cache->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

        $tables = array();
        $tables[] = "CREATE TABLE merchants (
                        _id INTEGER PRIMARY KEY,
                        id TEXT,
                        name TEXT DEFAULT 'N/A',
                        cacheUpdatedTimestamp INTEGER DEFAULT 0
                    )";
        $tables[] = "CREATE TABLE devices (
                        _id INTEGER PRIMARY KEY,
                        id TEXT,
                        merchantID TEXT DEFAULT NULL,
                        merchantName TEXT DEFAULT 'N/A',
                        name TEXT DEFAULT 'Unknown device',
                        model TEXT DEFAULT 'Unknown device',
                        serial TEXT DEFAULT 'Unknown device'
                    )";
        $tables[] = "CREATE TABLE items (
                        _id INTEGER PRIMARY KEY,
                        id TEXT,
                        merchantID TEXT DEFAULT NULL,
                        merchantName TEXT DEFAULT 'N/A',
                        name TEXT DEFAULT 'N/A',
                        alternateName TEXT DEFAULT 'N/A',
                        sku TEXT DEFAULT 'N/A',
                        price REAL DEFAULT 0.0,
                        cost REAL DEFAULT 0.0,
                        code TEXT DEFAULT 'N/A',
                        priceType TEXT DEFAULT 'N/A',
                        unitName TEXT DEFAULT 'N/A',
                        stockCount INTEGER DEFAULT 0,
                        stockCount_deprecated INTEGER DEFAULT 0,
                        deleted INTEGER DEFAULT 1,
                        category TEXT DEFAULT 'Uncategorised',
                        category_1 TEXT DEFAULT 'Uncategorised',
                        category_2 TEXT DEFAULT 'Uncategorised',
                        category_3 TEXT DEFAULT 'Uncategorised',
                        tag TEXT DEFAULT 'Unlabeled',
                        tag_1 TEXT DEFAULT 'Unlabeled',
                        tag_2 TEXT DEFAULT 'Unlabeled',
                        tag_3 TEXT DEFAULT 'Unlabeled'
                    )";
        $tables[] = "CREATE TABLE employees (
                        _id INTEGER PRIMARY KEY,
                        id TEXT,
                        merchantID TEXT DEFAULT NULL,
                        merchantName TEXT DEFAULT 'N/A',
                        name TEXT DEFAULT 'N/A',
                        nickname TEXT DEFAULT 'N/A',
                        email TEXT DEFAULT 'N/A',
                        pin TEXT DEFAULT 'N/A',
                        role TEXT DEFAULT 'N/A'
                    )";
        $tables[] = "CREATE TABLE customers (
                        _id INTEGER PRIMARY KEY,
                        id TEXT,
                        merchantID TEXT DEFAULT NULL,
                        merchantName TEXT DEFAULT 'N/A',
                        name TEXT DEFAULT 'N/A',
                        marketingAllowed INTEGER DEFAULT 0,
                        customerSinceTimestamp INTEGER DEFAULT 0,
                        emailFirst TEXT DEFAULT 'N/A',
                        emailLast TEXT DEFAULT 'N/A',
                        emails TEXT DEFAULT 'N/A',
                        phoneNumberFirst TEXT DEFAULT 'N/A',
                        phoneNumberLast TEXT DEFAULT 'N/A',
                        phoneNumbers TEXT DEFAULT 'N/A',
                        addressFirstStreet TEXT DEFAULT 'N/A',
                        addressFirstCity TEXT DEFAULT 'N/A',
                        addressFirstCountry TEXT DEFAULT 'N/A',
                        addressFirstState TEXT DEFAULT 'N/A',
                        addressFirstZip TEXT DEFAULT 'N/A',
                        addressLastStreet TEXT DEFAULT 'N/A',
                        addressLastCity TEXT DEFAULT 'N/A',
                        addressLastCountry TEXT DEFAULT 'N/A',
                        addressLastState TEXT DEFAULT 'N/A',
                        addressLastZip TEXT DEFAULT 'N/A',
                        addressesCity TEXT DEFAULT 'N/A',
                        addressesCountry TEXT DEFAULT 'N/A',
                        addressesState TEXT DEFAULT 'N/A',
                        addressesZip TEXT DEFAULT 'N/A',
                        note TEXT DEFAULT 'N/A',
                        businessName TEXT DEFAULT 'N/A',
                        dobDay INT DEFAULT 0,
                        dobMonth INT DEFAULT 0,
                        dobYear INT DEFAULT 0
                    )";
        $tables[] = "CREATE TABLE orders (
                        _id INTEGER PRIMARY KEY,
                        `order` TEXT DEFAULT NULL,
                        trx_type TEXT DEFAULT 'N/A',
                        merchantID TEXT DEFAULT NULL,
                        merchantName TEXT DEFAULT 'N/A',
                        employee_id TEXT DEFAULT NULL,
                        employee TEXT DEFAULT 'N/A',
                        device_id TEXT DEFAULT NULL,
                        device_name TEXT DEFAULT 'N/A',
                        device_model TEXT DEFAULT 'N/A',
                        device_serial TEXT DEFAULT 'N/A',
                        orderType TEXT DEFAULT 'N/A',
                        orderTitle TEXT DEFAULT 'N/A',
                        original_date TEXT DEFAULT 'N/A',
                        date_iso_format TEXT DEFAULT 'N/A',
                        date TEXT DEFAULT 'N/A',
                        hour TEXT DEFAULT 'N/A',
                        hour_numeric TEXT DEFAULT 'N/A',
                        half_hour TEXT DEFAULT 'N/A',
                        daypart TEXT DEFAULT 'N/A',
                        time TEXT DEFAULT 'N/A',
                        day TEXT DEFAULT 'N/A',
                        day_of_week TEXT DEFAULT 'N/A',
                        week TEXT DEFAULT 'N/A',
                        year TEXT DEFAULT 'N/A',
                        customerId TEXT DEFAULT 'N/A',
                        customerName TEXT DEFAULT 'N/A',
                        customerEmail TEXT DEFAULT 'N/A',
                        customerPhoneNumber TEXT DEFAULT 'N/A',
                        customerSince INTEGER DEFAULT 0,
                        payment_id TEXT DEFAULT 'N/A',
                        payment_ids TEXT DEFAULT 'N/A',
                        payment_last4 TEXT DEFAULT 'N/A',
                        payment_last4s TEXT DEFAULT 'N/A',
                        paymentLabel TEXT DEFAULT 'N/A',
                        payment_tender_labels TEXT DEFAULT 'N/A',
                        total_order REAL DEFAULT 0,
                        dummy INTEGER DEFAULT 1,
                        cost_and_tax INTEGER DEFAULT 0,
                        payment_profit INTEGER DEFAULT 0,
                        taxRemoved INTEGER DEFAULT 0,
                        isVat INTEGER DEFAULT 0,
                        payType TEXT DEFAULT 'N/A',
                        manualTransaction INTEGER DEFAULT 0,
                        note TEXT DEFAULT 'N/A',
                        
                        total REAL DEFAULT 0,
                        tipAmount REAL DEFAULT 0,
                        serviceCharge REAL DEFAULT 0,
                        tax_amount_pay REAL DEFAULT 0,
                        total_gross REAL DEFAULT 0,
                        tax_amount_pay_gross REAL DEFAULT 0,
                        total_refund REAL DEFAULT 0,
                        total_refund_tax REAL DEFAULT 0,
                        credit_amount REAL DEFAULT 0,
                        credit_taxamount REAL DEFAULT 0,
                        
                        card_type TEXT DEFAULT 'N/A',
                        entryType TEXT DEFAULT 'N/A',
                        type TEXT DEFAULT 'N/A',
                        state TEXT DEFAULT 'N/A',
                        payment_date_iso_format TEXT DEFAULT 'N/A',
                        payment_date TEXT DEFAULT 'N/A',
                        payment_time TEXT DEFAULT 'N/A',
                        
                        total_minus_tax REAL DEFAULT 0,
                        discount_order_percent REAL DEFAULT 0,
                        discount_order_amount REAL DEFAULT 0,
                        discount_order_names TEXT DEFAULT 'N/A',
                        
                        total_cost REAL DEFAULT 0,
                        total_price REAL DEFAULT 0,
                        total_discount REAL DEFAULT 0,
                        total_tax REAL DEFAULT 0,
                        total_profit REAL DEFAULT 0
                    )";
        $tables[] = "CREATE TABLE order_items(
                        _id INTEGER PRIMARY KEY,
                        item_id TEXT DEFAULT NULL,
                        order__id TEXT DEFAULT NULL,
                        item TEXT DEFAULT 'N/A',
                        noteItem TEXT DEFAULT 'N/A',
                        
                        alternateName TEXT DEFAULT 'N/A',
                        sku TEXT DEFAULT 'N/A',
                        itemCode TEXT DEFAULT 'N/A',
                        stockCount REAL DEFAULT 0,
                        category TEXT DEFAULT 'Uncategorised',
                        category_1 TEXT DEFAULT 'Uncategorised',
                        category_2 TEXT DEFAULT 'Uncategorised',
                        category_3 TEXT DEFAULT 'Uncategorised',
                        tag TEXT DEFAULT 'Unlabeled',
                        tag_1 TEXT DEFAULT 'Unlabeled',
                        tag_2 TEXT DEFAULT 'Unlabeled',
                        tag_3 TEXT DEFAULT 'Unlabeled',
                        
                        price REAL DEFAULT 0,
                        orig_price REAL DEFAULT 0,
                        cost REAL DEFAULT 0,
                        quantity REAL DEFAULT 0,
                        atLeastOneRefundedItem INTEGER DEFAULT 0,
                        refunded INTEGER DEFAULT 0,
                        isRevenue INTEGER DEFAULT 0,
                        exchanged INTEGER DEFAULT 0,
                        
                        modifications REAL DEFAULT 0,
                        orig_modifications REAL DEFAULT 0,
                        modification_names TEXT DEFAULT '',
                        discounts REAL DEFAULT 0,
                        discounts_amount REAL DEFAULT 0,
                        discount_amount REAL DEFAULT 0,
                        discount_item_names TEXT DEFAULT 'N/A',
                        itm_disc_amount_from_perc_on_order REAL DEFAULT 0,
                        itm_disc_amount_from_perc REAL DEFAULT 0,
                        
                        _itm_disc_from_item_perc REAL DEFAULT 0,
                        _itm_disc_from_item_abs REAL DEFAULT 0,
                        _itm_disc_from_order_perc REAL DEFAULT 0,
                        _tax_amount REAL DEFAULT 0,
                        _tax_rate REAL DEFAULT 0,
                        _tax_rates TEXT DEFAULT 'N/A',
                        _price_final REAL DEFAULT 0,
                        _price_final_minus_tax REAL DEFAULT 0,
                        
                        tmp_price_final REAL DEFAULT 0,
                        
                        tax_amount REAL DEFAULT 0,
                        tax_rate REAL DEFAULT 0,
                        tax_rates TEXT DEFAULT 'N/A',
                        tax_name TEXT DEFAULT 'N/A',
                        tax_names TEXT DEFAULT 'N/A',
                        
                        price_final_minus_tax REAL DEFAULT 0,
                        price_final REAL DEFAULT 0,
                        
                        profit REAL DEFAULT 0,
                        price_minus_tax REAL DEFAULT 0,
                        item_total_discount_amount REAL DEFAULT 0,
                        
                        calc_total_discount_on_item REAL DEFAULT 0,
                        _price REAL DEFAULT 0,
                        coeff_discount REAL DEFAULT 0,
                        _itm_disc_from_order_abs REAL DEFAULT 0,
                        coeff_service REAL DEFAULT 0,
                        serviceCharge_coeff_amount REAL DEFAULT 0,
                        refund_coeff_amount REAL DEFAULT 0,
                        coeff_refund REAL DEFAULT 0
                    )";
        $tables[] = "CREATE TABLE modifications(
                        _id INTEGER PRIMARY KEY,
                        id TEXT DEFAULT 'N/A',
                        name TEXT DEFAULT 'N/A',
                        alternateName TEXT DEFAULT 'N/A',
                        amount REAL DEFAULT 0,
                        merchant_id TEXT DEFAULT 'N/A',
                        merchantName TEXT DEFAULT 'N/A',
                        item_id TEXT DEFAULT 'N/A',
                        itemName TEXT DEFAULT 'N/A',
                        itemSKU TEXT DEFAULT 'N/A'
                    )";
        $tables[] = "CREATE TABLE modification_groups(
                        _id INTEGER PRIMARY KEY,
                        id TEXT DEFAULT 'N/A',
                        name TEXT DEFAULT 'N/A',
                        alternateName TEXT DEFAULT 'N/A',
                        amount REAL DEFAULT 0,
                        merchant_id TEXT DEFAULT 'N/A',
                        merchantName TEXT DEFAULT 'N/A'
                    )";
        $tables[] = "CREATE TABLE payments (
                        _id INTEGER PRIMARY KEY,
                        payment TEXT,
                        trx_type TEXT DEFAULT 'N/A',
                        merchantID TEXT DEFAULT NULL,
                        merchantName TEXT DEFAULT 'N/A',
                        employee_id TEXT DEFAULT NULL,
                        employee TEXT DEFAULT 'N/A',
                        `order` TEXT DEFAULT NULL,
                        payment_title TEXT DEFAULT 'N/A',
                        original_date TEXT DEFAULT 'N/A',
                        date_iso_format TEXT DEFAULT 'N/A',
                        date TEXT DEFAULT 'N/A',
                        hour TEXT DEFAULT 'N/A',
                        hour_numeric TEXT DEFAULT 'N/A',
                        half_hour TEXT DEFAULT 'N/A',
                        time TEXT DEFAULT 'N/A',
                        daypart TEXT DEFAULT 'N/A',
                        day TEXT DEFAULT 'N/A',
                        day_of_week TEXT DEFAULT 'N/A',
                        week TEXT DEFAULT 'N/A',
                        year TEXT DEFAULT 'N/A',
                        customerId TEXT DEFAULT 'N/A',
                        customerName TEXT DEFAULT 'N/A',
                        customerEmail TEXT DEFAULT 'N/A',
                        customerPhoneNumber TEXT DEFAULT 'N/A',
                        customerSince INTEGER DEFAULT 0,
                        paymentLabel TEXT DEFAULT 'N/A',
                        result TEXT DEFAULT 'N/A',
                        dummy INTEGER DEFAULT 1,
                        amount INTEGER DEFAULT 0,
                        taxAmount INTEGER DEFAULT 0,
                        credit_amount INTEGER DEFAULT 0,
                        credit_taxamount INTEGER DEFAULT 0,
                        refund_amount INTEGER DEFAULT 0,
                        refund_taxamount INTEGER DEFAULT 0,
                        tipAmount INTEGER DEFAULT 0,
                        cardType TEXT DEFAULT 'N/A',
                        entryType TEXT DEFAULT 'N/A',
                        type TEXT DEFAULT 'N/A',
                        state TEXT DEFAULT 'N/A',
                        last4 TEXT DEFAULT 'N/A',
                        authCode TEXT DEFAULT 'N/A'
                    )";

        foreach($tables as $tableDDL) {
            $this->cache->exec($tableDDL);
        }

        $this->cache->beginTransaction();
        $query = "INSERT INTO merchants (id,name) VALUES (?,?)";
        $stmt = $this->cache->prepare($query);
        foreach($this->merchantIds as $merchantId) {
            $stmt->bindValue(1, $merchantId, PDO::PARAM_STR);
            $stmt->bindValue(2, $this->merchantNames[$merchantId], PDO::PARAM_STR);
            $stmt->execute();
        }

        $this->cache->commit();
    }

    /**
     * @param string $table
     * @param array $data
     * @return bool
     */
    public function insertCacheData($table, &$data) {
        // check if data is empty.
        if(!count($data)) {
            return true;
        }

        // get columns from data.
        $columns = array_keys(reset($data));
        $columnNames = [];
        foreach($columns as $column) {
            $columnNames[] = '"' . $column . '"'; // "order"
        }
        $placeholders = array_fill(0, count($columns), '?');

        $columnNames = implode(',', $columnNames);
        $columnValuePlaceholder = implode(',', $placeholders);

        $query = "INSERT INTO $table ($columnNames) VALUES ($columnValuePlaceholder)";
        $this->cache->beginTransaction();
        $stmt = $this->cache->prepare($query);
        foreach($data as $row) {
            $i = 1;
            foreach($columns as $column) {
                $stmt->bindValue($i, $row[$column], PDO::PARAM_STR);
                $i++;
            }
            $stmt->execute();
        }
        $this->cache->commit();

        return true;
    }

    /**
     * @param array|null $merchantIds
     * @return bool
     */
    public function updateCacheTime($merchantIds = null) {
        if(!isset($merchantIds)) {
            $merchantIds = $this->merchantIds;
        }

        $timestamp = time();
        $query = "UPDATE merchants SET cacheUpdatedTimestamp = $timestamp WHERE id = ?";
        $this->cache->beginTransaction();
        $stmt = $this->cache->prepare($query);
        foreach($merchantIds as $merchantId) {
            $stmt->bindValue(1, $merchantId, PDO::PARAM_STR);
            $stmt->execute();
        }
        $this->cache->commit();

        return true;
    }

    /**
     * Returns all rows from the specified table. Returned columns can be specified.
     *
     * @param string $table
     * @param string $columns
     * @param array $idColumns If empty, index in returning array will be default ID columns. Otherwise index will be concatenation of these columns.
     * @return array
     */
    private function getCacheData($table, $columns = '*', $idColumns = []) {
        $query = "SELECT $columns FROM $table";
        $stmt = $this->cache->query($query);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if(empty($results)) {
            // in case merchant location does not have defined devices or employees or items.
            return [];
        }

        // if there are no 'id' column(s) in result, we return numeric array...
        $idColumnsMain = !empty($idColumns) ? $idColumns : self::$cacheTableColumnId[$table];
        foreach($idColumnsMain as $idColumn) {
            if(!array_key_exists($idColumn, $results[0])) {
                return $results;
            }
        }

        // otherwise we return array indexed by the value of 'id' column.
        $indexedResult = [];
        foreach($results as $row) {
            $index = '';
            foreach($idColumnsMain as $idColumn) {
                $index .= $row[$idColumn];
            }

            $indexedResult[$index] = $row;
        }
        return $indexedResult;
    }

    /**
     * Updates local cache array(s). Arrays are indexed by column 'id'.
     *
     * @param string $table
     * @param bool $forceNew Whether to force updating local cache array even if it is already defined.
     */
    public function updateLocalCacheData($table, $forceNew = false) {
        if(!$forceNew) {
            switch ($table) {
                case 'devices':
                    if(count($this->cacheDevices)) { return; } break;
                case 'employees':
                    if(count($this->cacheEmployees)) { return; } break;
                case 'customers':
                    if(count($this->cacheCustomers)) { return; } break;
                case 'items':
                    if(count($this->cacheItems)) { return; } break;
                case 'merchants':
                    if(count($this->cacheMerchants)) { return; } break;
                case 'modifications':
                    if(count($this->cacheModifications)) { return; } break;
                case 'modification_groups':
                    if(count($this->cacheModificationGroups)) { return; } break;
                case 'orders':
                    if(count($this->cacheOrders)) { return; } break;
                case 'order_items':
                    if(count($this->cacheOrderItems)) { return; } break;
                case 'payments':
                    if(count($this->cachePayments)) { return; } break;
            }
        }

        // if we force new cache data or if the requested data is not locally cached.
        $results = $this->getCacheData($table);

        switch ($table) {
            case 'devices':
                $this->cacheDevices = $results; break;
            case 'employees':
                $this->cacheEmployees = $results; break;
            case 'customers':
                $this->cacheCustomers = $results; break;
            case 'items':
                $this->cacheItems = $results; break;
            case 'merchants':
                $this->cacheMerchants = $results; break;
            case 'modifications':
                $this->cacheModifications = $results; break;
            case 'modification_groups':
                $this->cacheModificationGroups = $results; break;
            case 'orders':
                $this->cacheOrders = $results; break;
            case 'order_items':
                $this->cacheOrderItems = $results; break;
            case 'payments':
                $this->cachePayments = $results; break;
        }
    }

    /**
     * Checks if cache is still valid, not expired, for selected merchants. Adds new merchants if there not present in cache.
     *
     * @return bool
     */
    public function checkCache() {
        // 1st check for possible filtering on the frontend - selected new merchant that is not in cache.
        $query = "SELECT id AS merchantId FROM merchants";
        $stmt = $this->cache->query($query);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $existingMerchantIds = [];
        foreach($results as $row) {
            $existingMerchantIds[$row['merchantId']] = $row['merchantId'];
        }

        $newMerchantIds = [];
        foreach($this->merchantIds as $merchantId) {
            // check for each new merchant if maybe it is not among already cached merchants.
            if(!isset($existingMerchantIds[$merchantId])) {
                $newMerchantIds[] = $merchantId;
            }
        }

        if(count($newMerchantIds)) {
            // if there are new merchants, add them to cache and return that cache is not valid.
            $query = "INSERT INTO merchants (id,name) VALUES (?,?)";
            $this->cache->beginTransaction();
            $stmt = $this->cache->prepare($query);
            foreach($newMerchantIds as $merchantId) {
                $stmt->bindValue(1, $merchantId, PDO::PARAM_STR);
                $stmt->bindValue(2, $this->merchantNames[$merchantId], PDO::PARAM_STR);
                $stmt->execute();
            }

            $this->cache->commit();
        }

        // 2nd check if cache has expired.
        $timestamp = time();
        $duration = CloverMulti::SESSION_VALID_FOR;
        $merchantIds = "'" . implode("','", $this->merchantIds) . "'";
        $query = "SELECT id AS merchantID FROM merchants WHERE $timestamp - cacheUpdatedTimestamp > $duration AND id IN ($merchantIds)";
        $stmt = $this->cache->query($query);
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $this->merchantIdsExpiredCache = [];
        foreach($result as $merchantRow) {
            $this->merchantIdsExpiredCache[$merchantRow['merchantID']] = $merchantRow['merchantID'];
        }

        return (count($this->merchantIdsExpiredCache) ? false : true);
    }

    /**
     * @param string $table
     * @param bool $castMeasures
     * @param string|array $selectColumns
     * @param int|null $limit
     * @return array
     */
    public function getRawData($table, $castMeasures = false, $selectColumns = '*', $limit = null) {
        // special case for selecting RAW from orders and order_items because of overlapping column names.
        if($selectColumns == '*' && in_array($table, ['orders', 'order_items'])) {
            $selectColumns = '"orders".*';
            if($table == 'order_items') {
                $selectColumns .= ',"order_items".*';
            }
            $tmpColumnsData = $this->getTableColumns('customers', false);
            foreach($tmpColumnsData as $columnName) {
                if(!in_array($columnName, ['_id', 'merchantID', 'merchantName', 'note'])) {
                    $selectColumns .= ',"customers"."' . $columnName . '"';
                }
            }
        }


        $selectClause = "SELECT ";
        if(is_array($selectColumns)) {
            $selectClause .= '"' . implode('","', $selectColumns) . '"';
        } else {
            $selectClause .= (string)$selectColumns;
        }

        $columnsToFloat = [];
        if($table == 'order_items') {
            $fromClause = 'FROM "orders" JOIN "order_items" ON "orders"."order" = "order_items"."order__id" AND "orders"."trx_type" = \'ORDER\'
                            LEFT JOIN "customers" ON "orders"."customerId" = "customers"."id"';

            if($castMeasures) {
                foreach(['orders', 'order_items', 'customers'] as $tableName) {
                    $tmpColumnsData = $this->getTableColumns($tableName, true);
                    foreach ($tmpColumnsData as $columnName => $columnType) {
                        if ($columnName != '_id' && ($columnType == 'INTEGER' || $columnType == 'REAL')) {
                            $columnsToFloat[$columnName] = $columnName;
                        }
                    }
                }
            }
        } elseif($table == 'orders') {
            $fromClause = 'FROM "orders" LEFT JOIN "customers" ON "orders"."customerId" = "customers"."id"';

            if($castMeasures) {
                foreach(['orders', 'customers'] as $tableName) {
                    $tmpColumnsData = $this->getTableColumns($tableName, true);
                    foreach ($tmpColumnsData as $columnName => $columnType) {
                        if ($columnName != '_id' && ($columnType == 'INTEGER' || $columnType == 'REAL')) {
                            $columnsToFloat[$columnName] = $columnName;
                        }
                    }
                }
            }
        } else {
            $fromClause = "FROM \"{$table}\"";

            if($castMeasures) {
                $tmpColumnsData = $this->getTableColumns($table, true);
                foreach($tmpColumnsData as $columnName => $columnType) {
                    if($columnName != '_id' && ($columnType == 'INTEGER' || $columnType == 'REAL')) {
                        $columnsToFloat[$columnName] = $columnName;
                    }
                }
            }
        }

        $orderLimitClause = "ORDER BY \"{$table}\".\"_id\"";
        if(isset($limit)) {
            $limit = (int)$limit;
            $orderLimitClause .= " LIMIT {$limit}";
        }

        $query = "{$selectClause} {$fromClause} {$orderLimitClause}";
        $stmt = $this->cache->query($query);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if(count($columnsToFloat)) {
            foreach($results as &$row) {
                foreach($columnsToFloat as $columnName) {
                    if(array_key_exists($columnName, $row)) {
                        $row[$columnName] = (float)$row[$columnName];
                    }
                }
            }
        }
        
        return $results;
    }

    /**
     * @param string $table
     * @param array $fields
     * @param string|null $splitField
     * @param array|null $filters
     * @param bool $doGrouping
     * @param bool $doFilling
     * @param string|null $decimalSeparator
     * @param bool $addAggToAlias
     * @return array
     * @throws Exception
     */
    public function groupCacheData($table, $fields, $splitField = null, $filters = null, $doGrouping = true, $doFilling = false, $decimalSeparator = null, $addAggToAlias = true) {
        // Check the table exists.
        $stmt = $this->cache->query("SELECT count(*) AS exist FROM sqlite_master WHERE type='table' AND name='$table'");
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        if(!$result['exist']) {
            throw new Exception("Specified table '$table' does not exist.");
        }

        $query = $this->createGroupCacheSql($table, $fields, $splitField, $filters, $doGrouping, $selectFieldsCorrected, $addAggToAlias);

        $stmt = $this->cache->query($query);

        if(!$stmt) {
            $error_info = $this->cache->errorInfo();
            if(!is_array($error_info) || !$error_info[2]) {
                $error_info = 'Unknown error while running query.';
            }
            else {
                $error_info = $error_info[2];
            }
            throw new Exception($error_info);
        }

        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Filling of missing dimension values - used for generated date columns.
        if($doFilling) {
            foreach($fields as $selectField) {
                if($selectField->type == 'dimension' && isset(self::$cacheDateFields[$selectField->name])) {
                    $fillField = $selectField;
                    break;
                }
            }

            if(isset($fillField) && count($results)) {
                $this->fillMissingDimensionValues($results, $selectFieldsCorrected, $fillField);
            }
        }

        // CAST measures to float.
        $selectMeasures = array();
        foreach ($selectFieldsCorrected as $columnName => $columnData) {
            if ($columnData['type'] == 'measure') {
                $selectMeasures[$columnName] = $columnName;
            }
        }

        if (count($selectMeasures)) {
            // A bit of optimization - to avoid this IF for every data row and measure column.
            if(!empty($decimalSeparator)) {
                // If we use decimal separator, measure value will be string.
                foreach ($results as &$resultRow) {
                    foreach ($selectMeasures as $selectMeasure) {
                        if (isset($resultRow[$selectMeasure])) {
                            $resultRow[$selectMeasure] = str_replace('.', $decimalSeparator, $resultRow[$selectMeasure]);
                        }
                    }
                }
            } else {
                foreach ($results as &$resultRow) {
                    foreach ($selectMeasures as $selectMeasure) {
                        if (isset($resultRow[$selectMeasure])) {
                            $resultRow[$selectMeasure] = (float)$resultRow[$selectMeasure];
                        }
                    }
                }
            }
        }

        return $results;
    }

    /**
     * @param array $results
     * @param array $selectFieldsCorrected
     * @param object $fillField
     */
    private function fillMissingDimensionValues(&$results, &$selectFieldsCorrected, $fillField) {
        $fillColumnName = $fillField->alias;
	    $fillColumnType = self::$cacheDateFields[$fillField->name];
        if($fillColumnType == 'date' && $this->getDateFormat() != CloverMulti::DATE_FORMAT_ISO) {
            $this->orderData($results, ['name' => $fillColumnName, 'format' => CloverMulti::DATE_FORMAT_ISO], SORT_ASC);
        } else {
            $this->orderData($results, $fillColumnName, SORT_ASC);
        }

        $fillIndexes = array();
        $templateRow = array(); // template fill row -> all dims are '' and all measures are 0.
        foreach($selectFieldsCorrected as $columnName => $columnData) {
            $templateRow[$columnName] = $columnData['type'] == 'measure' ? 0 : '';
        }
        
        $indexFirstValue = 0;
        $nmbResults = count($results);
        $currentValue = $results[$indexFirstValue][$fillColumnName]; // 1st value
	    $nextValue = $this->nextDateValue($currentValue, $fillColumnType);
        for($key = $indexFirstValue; $key < $nmbResults; $key++) {
            $rowResult = $results[$key];
            if($rowResult[$fillColumnName] == $currentValue) {
                continue;
            } elseif($rowResult[$fillColumnName] == $nextValue) {
                $currentValue = $nextValue;
                $nextValue = $this->nextDateValue($currentValue, $fillColumnType);
                continue;
            }
            
            while($rowResult[$fillColumnName] != $nextValue) {
                $row = $templateRow;
                $row[$fillColumnName] = (string)$nextValue;
                if($fillColumnType == 'date' && array_key_exists('day', $row)) {
                    $dt = new DateTime($row[$fillColumnName]);
                    $row['day'] = $dt->format('l');
                }
                if($fillColumnType == 'date' && array_key_exists('date_iso_format', $row)) {
                    $dt = new DateTime($row[$fillColumnName]);
                    $row['date_iso_format'] = $dt->format(CloverMulti::DATE_FORMAT_ISO);
                }
                if(!isset($fillIndexes[$key])) {
                    $fillIndexes[$key] = array();
                }
                array_unshift($fillIndexes[$key], $row);
    
                $nextValue = $this->nextDateValue($nextValue, $fillColumnType);
            }
            
            $currentValue = $nextValue;
		    $nextValue = $this->nextDateValue($currentValue, $fillColumnType);
        }
        
        $fillIndexes = array_reverse($fillIndexes, true);
        foreach($fillIndexes as $index => $fills) {
            foreach($fills as $fill) {
                array_splice($results, $index, 0, array($fill));
            }
        }
    }

    /**
     * @param array $order_items
     * @param array $orders
     * @param array $orders_index
     * @param array $vats
     * @param array $orders_refund_no_propagate
     */
    public function manipulateItemLevelAmounts(&$order_items, &$orders, &$orders_index, &$vats, &$orders_refund_no_propagate)
    {
        //Note
        //When rounding decimal amounts smaller than the cent — such as tax per line Item —
        //rounding should be calculated with the Round Half Up tie-breaking rule, meaning fives will be rounded up.
        //Important
        //if $vat=true in a merchant’s properties, that merchant uses a VAT (Value Added Tax).
        //This means the tax amount is already included in the lineItem total – no additional amount should be added for tax.

        $orders_reset_total_profit = [];
        foreach ($order_items as &$orderItem) {
            $order = &$orders[$orders_index[$orderItem['order__id']]];

            $orderItem["calc_total_discount_on_item"] = 0;
            $orderItem["price_final_minus_tax"] = 0;
            $orderItem["price_final"] = 0;
            $orderItem["_price"] = 0;

            $atLeastOneRefundedItem = (bool)$orderItem["atLeastOneRefundedItem"];
            $refunded = (bool)$orderItem["refunded"];
            $taxRates = isset($orderItem["tax_rates"]) ? explode(', ', $orderItem["tax_rates"]) : null;
            $vat = isset($vats[$order['merchantID']]) ? $vats[$order['merchantID']] : '0';

            /**
             *  DISCOUNTS HANDLER!!!!!!
             */
            $orderItem["coeff_discount"] = 0;
            $orderItem["_itm_disc_from_order_abs"] = 0;
            if (!$orderItem["exchanged"]) {
                // if item price is really negative, no need to later force it to 0.
                $itemPriceIsNegative = $orderItem["orig_price"] < 0;

                //a. Start with item price
                $orderItem["_price"] = $orderItem["orig_price"];
                //b. Apply any modifier costs to find lineItem’s pre-discount total
                $orderItem["_price"] = $orderItem["_price"] + $orderItem["modifications"];
                //c. Subtract all percentage lineItem discounts (all percentages based on lineItem’s pre-discount total) ITEM PERC
                $orderItem["_price"] = $orderItem["_price"] - $orderItem["itm_disc_amount_from_perc"];
                $orderItem["calc_total_discount_on_item"] += $orderItem["itm_disc_amount_from_perc"];
                //d. Subtract all amount lineItem discounts to find lineItem’s discounted total ITEM ABS
                $orderItem["_price"] = $orderItem["_price"] - $orderItem["discounts_amount"];
                $orderItem["calc_total_discount_on_item"] += $orderItem["discounts_amount"];
                //e. Subtract all order level percentage discounts ORDER PERC
                if ($order["discount_order_percent"] != 0) {
                    $orderItem["_itm_disc_from_order_perc"] = $orderItem["_price"] * $order["discount_order_percent"] / 100;
                    $orderItem["_price"] = $orderItem["_price"] - $orderItem["_itm_disc_from_order_perc"];
                    $orderItem["calc_total_discount_on_item"] += $orderItem["_itm_disc_from_order_perc"];
                }
                //Subtract all order level amount discounts ORDER ABS
                if ($order["discount_order_amount"] != 0) {

                    if ($vat === "1" || $vat === 1) {
                        $orderItem["coeff_discount"] = 1 - (-$order["discount_order_amount"] / ($order["total_gross"] - $order["discount_order_amount"]));
                    } else {
                        $orderItem["coeff_discount"] = 1 - (-$order["discount_order_amount"] / ($order["total_gross"] - $order["tax_amount_pay"] - $order["discount_order_amount"]));
                    }
                    $orderItem["_itm_disc_from_order_abs"] = $orderItem["_price"] - ($orderItem["coeff_discount"] * $orderItem["_price"]);
                    $orderItem["calc_total_discount_on_item"] += $orderItem["_itm_disc_from_order_abs"];
                    // adjust item price for all items on an order
                    $orderItem["_price"] = $orderItem["coeff_discount"] * $orderItem["_price"];

                }
                if ($orderItem["_price"] < 0 && !$itemPriceIsNegative) {
                    $orderItem["_price"] = 0;
                    $orderItem["calc_total_discount_on_item"] = $orderItem["orig_price"] / 1 + $orderItem["modifications"];
                }

            } else {
                $orderItem["_price"] = 0;
                $orderItem["_itm_disc_from_order_abs"] = 0;
                $orderItem["_itm_disc_from_order_perc"] = 0;
                $orderItem["itm_disc_amount_from_perc"] = 0;
                $orderItem["discounts_amount"] = 0;
            }

            /**
             *  SERVICE CHARGE HANDLER!!!!!!
             */
            $orderItem["coeff_service"] = 0;
            $orderItem["serviceCharge_coeff_amount"] = 0;
            if ($order["serviceCharge"] > 0) {
                //primjeniti service charge sada na iteme preko coefficienta
                if ($vat === "1" || $vat === 1) {
                    $orderItem["coeff_service"] = ($order["serviceCharge"] / ($order["total_order"] - $order["serviceCharge"]));
                    $orderItem["serviceCharge_coeff_amount"] = $orderItem["coeff_service"] * $orderItem["_price"];
                } else {
                    $orderItem["coeff_service"] = ($order["serviceCharge"] / ($order["total_order"] - $order["serviceCharge"] - $order["tax_amount_pay"]));
                    $orderItem["serviceCharge_coeff_amount"] = $orderItem["coeff_service"] * $orderItem["_price"];
                }
            }


//      ********************** NOW; price modifed as such is GROSS actually *** for EU!
            $orderItem["price_final"] = $orderItem["_price"];
            /**
             *  REFUND FROM ORDER LEVEL HANDLER!!!!!!
             */
            //primjeniti refund sada na iteme preko coefficienta, ali samo ako nije neki item refundiran
            $orderItem["refund_coeff_amount"] = 0;
            if ($order["total_refund"] > 0 && $atLeastOneRefundedItem === false) {

                // 2018-02-28 - revertan dio oko 'orders_refund_no_propagate' -> krivo se bio prikazivao profit, item net...
                if ($vat === "1" || $vat === 1) {
                    if($order["total_gross"] - $order["total_refund"] == $order["total_order"]) {
                        // 2018-04-06 - ako je total (cijena na order-u) jednak paymentima - refundu, onda ne trebamo racunati refund koef.
                        $orderItem["coeff_refund"] = 0;
                    } else {
                        $orderItem["coeff_refund"] = ($order["total_refund"] / $order["total_gross"]); // payed gross - refund_gross
                    }
                } else {
                    if($order["total_gross"] - $order["tax_amount_pay"] - $order["total_refund"] == $order["total_order"]) {
                        $orderItem["coeff_refund"] = 0;
                    } else {
                        $orderItem["coeff_refund"] = ($order["total_refund"] / ($order["total_gross"] - $order["tax_amount_pay"])); // payed gross - refund_gross
                    }
                }

                $orderItem["refund_coeff_amount"] = $orderItem["coeff_refund"] * $orderItem["_price"];
                $orderItem["_price"] = $orderItem["_price"] - $orderItem["refund_coeff_amount"];

                // 2018-02-28 - refund na order levelu propagirati i na item cost.
                $refund_coeff_amount_cost = $orderItem["coeff_refund"] * $orderItem["cost"];
                $orderItem["cost"] = $orderItem["cost"] - $refund_coeff_amount_cost;
            } else if ($order["total_refund"] > 0 && $atLeastOneRefundedItem === true) {
                // adjust item price for all items on an order
                if ($refunded === true || $refunded === "true") {
                    //hrvoje 08.12.2016
                    $orderItem["refund_coeff_amount"] = $orderItem["_price"];
                    $orderItem["_price"] = 0;

                }
            }

            /**
             *  GROSS NET TAX VALUES HANDLER!!!!!!
             */

            //2. Calculate tax per taxable line item
            $orderItem["tax_amount"] = 0;

            if ($vat === "1" || $vat === 1) {

                if (isset($taxRates)) {

                    $taxCummulative = 0;
                    foreach ($taxRates as $tax) {
                        if ($tax != 0 && $orderItem["_price"] != 0) {
                            $taxCummulative = $taxCummulative + ($orderItem["_price"] * $tax / (1 + $tax));
                        }
                    }
                    // tax amount
                    $orderItem["tax_amount"] = $taxCummulative;
                    $taxCummulative = 0;
                }
                if (isset($orderItem["_price"])) {
                    // net amount
                    $orderItem["price_final_minus_tax"] = $orderItem["_price"] - $orderItem["tax_amount"];
                }

            } else {
                if (isset($orderItem["tax_rate"])) {
                    // tax amount
                    $orderItem["tax_amount"] = $orderItem["tax_rate"] * $orderItem["_price"];
                }
                if (isset($orderItem["_price"])) {
                    // net amount
                    $orderItem["price_final_minus_tax"] = $orderItem["_price"];
                    // gross
                    if ($refunded) {
                        // do nothing;
                        $orderItem["price_final"] = $orderItem["price_final_minus_tax"] + $orderItem["tax_amount"] + $orderItem["serviceCharge_coeff_amount"];
                    } else {
                        $orderItem["price_final"] = $orderItem["price_final_minus_tax"] + $orderItem["tax_amount"] + $orderItem["serviceCharge_coeff_amount"];
                    }
                }
            }

            $orderItem["profit"] = $orderItem["price_final_minus_tax"] - $orderItem["cost"];

            if(!isset($orders_reset_total_profit[$order['order']])) {
                $order['total_profit'] = 0;
                $orders_reset_total_profit[$order['order']] = true;
            }
            $order['total_profit'] += $orderItem["profit"];
        }
    }

    /**
     * @param array $data
     * @param string|array $columnName
     * @param int $sortOrder
     */
    private function orderData(&$data, $columnName, $sortOrder) {
        $columnData = array();
        if(is_array($columnName)) {
            // convert to special date format
            foreach($data as $key => $row) {
                $columnData[$key] = date($columnName['format'], strtotime($row[$columnName['name']]));
            }
        } else {
            foreach($data as $key => $row) {
                $columnData[$key] = $row[$columnName];
            }
        }

        array_multisort($columnData, $sortOrder, $data);
    }

    /**
     * @param string $currentValue
     * @param string $dateType
     * @return null|string
     */
    private function nextDateValue($currentValue, $dateType) {
        switch($dateType) {
            case 'date':
            case 'date-iso':
                $dt = new DateTime($currentValue);
				$di = DateInterval::createFromDateString('+1 day');
				$dt->add($di);
				if($dateType == 'date') {
                    $nextValue = $dt->format($this->getDateFormat()); // 2000-01-01
                } else {
                    $nextValue = $dt->format(CloverMulti::DATE_FORMAT_ISO); // 2000-01-01
                }
                break;
            case 'hour':
                $currentValue = (int)substr($currentValue, 0, 2); // 0, 1, ..., 22, 23
                $nextValue = str_pad(($currentValue + 1) % 24, 2, '0', STR_PAD_LEFT); // 01, 02, ..., 23, 00
                $nextValue = CloverMulti::transformHourToAMPM($nextValue, $this->apiType);
                break;
            default:
                $nextValue = null;
			    break;
        }

        return $nextValue;
    }

    /**
     * @param string $table
     * @param array $fields
     * @param string|null $splitField
     * @param array|null $filters
     * @param bool|null $doGrouping
     * @param array|null $selectFieldsCorrected For each column add its type. Needed later for knowing whether to cast or not a column to number. Can't use $selectFields because
     * @param bool $addAggToAlias
     * @return string
     */
    private function createGroupCacheSql($table, $fields, $splitField = null, $filters = null, $doGrouping = true, &$selectFieldsCorrected = [], $addAggToAlias = true) {
        // 1st check if splitting is necessary.
        $splitDistinctValues = [];
        if(!empty($splitField)) {
            if($table == 'order_items') {
                $splitTables = ['order_items', 'orders'];
            } else {
                $splitTables = [$table];
            }

            $splitFieldExist = false;
            foreach($splitTables as $splitTable) {
                $sql = "PRAGMA table_info('$splitTable')";
                $stmt = $this->cache->query($sql);
                $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($results as $row) {
                    if ($row['name'] == $splitField) {
                        $splitFieldExist = true;
                        break 2;
                    }
                }
            }
            
            if($splitFieldExist && !empty($splitTable)) {
                $sql = "SELECT DISTINCT \"$splitField\" FROM $splitTable ORDER BY 1 ASC";
                $stmt = $this->cache->query($sql);
                $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
                // We no longer check for too many parts for split measure - it's moved to frontend.
                foreach($results as $row) {
                    $splitDistinctValues[] = str_replace("'", "''", $row[$splitField]);
                }
            }
        }

        if(!is_array($selectFieldsCorrected)) {
            $selectFieldsCorrected = [];
        }

        $selectClause = 'SELECT ';
        if($table == 'order_items') {
            $fromClause = 'FROM "orders" JOIN "order_items" ON "orders"."order" = "order_items"."order__id" AND "orders"."trx_type" = \'ORDER\'';
        } else {
            $fromClause = "FROM $table";
        }
        $whereClause = [];
	    $groupByClause = '';
        $orderByClause = '';

        // 2nd SELECT clause.
        foreach($fields as $field) {
            if($field->type == 'measure') {
                $alias = $field->tag . $field->alias . ($addAggToAlias ? '_' . $field->agg : '');
                $selectClause .= $field->agg . '("' . $field->name . '") AS "' . $alias . '",';

                $selectFieldsCorrected[$alias] = ['type' => 'measure'];

                foreach($splitDistinctValues as $splitDistinctValue) {
                    $alias = $field->tag . $splitDistinctValue . '_' . $field->alias . '_' . $field->agg;
                    $case = 'CASE WHEN "' . $splitField . '" = \'' . $splitDistinctValue . '\' THEN "' . $field->name . '" ELSE 0 END';
                    $selectClause .= $field->agg . '(' . $case . ') AS \'' . $alias . '\',';

                    $selectFieldsCorrected[$alias] = ['type' => 'measure'];
                }

            } else {
                $selectClause .= '"' . $field->name . '" AS "' . $field->alias . '",';
			    $groupByClause .= '"' . $field->name . '",';

                $selectFieldsCorrected[$field->alias] = ['type' => 'dimension'];
            }
        }
        $selectClause = rtrim($selectClause, ',');

        // 3rd WHERE clause.
        if(isset($filters)) {
            foreach ($filters as $alias => $values) {
                if (empty($values) || (is_object($values) && (empty($values->operator) || empty($values->values)))) {
                    continue;
                }

                if(is_array($values)) {
                    foreach ($values as &$value) {
                        // Clean filter values.
                        $value = SQLite3::escapeString($value);
                    }

                    $filter = '"' . $alias . '"';
                    if (count($values) == 1) {
                        $filter .= " = '" . $values[0] . "'";
                    } else {
                        $filter .= " IN ('" . implode("','", $values) . "')";
                    }
                } else {
                    foreach ($values->values as &$value) {
                        // Clean filter values.
                        $value = SQLite3::escapeString($value);
                    }

                    $filter = '"' . $alias . '"';
                    if($values->operator == 'RANGE' && (count($values->values) == 2)) {
                        $filter .= " BETWEEN '" . $values->values[0] . "' AND '" . $values->values[1] . "'";
                    } else {
                        if($values->operator == 'RANGE') {
                            $values->operator = '=';
                        }

                        $filter .= " " . $values->operator . " '" . $values->values[0] . "'";
                    }
                }

                $whereClause[] = $filter;
            }
        }
        $whereClause = !empty($whereClause) ? 'WHERE ' . implode(' AND ', $whereClause) : '';

        // 4th GROUP BY clause.
        if($doGrouping) {
            $groupByClause = !empty($groupByClause) ? 'GROUP BY ' . rtrim($groupByClause, ',') : '';
        } else {
            $groupByClause = 'GROUP BY ' . $table . '."_id"';
        }

        $query = "$selectClause $fromClause $whereClause $groupByClause $orderByClause";

	    return $query;
    }

    /**
     * Deletes all records from specified $tables. Optionally sets cache update timestamp to 0.
     *
     * @param array $tables
     * @param bool $clearCacheTimestamp
     * @param array|null $filterMerchantIds
     * @return bool
     */
    public function clearCache($tables = ['devices','items','employees', 'customers'], $clearCacheTimestamp = true, $filterMerchantIds = null) {
        if(!isset($filterMerchantIds)) {
            $filterMerchantIds = $this->merchantIds;
        }
        $filterMerchantIds = "'" . implode($filterMerchantIds, "','") . "'";

        $this->cache->beginTransaction();
        foreach($tables as $table) {
            if(isset(self::$cacheTableMerchantId[$table])) {
                $whereClause = 'WHERE ' . self::$cacheTableMerchantId[$table] . ' IN (' . $filterMerchantIds . ')';
            } else {
                $whereClause = '';
            }

            $query = "DELETE FROM $table $whereClause";
            $this->cache->exec($query);
        }

        if($clearCacheTimestamp) {
            $query = "UPDATE merchants SET cacheUpdatedTimestamp = 0 WHERE id IN ($filterMerchantIds)";
            $this->cache->exec($query);
        }

        $this->cache->commit();

        return true;
    }

    /**
     * If no date format is set, it tries to determine date format from cache date columns with regular expressions.
     * @return string
     */
    public function getDateFormat() {
        if(empty($this->dateFormat)) {
            $stmt = $this->cache->query("SELECT date FROM orders LIMIT 1");
            $results = $stmt->fetch(PDO::FETCH_ASSOC);
            if(empty($results)) {
                $stmt = $this->cache->query("SELECT date FROM payments LIMIT 1");
                $results = $stmt->fetch(PDO::FETCH_ASSOC);
            }
            
            if(!empty($results)) {
                foreach(self::$dateFormatExpressions as $dateFormat => $dateFormatExpression) {
                    if(preg_match($dateFormatExpression, $results['date'])) {
                        $this->dateFormat = $dateFormat;
                        break;
                    }
                }
            }

            if(empty($this->dateFormat)) {
                $this->dateFormat = CloverMulti::DATE_FORMAT_ISO;
            }
        }

        return $this->dateFormat;
    }

    /**
     * @param string $table
     * @param bool $returnTypes
     * @return array
     * @throws Exception
     */
    public function getTableColumns($table, $returnTypes = false) {
        $query = "PRAGMA table_info(\"{$table}\")";
        $stmt = $this->cache->query($query);
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if(!count($result)) {
            throw new Exception("Specified table '{$table}' does not exist.");
        }

        $columns = [];
        if($returnTypes) {
            foreach ($result as $row) {
                $columns[$row['name']] = $row['type'];
            }
        } else {
            foreach ($result as $row) {
                $columns[] = $row['name'];
            }
        }

        return $columns;
    }

}