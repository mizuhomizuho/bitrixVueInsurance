<?php

namespace Ms\General\Sale\Order;

use Bitrix\Catalog\StoreTable;
use Bitrix\Currency\CurrencyManager;
use Bitrix\Iblock\ElementTable;
use Bitrix\Iblock\Model\Section;
use Bitrix\Iblock\PropertyEnumerationTable;
use Bitrix\Iblock\PropertyTable;
use Bitrix\Main\Context;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Event;
use Bitrix\Sale\Payment as BxPayment;
use Bitrix\Sale\Cashbox\CheckManager;
use Bitrix\Sale\Delivery\Services\Table;
use Bitrix\Sale\Order;
use Bitrix\Sale\Shipment;
use Mi\ORM\ElementPropertyTable;
use Ms\General\Api\Models\Sale\BasketCollection;
use Ms\General\Api\Models\Sale\Payment;
use Ms\General\Api\Models\Sale\PaymentCollection;
use Ms\General\Api\Models\Sale\SmartOrder;
use Ms\General\Iblock\Sections;
use Ms\General\Sale;
use Ms\General\Sale\Order\Insurance\Tarifs;
use Ms\General\Site\Dev;
use Sale\Handlers\Delivery\ApiShipProfile;

class Insurance {

    private $order;

    private ?SmartOrder $smartOrder = null;

    private static array $insuranceSelected = [];
    private static array $insuranceSelectedTime = [];

    private const PID = 10221;
    private const PREFIX_ON_BASKET_ITEM = 'insuranceSave_';

    const KEY_ON_BASKET_ITEM_TARIF_ID = 'insuranceTarifId';

    private const NOMENCLATURE_IBLICK_ID = 38;

    function __construct(Order $order)
    {
        $this->order = $order;
    }

    function setSmartOrderModel(SmartOrder $smartOrder)
    {
        $this->smartOrder = $smartOrder;
    }

    function set(array $params): void
    {
        static::$insuranceSelected = $params['insuranceSelected'];
        static::$insuranceSelectedTime = $params['insuranceSelectedTime'];
    }

    function getOrderApiShipProviderCode(): false|string
    {
        $order = $this->order;

        $shipments = [];
        /** @var Shipment $shipment */
        foreach ($order->getShipmentCollection() as $shipment) {
            $shipments[] = $shipment;
        }

        $shipmentsCount = count($shipments);

        $getApiShipProviderCode = function(int $profileId): string {

            return Table::query()
                ->setCacheTtl(3600 * 24 * 88888888)
                ->setSelect(['CONFIG'])
                ->where('ID', '=', $profileId)
                ->fetch()['CONFIG']['MAIN']['DELIVERY_PROVIDER'];
        };

        $returnDeliveryProvider = null;

        if ($shipmentsCount === 1) {

            if (!($shipments[0]->getDelivery() instanceof ApiShipProfile)) {
                return false;
            }

            $returnDeliveryProvider = $getApiShipProviderCode(
                $shipments[0]->getDeliveryId()
            );
        }
        elseif ($shipmentsCount > 1) {

            $buDeliveryId = null;
            $buDeliveryProvider = null;

            foreach ($shipments as $shipmentsV) {

                if (!($shipmentsV->getDelivery() instanceof ApiShipProfile)) {
                    return false;
                }

                $curDeliveryId = $shipmentsV->getDeliveryId();

                $curDeliveryProvider = $getApiShipProviderCode($curDeliveryId);

                $returnDeliveryProvider = $curDeliveryProvider;

                if (
                    $buDeliveryId !== null
                    && (
                        $buDeliveryId !== $curDeliveryId
                        || $buDeliveryProvider !== $curDeliveryProvider
                    )
                ) {
                    return false;
                }

                $buDeliveryId = $curDeliveryId;
                $buDeliveryProvider = $curDeliveryProvider;
            }
        }

        if (
            is_string($returnDeliveryProvider)
            && $returnDeliveryProvider !== ''
        ) {
            return $returnDeliveryProvider;
        }

        return false;
    }

    function getBuild(): array
    {
        $return = [];

        $order = $this->order;
        $smartOrder = $this->smartOrder;

        $returnNoProducts = ['products' => []];

        if (!CurrentUser::get()->isAdmin()) {
            return $returnNoProducts;
        }

        /** @var BasketCollection $smartOrderBasket */
        $smartOrderBasket = $smartOrder->toArray()['order']['basket'];
        if (!$smartOrderBasket) {
            return $returnNoProducts;
        }

        $bIds = [];
        $allowPIds = $this->getProductsIds($smartOrderBasket);
        foreach ($order->getBasket()->getBasketItems() as $basketItem) {
            if (in_array($basketItem->getField('PRODUCT_ID'), $allowPIds)) {
                $bIds[] = $basketItem->getId();
            }
        }
        if (!$bIds) {
            return $returnNoProducts;
        }

        $return['products'] = $bIds;

        $tarifsObj = new Tarifs($order);

        $tarifsObj->refreshTarifId();

        $return['variants'] = $this->getVariants($bIds);

        $return['time'] = json_encode($tarifsObj::getTime());

        $return['selected'] = $this->get('insuranceSelected');
        $return['selectedTime'] = $this->get('insuranceSelectedTime');



        $cU = CurrentUser::get();

        $defSurname = (string) $cU->getLastName();
        $defName = (string) $cU->getFirstName();

        $defPhone = (string) $GLOBALS['USER']->GetParam('PERSONAL_PHONE');
        $defPhone = preg_replace('/\D/', '', $defPhone);

        $return['form'] = [
            'telephone' => $defPhone,
            'email' => (string) $cU->getEmail(),
            'surname' => $defSurname,
            'name' => $defName,
        ];

        $return['formAskPassportIfSum'] = (float) $tarifsObj->getBlockById(
            'ask-passport-if-sum',
            'value'
        );

        $return['fromParams'] = $this->getFromParams();

        return $return;
    }

    private static function getPId(): int
    {
        if (Dev::isDevRemote('orderInsurance')) {
            return 9556;
        }

        if (Dev::isDev('orderInsurance')) {
            return 9563;
        }

        return static::PID;
    }

    static function eventOnSaleStatusOrderChange(Event $event): void
    {
        if (!defined('MI_INSURANCE_NEED_PRINT_CHECK')) {
            return;
        }

        if (Dev::isDev('orderInsurance')) {
            return;
        }

        $newOrderStatus = $event->getParameter('VALUE');

        if ($newOrderStatus !== 'F') {
            return;
        }

        /** @var Order $order */
        $order = $event->getParameter('ENTITY');

        $_this = new static($order);

        if (!$_this->issetInsurance()) {
            return;
        }

        $apiShipProviderCode = $_this->getOrderApiShipProviderCode();

        if (
            !is_string($apiShipProviderCode)
            || $apiShipProviderCode === 'dalli'
        ) {
            return;
        }



        $orderPayment = null;

        if ($order->getPaymentCollection()->count() === 1) {

            foreach ($order->getPaymentCollection() as $orderPayment) {
                break;
            }
        }

        if (!($orderPayment instanceof BxPayment)) {
            return;
        }



        $orderShipment = null;

        if ($order->getShipmentCollection()->count() === 2) {

            $shipments = [];

            $buDeliveryId = null;

            /** @var Shipment $shipment */
            foreach ($order->getShipmentCollection() as $shipmentsV) {

                $curDeliveryId = $shipmentsV->getDeliveryId();

                if (
                    $buDeliveryId !== null
                    && $buDeliveryId !== $curDeliveryId
                ) {
                    break;
                }

                $shipments[] = $shipmentsV;

                $buDeliveryId = $curDeliveryId;
            }

            if (count($shipments) === 2) {

                if ($shipments[0]->getPrice() === 0.0 && $shipments[1]->getPrice() > 0) {

                    $orderShipment = $shipments[1];
                }
                elseif ($shipments[1]->getPrice() === 0.0 && $shipments[0]->getPrice() > 0) {

                    $orderShipment = $shipments[0];
                }
                elseif ($shipments[0]->getPrice() === 0.0 && $shipments[1]->getPrice() === 0.0) {

                    if ($shipments[0]->getId() > $shipments[1]->getId()) {

                        $orderShipment = $shipments[0];
                    }
                    else {

                        $orderShipment = $shipments[1];
                    }
                }
            }
        }

        if (!($orderShipment instanceof Shipment)) {
            return;
        }

        CheckManager::addByType(
            [$orderPayment],
            'sell',
            ['' => [$orderShipment]]
        );
    }

    function createOrder(): false|array
    {
        if (!$this->issetInsurance()) {

            return false;
        }

        $order = $this->order;
        $tarifsObj = new Tarifs($order);
        $startSettings = $this->getSettings();
        $selectedTime = $this->get('insuranceSelectedTime');
        $timeSettings = $tarifsObj::getTime();

        $storNew = [];

        foreach ($this->get('insuranceSelected') as $bId => $val) {

            if ((string) $val['insurance_0'] === 'true') {

                continue;
            }

            foreach ($val as $id => $val) {

                $quantity = null;
                $for = null;

                foreach ($order->getBasket()->getBasketItems() as $basketItem) {

                    if ($bId === $basketItem->getId()) {

                        $quantity = $basketItem->getQuantity();
                        $for = $basketItem->getField('NAME');

                        break;
                    }
                }

                $title = $startSettings[$id]['titleBase'];

                if ($startSettings[$id]['time']) {

                    $timeCode = $selectedTime[$bId][$id];

                    foreach ($timeSettings as $timeSettingsV) {

                        if ($timeSettingsV['code'] === $timeCode) {

                            $title .= sprintf(' (%s)', trim($timeSettingsV['title']));

                            break;
                        }
                    }
                }

                $title .= sprintf(' для %s', $for);

                $itemFields = [
                    'NAME' => $title,
                    'QUANTITY' => $quantity,
                    'CURRENCY' => CurrencyManager::getBaseCurrency(),
                    'LID' => Context::getCurrent()->getSite(),
                    'PRICE' => $tarifsObj->getPrice($bId, $id) / $quantity,
                    'CUSTOM_PRICE' => 'Y',
                ];

                $newItem = $order->getBasket()->createItem('catalog', static::getPId());

                $newItem->setFields($itemFields);

                $newItem->save();

                $storNew['insBIds'][$bId][$id] = $newItem->getId();
            }
        }

        return $storNew;
    }

    private function getStorFromBasketItems(): array
    {
        $order = $this->order;

        $storCur = [];

        foreach ($order->getBasket()->getBasketItems() as $basketItem) {

            $bId = (int) $basketItem->getId();

            $propertyCollection = $basketItem->getPropertyCollection();
            $propertyCollectionVals = $propertyCollection->getPropertyValues();

            foreach ($propertyCollectionVals as $propertyCollectionValsV) {

                $build = function (

                    string $codeSuffix,
                    string $storBase,

                ) use (

                    &$storCur,
                    $propertyCollectionValsV,
                    $bId,

                ): void {

                    if ($propertyCollectionValsV['CODE'] === static::PREFIX_ON_BASKET_ITEM . $codeSuffix) {

                        $storDecode = (array) json_decode(
                            (string) $propertyCollectionValsV['VALUE'],
                            true
                        );

                        if ($storDecode) {

                            $storCur[$storBase][(string) $bId] = $storDecode;
                        }
                    }
                };

                $build('selected', 'insuranceSelected');
                $build('selectedTime', 'insuranceSelectedTime');
            }
        }

        return $storCur;
    }

    function clear(): void
    {
        $order = $this->order;

        foreach ($order->getBasket()->getBasketItems() as $basketItem) {

            $propertyCollection = $basketItem->getPropertyCollection();
            $propertyCollectionVals = $propertyCollection->getPropertyValues();

            $isNeedSave = false;

            foreach ($propertyCollectionVals as $propertyCollectionValsK => $propertyCollectionValsV) {

                if (in_array($propertyCollectionValsV['CODE'], [
                    static::KEY_ON_BASKET_ITEM_TARIF_ID,
                    static::PREFIX_ON_BASKET_ITEM . 'selected',
                    static::PREFIX_ON_BASKET_ITEM . 'selectedTime',
                ])) {
                    $isNeedSave = true;
                    unset($propertyCollectionVals[$propertyCollectionValsK]);
                }
            }

            if ($isNeedSave) {
                $propertyCollection->redefine($propertyCollectionVals);
                $propertyCollection->save();
            }
        }
    }

    function saveStor(array $stor): void
    {
        $order = $this->order;

        if (is_array($stor['form'])) {

            foreach ($stor['form'] as $formK => $formV) {

                $stor['form'][$formK] = htmlspecialcharsEx($formV);
            }
        }

        $stor['stor'] = $this->getStorFromBasketItems();

        $stor['nomenclature'] = [];

        $bIds = [];
        foreach ($order->getBasket()->getBasketItems() as $basketItem) {
            $bIds[] = $basketItem->getId();
        }

        if ($bIds) {

            $getVariantsRes = $this->getVariants($bIds);

            foreach ($getVariantsRes as $bId => $getVariantsResV) {

                foreach ($getVariantsResV as $getVariantsResVV) {

                    if (
                        is_string($getVariantsResVV['nomenclatureId'])
                        && $getVariantsResVV['nomenclatureId'] !== ''
                        && isset($stor['stor']['insuranceSelected'][$bId][$getVariantsResVV['id']])
                    ) {
                        $stor['nomenclature'][$bId][$getVariantsResVV['id']] = $getVariantsResVV['nomenclatureId'];
                    }
                }
            }
        }

        $this->clear();

        Data::getInstance($order)->set('insuranceStor', $stor)->save();
    }

    function get(?string $base = null): array
    {
        $order = $this->order;

        if ($order->getId()) {

            $stor = Data::getInstance($order)->get('insuranceStor');

            if (is_array($stor)) {

                if ($base === null) {

                    return (array) $stor['stor'];
                }

                return (array) $stor['stor'][$base];
            }
        }

        $storCur = $this->getStorFromBasketItems();

        if ($base === null) {

            return $storCur;
        }

        return (array) $storCur[$base];
    }

    function issetInsurance(): bool {

        foreach ($this->get('insuranceSelected') as $val) {

            if ((string) $val['insurance_0'] !== 'true') {

                return true;
            }
        }

        return false;
    }

    function filterPaymentVariants(

        PaymentCollection $paymentVariants,
        Sale\SmartOrder $smartOrder,

    ): PaymentCollection {

        if ($smartOrder->getDeliveryId() === RETAIL_PICKUP_DELIVERY_ID) {

            return $paymentVariants;
        }

        if (!$this->issetInsurance()) {

            return $paymentVariants;
        }

        $return = [];

        $payMethods = $smartOrder->getAllowPayments();

        /** @var Payment $payment */
        foreach ($paymentVariants as $payment) {

            foreach($payMethods as $pay){

                if ((string) $payment->toArray()['id'] === $pay['ID']) {

                    $return[] = $pay;

                    break;
                }
            }
        }

        foreach ($return as $returnK => $returnV) {

            if ($returnV['ACTION_FILE'] === 'cash') {

                unset($return[$returnK]);
            }
        }

        return PaymentCollection::createByData($return);
    }

    private function getProductsIds(BasketCollection $smartOrderBasket): array
    {
        $return = [];

        $insuranceSections = Section::compileEntityByIblock(MAIN_CATALOG_IBLOCK_ID)::getList([
            'cache' => [
                'ttl' => 3600 * 24 * 8,
                'cache_joins' => true,
            ],
            'select' => ['ID'],
            'filter' => [
                'IBLOCK_ID' => MAIN_CATALOG_IBLOCK_ID,
                'ACTIVE' => 'Y',
                'GLOBAL_ACTIVE' => 'Y',
                'UF_INSURANCE' => '1',
            ],
        ]);

        $insuranceSectionsIds = [];
        while($insuranceSection = $insuranceSections->fetch()) {
            $insuranceSectionsIds[] = $insuranceSection['ID'];
        }

        $sections = Sections::getInstance(MAIN_CATALOG_IBLOCK_ID)->get();

        foreach ($smartOrderBasket->toArray() as $smartOrderBasketV) {

            $smartOrderBasketV = $smartOrderBasketV->toArray();

            if (
                !isset($smartOrderBasketV['categoryId'])
                || !is_numeric($smartOrderBasketV['categoryId'])
            ) {
                continue;
            }

            $sectionPath = [];
            if (isset($sections['path'][$smartOrderBasketV['categoryId']])) {
                $sectionPath = $sections['path'][$smartOrderBasketV['categoryId']];
            }
            $sectionPath[] = $smartOrderBasketV['categoryId'];

            foreach ($sectionPath as $sectionPathV) {
                if (in_array($sectionPathV, $insuranceSectionsIds)) {
                    $return[$smartOrderBasketV['productId']] = true;
                    break;
                }
            }
        }

        $return = array_keys($return);

        return $return;
    }

    private function applySave(): void
    {
        $order = $this->order;

        if (!(
            !$order->getId()
            && static::$insuranceSelected === []
            && static::$insuranceSelectedTime === []
        )) {
            return;
        }

        $insuranceSelectedSaved = [];
        $insuranceSelectedTimeSaved = [];

        $selectedDecode = $this->get();

        foreach ($order->getBasket()->getBasketItems() as $basketItem) {

            $pId = (string) $basketItem->getId();

            if (isset($selectedDecode['insuranceSelected'][$pId])) {

                $insuranceSelectedSaved[$pId]
                    = $selectedDecode['insuranceSelected'][$pId];
            }

            if (isset($selectedDecode['insuranceSelectedTime'][$pId])) {

                $insuranceSelectedTimeSaved[$pId]
                    = $selectedDecode['insuranceSelectedTime'][$pId];
            }
        }

        $this->set([
            'insuranceSelected' => $insuranceSelectedSaved,
            'insuranceSelectedTime' => $insuranceSelectedTimeSaved,
        ]);
    }

    function save(): void
    {
        $order = $this->order;

        $this->applySave();

        $selected = static::$insuranceSelected;
        $selectedTime = static::$insuranceSelectedTime;

        $variantsList = null;

        if ($selected) {

            $pIds = [];
            foreach ($selected as $pId => $val) {
                $pIds[] = $pId;
            }

            $variantsList = $this->getVariants($pIds);
        }

        $codeTxtPropSelected = 'insuranceTxtPropSelected';
        $codeTxtPropSelectedTime = 'insuranceTxtPropSelectedTime';

        foreach ($order->getBasket()->getBasketItems() as $basketItem) {

            $pId = (int) $basketItem->getId();

            $propertyCollection = $basketItem->getPropertyCollection();
            $propertyCollectionVals = $propertyCollection->getPropertyValues();

            $isNeedSave = false;



            foreach ($propertyCollectionVals as $propertyCollectionValsK => $propertyCollectionValsV) {

                if (in_array($propertyCollectionValsV['CODE'], [
                    $codeTxtPropSelected,
                    $codeTxtPropSelectedTime,
                    static::PREFIX_ON_BASKET_ITEM . 'selected',
                    static::PREFIX_ON_BASKET_ITEM . 'selectedTime',
                ])) {
                    unset($propertyCollectionVals[$propertyCollectionValsK]);
                    $isNeedSave = true;
                }
            }



            if ($selected) {

                $forItemSelected = [];
                foreach ($selected as $bId => $val) {
                    if ((int) $bId === $pId) {
                        $forItemSelected = $val;
                        break;
                    }
                }

                $forItemSelectedTime = [];
                foreach ($selectedTime as $bId => $val) {
                    if ((int) $bId === $pId) {
                        $forItemSelectedTime = $val;
                        break;
                    }
                }

                $propertyCollectionVals[] = [
                    'NAME' => static::PREFIX_ON_BASKET_ITEM . 'selected',
                    'CODE' => static::PREFIX_ON_BASKET_ITEM . 'selected',
                    'VALUE' => json_encode($forItemSelected),
                ];

                $propertyCollectionVals[] = [
                    'NAME' => static::PREFIX_ON_BASKET_ITEM . 'selectedTime',
                    'CODE' => static::PREFIX_ON_BASKET_ITEM . 'selectedTime',
                    'VALUE' => json_encode($forItemSelectedTime),
                ];

                $isNeedSave = true;
            }



            if (
                $selected
                && (string) $selected[$pId]['insurance_0'] !== 'true'
            ) {

                $insuranceVariants = $variantsList[$pId];

                $insuranceSelectedRes = [];

                foreach ($insuranceVariants as $insuranceVariantsV) {
                    foreach ($selected as $selectedPId => $val) {
                        foreach ($val as $id => $val) {
                            if (
                                $id === $insuranceVariantsV['id']
                                && $pId === $selectedPId
                            ) {
                                $insuranceSelectedRes[] = $insuranceVariantsV['titleBase'];
                            }
                        }
                    }
                }

                if ($insuranceSelectedRes) {

                    $propertyCollectionVals[] = [
                        'NAME' => 'Страховкa',
                        'CODE' => $codeTxtPropSelected,
                        'VALUE' => implode(' + ', $insuranceSelectedRes),
                    ];

                    $isNeedSave = true;
                }
            }



            if (
                $selectedTime
                && !isset($selectedTime[$pId]['insurance_0'])
            ) {

                $insuranceSelectedTimeRes = '';

                foreach ($selectedTime as $selectedTimePId => $val) {
                    foreach ($val as $timeCode) {

                        if ($selectedTimePId === $pId) {

                            $timeDecode = Tarifs::getTime();

                            foreach ($timeDecode as $timeV) {
                                if ($timeV['code'] === $timeCode) {
                                    $insuranceSelectedTimeRes = trim($timeV['title']);
                                    break;
                                }
                            }

                            break 2;
                        }
                    }
                }

                if ($insuranceSelectedTimeRes) {

                    $propertyCollectionVals[] = [
                        'NAME' => 'Срок страховки',
                        'CODE' => $codeTxtPropSelectedTime,
                        'VALUE' => $insuranceSelectedTimeRes,
                    ];

                    $isNeedSave = true;
                }
            }



            if ($isNeedSave) {

                $propertyCollection->redefine($propertyCollectionVals);
                $propertyCollection->save();
            }
        }
    }

    private function getFromParams(): array
    {
        $datePlaceholder = 'дд.мм.гггг';
        $d6Placeholder = '000000';

        return [

            [
                'id' => 'base',
                'blocks' => [
                    [
                        'surname' => ['title' => 'Фамилия'],
                        'name' => ['title' => 'Имя'],
                        'otchestvo' => ['title' => 'Отчество'],
                        'dateOfBirth' => [
                            'title' => 'Дата рождения',
                            'placeholder' => $datePlaceholder,
                            'maskType' => 'date',
                        ],
                        'telephone' => [
                            'title' => 'Телефон',
                            'placeholder' => '+7 (___) ___-__-__',
                            'maskType' => 'phone',
                        ],
                        'email' => [
                            'title' => 'Email',
                            'placeholder' => 'my@mail.ru',
                            'checkType' => 'email',
                        ],
                    ],
                ],
            ],

            [
                'id' => 'passport',
                'title' => 'Паспорт',
                'blocks' => [
                    [
                        'series' => [
                            'title' => 'Серия',
                            'placeholder' => '00 00',
                            'maskType' => 'ddsdd',
                        ],
                        'number' => [
                            'title' => 'Номер',
                            'placeholder' => $d6Placeholder,
                            'maskType' => 'd6',
                        ],
                        'dateOfIssue' => [
                            'title' => 'Дата выдачи',
                            'placeholder' => $datePlaceholder,
                            'maskType' => 'date',
                        ],
                    ],
                    [
                        'issued' => ['title' => 'Выдан'],
                        'placeOfBirth' => ['title' => 'Место рождения'],
                        'citizenship' => ['title' => 'Гражданство'],
                        'index' => [
                            'title' => 'Индекс',
                            'placeholder' => $d6Placeholder,
                            'maskType' => 'd6',
                        ],
                    ],
                    [
                        'registeredAt' => ['title' => 'Зарегистрирован по адресу'],
                    ],
                ],
            ],
        ];
    }

    private function getSettings(): array
    {
        return static::getSettingsBase();
    }

    static function getSettingsBase(): array
    {
        $titleBaseFromTime12Mes = '=)';
        $titleBaseFromTimeCombo = '=)';

        foreach (Tarifs::get()['tarifs']['blocks'] as $blockV) {

            if ($blockV['dataId'] === 'srok-strahovki') {

                foreach ($blockV['data'] as $dataK => $dataV) {

                    if (!$dataK) {

                        $colsCodes = $dataV;
                        continue;
                    }

                    $timesItem = [];

                    foreach ($dataV as $dataVK => $dataVV) {

                        $timesItem[$colsCodes[$dataVK]] = $dataVV;
                    }

                    $titleBaseFromTime12Mes = $timesItem['btnTitle__insurance_12mes'];
                    $titleBaseFromTimeCombo = $timesItem['btnTitle__insurance_combo'];

                    break 2;
                }
            }
        }

        return [

            'insurance_0' => [

                'titleBase' => 'Нет',
                'price' => 0,
                'isActive' => true,
            ],

            'insurance_12mes' => [

                'combo' => 'comboSet',
                'titleBase' => $titleBaseFromTime12Mes,
                'isHit' => true,
                'time' => true,
            ],

            'insurance_zp' => [

                'combo' => 'comboSet',
                'titleBase' => 'Защита покупки',
            ],

            'insurance_ekran' => [

                'combo' => 'comboSet',
                'titleBase' => 'Защита экрана',
            ],

            'insurance_combo' => [

                'combo' => 'comboSet',
                'isComboSetParent' => true,
                'titleBase' => $titleBaseFromTimeCombo,
                'time' => true,
            ],
        ];
    }

    private function getVariants(array $pIds): array
    {
        $return = [];

        $order = $this->order;

        $tarifsObj = new Tarifs($order);

        $startSettings = $this->getSettings();

        $nomenclatureList = static::getNomenclature();

        foreach ($startSettings as $startSettingsK => $startSettingsV) {

            $returnItem = $startSettingsV;

            $returnItem['id'] = $startSettingsK;

            foreach ($pIds as $pIdsV) {

                $returnItem['price'] = $tarifsObj->getPrice($pIdsV, $startSettingsK);



                $curNomenclature = null;

                foreach ($nomenclatureList as $nomenclatureListV) {

                    if (
                        $this->isEcosystem($pIdsV) === $nomenclatureListV['isEcosystem']
                        && $this->isMixtech() === $nomenclatureListV['isOnlyPickupMixtech']
                        && $nomenclatureListV['props']['insuranceId'][0]['VALUE'] === $startSettingsK
                    ) {
                        $curNomenclature = $nomenclatureListV;
                        break;
                    }
                }

                if ($curNomenclature !== null) {

                    $returnItem['nomenclatureId'] = $curNomenclature['id'];
                }



                $filterRes = $tarifsObj->filterStartSettings($pIdsV, $returnItem);

                if ($filterRes !== false) {

                    $filterRes = $this->filterStartSettings($filterRes);
                }

                if ($filterRes !== false) {

                    $return[$pIdsV][] = $filterRes;
                }
            }
        }

        foreach ($return as $returnK => $returnV) {

            if ($returnV[0]['id'] === 'insurance_0' && count($returnV) === 1) {

                unset($return[$returnK]);
            }
        }

        return $return;
    }

    private static function getNomenclature(): array
    {
        $productIds = [];

        $nomenclatureIblick = ElementTable::getList([
            'cache' => [
                'ttl' => 3600 * 24 * 88888888,
                'cache_joins' => true,
            ],
            'select' => ['ID'],
            'filter' => [
                'IBLOCK_ID' => static::NOMENCLATURE_IBLICK_ID,
                'ACTIVE' => 'Y',
            ],
        ]);

        while ($nomenclatureIblickItem = $nomenclatureIblick->fetch()) {

            $productIds[] = $nomenclatureIblickItem['ID'];
        }

        if (!$productIds) {

            return [];
        }

        $queryProps = ElementTable::query()
            ->setCacheTtl(3600 * 24 * 8)
            ->cacheJoins(true)
            ->setSelect([
                'ELEMENT_ID' => 'ID',
                'PROP_CODE' => 'prop.CODE',
                'PROP_ID' => 'prop.ID',
                'VAL_ID' => 'propVal.ID',
                'VALUE' => 'propVal.VALUE',
                'ENUM_VALUE' => 'propValEnum.XML_ID',
            ])
            ->registerRuntimeField(
                'prop',
                [
                    'data_type' => PropertyTable::class,
                    'reference' => [
                        '=ref.IBLOCK_ID' => [static::NOMENCLATURE_IBLICK_ID],
                    ],
                ]
            )
            ->registerRuntimeField(
                'propVal',
                [
                    'data_type' => ElementPropertyTable::class,
                    'reference' => [
                        '=ref.IBLOCK_PROPERTY_ID' => 'this.prop.ID',
                        '=ref.IBLOCK_ELEMENT_ID' => 'this.ID',
                    ],
                ]
            )
            ->registerRuntimeField(
                'propValEnum',
                [
                    'data_type' => PropertyEnumerationTable::class,
                    'reference' => [
                        '=ref.PROPERTY_ID' => 'this.prop.ID',
                        '=ref.ID' => 'this.propVal.VALUE',
                    ],
                ]
            )
            ->setFilter([
                '=ID' => $productIds,
            ]);

        $return = [];
        foreach ($productIds as $productIdsV) {
            $returnItem = [];
            foreach ($queryProps->fetchAll() as $queryPropsV) {
                if ($queryPropsV['ELEMENT_ID'] === $productIdsV) {
                    $returnItem['id'] = $productIdsV;
                    $returnItem['isOnlyPickupMixtech'] = $queryPropsV['ENUM_VALUE'] === 'Y';
                    $returnItem['isEcosystem'] = $queryPropsV['ENUM_VALUE'] === 'Y';
                    $returnItem['props'][$queryPropsV['PROP_CODE']][] = $queryPropsV;
                }
            }
            $return[$productIdsV] = $returnItem;
        }

        return $return;
    }

    private function isMixtech(): bool
    {
        $smartOrder = $this->smartOrder;

        $deliveryId = $smartOrder->toArray()['order']['deliveryId'];
        $pickupPointId = $smartOrder->toArray()['order']['pickupPointId'];

        if (!(
            RETAIL_PICKUP_DELIVERY_ID === $deliveryId
            && $pickupPointId !== null
        )) {
            return false;
        }

        $mixtechStoresList = StoreTable::getList([
            'cache' => [
                'ttl' => 3600 * 24 * 88888888,
                'cache_joins' => true,
            ],
            'select' => ['ID'],
            'filter' => [
                'ACTIVE' => 'Y',
                'UF_IS_MIXTECH' => '1',
            ],
        ]);

        $mixtechStoreIds = [];
        while ($mixtechStore = $mixtechStoresList->fetch()) {
            $mixtechStoreIds[] = $mixtechStore['ID'];
        }

        return in_array($pickupPointId, $mixtechStoreIds);
    }

    private function isEcosystem(int $bId): bool
    {
        $smartOrder = $this->smartOrder;

        if ($smartOrder === null) {

            return false;
        }

        /** @var BasketCollection $smartOrderBasket */
        $smartOrderBasket = $smartOrder->toArray()['order']['basket'];

        $sections = Sections::getInstance(MAIN_CATALOG_IBLOCK_ID)->get();

        $noEcosystemSections = Section::compileEntityByIblock(MAIN_CATALOG_IBLOCK_ID)::getList([
            'cache' => [
                'ttl' => 3600 * 24 * 8,
                'cache_joins' => true,
            ],
            'select' => ['ID'],
            'filter' => [
                'IBLOCK_ID' => MAIN_CATALOG_IBLOCK_ID,
                'ACTIVE' => 'Y',
                'GLOBAL_ACTIVE' => 'Y',
                'UF_IS_NO_ECOSYSTEM' => '1',
            ],
        ]);

        $noEcosystemSectionIds = [];
        while ($noEcosystemSection = $noEcosystemSections->fetch()) {
            $noEcosystemSectionIds[] = $noEcosystemSection['ID'];
        }

        foreach ($smartOrderBasket->toArray() as $smartOrderBasketV) {

            $smartOrderBasketV = $smartOrderBasketV->toArray();

            if ($smartOrderBasketV['basketItemId'] !== $bId) {
                continue;
            }

            if (
                !isset($smartOrderBasketV['categoryId'])
                || !is_numeric($smartOrderBasketV['categoryId'])
            ) {
                return false;
            }

            $sectionPath = [];
            if (isset($sections['path'][$smartOrderBasketV['categoryId']])) {
                $sectionPath = $sections['path'][$smartOrderBasketV['categoryId']];
            }
            $sectionPath[] = $smartOrderBasketV['categoryId'];

            foreach ($sectionPath as $sectionPathV) {
                if (in_array($sectionPathV, $noEcosystemSectionIds)) {
                    return false;
                }
            }

            return true;
        }

        return false;
    }

    private function filterStartSettings(array $item): false|array
    {
        if ($item['id'] === 'insurance_0') {

            return $item;
        }

        if (
            !is_string($item['nomenclatureId'])
            || $item['nomenclatureId'] === ''
        ) {
            return false;
        }

        $nomenclatureList = static::getNomenclature();

        if (!isset($nomenclatureList[$item['nomenclatureId']])) {

            return false;
        }

        $curNomenclature = $nomenclatureList[$item['nomenclatureId']];

        if (
            is_string($curNomenclature['props']['desc'][0]['VALUE'])
            && $curNomenclature['props']['desc'][0]['VALUE'] !== ''
        ) {
            $item[$item['time'] ? 'timeDesc' : 'titleDesc'] = $curNomenclature['props']['desc'][0]['VALUE'];
        }

        return $item;
    }
}