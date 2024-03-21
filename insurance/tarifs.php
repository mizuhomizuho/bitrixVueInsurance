<?php

namespace Ms\General\Sale\Order\Insurance;

use Bitrix\Main\Application;
use Bitrix\Sale\Order;
use Ms\General\Sale\Order\Insurance;

class Tarifs {

    private Order $order;

    function __construct(Order $order)
    {
        $this->order = $order;
    }

    static function get(): array {

        $cache = Application::getInstance()->getManagedCache();

        $cacheId = __CLASS__ . '::' . __FUNCTION__;

        if ($cache->read(3600 * 24 * 8, $cacheId)) {

            $res = $cache->get($cacheId);

            if (is_array($res)) {

                return $res;
            }
        }

        $return = [];
        $return['tarifs'] = static::getTarifs();

        $cache->set($cacheId, $return);

        return $return;
    }

    private function getEkranPrice(int $prodId): float {

        $order = $this->order;

        foreach ($order->getBasket()->getBasketItems() as $basketItem) {

            if ((string) $basketItem->getId() !== (string) $prodId) {

                continue;
            }

            $otStoimostiUstrojstva = (float) $this->getBlockById(
                'grs-zashchita-ekrana__ot-stoimosti-ustrojstva',
                'value'
            );

            $minPrice = (float) $this->getBlockById(
                'grs-zashchita-ekrana__min',
                'value'
            );

            $price = $basketItem->getPrice() / 100 * $otStoimostiUstrojstva;

            if ($price < $minPrice) {

                $price = $minPrice;
            }

            return $price * $basketItem->getQuantity();
        }
    }

    function refreshTarifId(): void
    {
        $order = $this->order;

        foreach ($order->getBasket()->getBasketItems() as $basketItem) {

            $propertyCollection = $basketItem->getPropertyCollection();
            $propertyCollectionVals = $propertyCollection->getPropertyValues();

            $productPrice = $basketItem->getPrice();

            $priceFromTo = $this->getBlockById('stoimost-ustrojstva-ot-do');

            $tarifId = null;

            $priceFromToCount = count($priceFromTo);
            foreach ($priceFromTo as $priceFromToK => $priceFromToV) {

                $from = (float) $priceFromToV['from'];
                $to = (float) $priceFromToV['to'];

                if (
                    $from <= $productPrice
                    && $productPrice <= $to
                ) {
                    $tarifId = (int) $priceFromToK;
                    break;
                }
            }

            if ($tarifId === null) {

                if ($productPrice < $priceFromTo[0]['from']) {

                    $tarifId = 0;
                }
                else {

                    $tarifId = $priceFromToCount - 1;
                }
            }

            $base = Insurance::KEY_ON_BASKET_ITEM_TARIF_ID;

            foreach ($propertyCollectionVals as $propertyCollectionValsK => $propertyCollectionValsV) {

                if ($propertyCollectionValsV['CODE'] === $base) {

                    unset($propertyCollectionVals[$propertyCollectionValsK]);

                    break;
                }
            }

            $propertyCollectionVals[] = [
                'NAME' => $base,
                'CODE' => $base,
                'VALUE' => $tarifId,
            ];

            $propertyCollection->redefine($propertyCollectionVals);

            $propertyCollection->save();
        }
    }

    private function getTarifId(

        string $prodId,

    ): int {

        $order = $this->order;

        foreach ($order->getBasket()->getBasketItems() as $basketItem) {

            if ($prodId !== (string) $basketItem->getId()) {

                continue;
            }

            $propertyCollection = $basketItem->getPropertyCollection();
            $propertyCollectionVals = $propertyCollection->getPropertyValues();

            foreach ($propertyCollectionVals as $propertyCollectionValsV) {

                if ($propertyCollectionValsV['CODE'] === Insurance::KEY_ON_BASKET_ITEM_TARIF_ID) {

                    return (int) $propertyCollectionValsV['VALUE'];
                }
            }

            $this->refreshTarifId();

            return call_user_func_array([$this, __FUNCTION__], func_get_args());
        }
    }

     function getPrice(

        int $bId,
        string $id,

    ): float {

         if ($id === 'insurance_ekran') {

             return $this->getEkranPrice($bId);
         }

         $dataMap = [

             'insurance_12mes' => [

                 'tarifsBlockId' => 'grs-prodlennaya-garantiya',
             ],

             'insurance_zp' => [

                 'tarifsBlockId' => 'grs-zashchita-pokupki',
                 'tarifsBlockDataType' => 'column',
             ],

             'insurance_combo' => [

                 'tarifsBlockId' => 'grs-kombo',
             ],
         ];

         if (isset($dataMap[$id]['tarifsBlockId'])) {

             return $this->getVal(
                 $dataMap[$id]['tarifsBlockId'],
                 $bId,
                 $id,
                 $dataMap[$id]['tarifsBlockDataType'],
             );
         }

         return 0;
    }

    private function getVal(

        string $blockId,
        int $prodId,
        string $insuranceId,
        ?string $blockDataType = null,

    ): float {

        $order = $this->order;

        $insuranceSaveJsonDecode = (new Insurance($order))->get();

        foreach ($order->getBasket()->getBasketItems() as $basketItem) {

            $tarifId = $this->getTarifId((string) $basketItem->getId());

//            $propertyCollection = $basketItem->getPropertyCollection();
//            $propertyCollectionVals = $propertyCollection->getPropertyValues();
//
//            $insuranceSaveJsonDecode = null;
//
//            foreach ($propertyCollectionVals as $propertyCollectionValsV) {
//
//                if ($propertyCollectionValsV['CODE'] === Insurance::KEY_ON_BASKET_ITEM_STOR) {
//
//                    $insuranceSaveJsonDecode = (array) json_decode(
//                        (string) $propertyCollectionValsV['VALUE'],
//                        true
//                    );
//
//                    break;
//                }
//            }

            if ((string) $basketItem->getId() === (string) $prodId) {

                $getReturn = function (string $val) use ($basketItem): float {

                    $val = (float) $val;

                    return $val * $basketItem->getQuantity();
                };

                $val = $this->getBlockById($blockId, $blockDataType)[$tarifId];

                if (is_array($val)) {

                    if (isset(

                        $insuranceSaveJsonDecode
                            ['insuranceSelectedTime']
                            [$prodId]
                            [$insuranceId]
                    )) {

                        $selectedTimeCode = $insuranceSaveJsonDecode
                            ['insuranceSelectedTime']
                            [$prodId]
                            [$insuranceId];

                        return $getReturn($val[$selectedTimeCode]);
                    }

                    foreach ($val as $defVal) {
                        return $getReturn($defVal);
                    }
                }

                return $getReturn($val);
            }
        }
    }

    function getBlockById(string $blockId, ?string $dataType = null): array|string {

        $res = [];

        $tarifs = $this->get();

        foreach ($tarifs['tarifs']['blocks'] as $blockV) {

            if ($blockV['dataId'] === $blockId) {

                if ($dataType === 'column') {

                    return $blockV['data'];
                }

                $colsCodes = [];

                foreach ($blockV['data'] as $dataK => $dataV) {

                    if ($dataType === 'value') {

                        return $dataV[0];
                    }

                    if (!$dataK) {

                        $colsCodes = $dataV;
                        continue;
                    }

                    $resItem = [];

                    foreach ($dataV as $dataVK => $dataVV) {

                        $resItem[$colsCodes[$dataVK]] = $dataVV;
                    }

                    $res[] = $resItem;
                }

                break;
            }
        }

        return $res;
    }

    static function getTime(): array
    {
        $tarifs = static::get();

        $times = [];

        foreach ($tarifs['tarifs']['blocks'] as $blockV) {

            if ($blockV['dataId'] === 'srok-strahovki') {

                $colsCodes = [];

                foreach ($blockV['data'] as $dataK => $dataV) {

                    if (!$dataK) {

                        $colsCodes = $dataV;
                        continue;
                    }

                    $timesItem = [];

                    foreach ($dataV as $dataVK => $dataVV) {

                        $timesItem[$colsCodes[$dataVK]] = $dataVV;
                    }

                    $times[] = $timesItem;
                }

                break;
            }
        }

        return $times;
    }

    private static function getTarifs(): array {

        $blocks = [];

        $base = [];
        $colsAlfToNum = [];
        $colsNumToAlf = [];
        $colsCount = 0;

        $rowNum = 0;

        $arrRes = [];
        if (($handle = fopen(__DIR__ . '/tarifs.csv.php', 'r')) !== false) {
            while (($data = fgetcsv($handle)) !== false) {
                $num = count($data);
                $arrResItem = [];
                for ($c=0; $c < $num; $c++) {
                    $arrResItem['col' . $c] = $data[$c];
                }
                $arrRes[] = $arrResItem;
            }
            fclose($handle);
        }

        foreach ($arrRes as $row) {

            if (!$colsCount) {
                $colsCount = count($row);
            }

            $base[] = $row;

            $colNum = 0;

            foreach ($row as $rowK => $rowV) {

                $colsAlfToNum[$rowK] = $colNum;
                $colsNumToAlf[$colNum] = $rowK;

                preg_match('/( |^)\[%(?<dataId>[a-z_-]+)%\]( |$)/i', $rowV, $match);

                if ($match['dataId']) {

                    $title = trim(str_replace('[%' . $match['dataId'] . '%]', '', $rowV));

                    $blocks[] = [
                        'dataTitle' => $title,
                        'dataId' => $match['dataId'],
                        'coordinates' => [
                            'colName' => $rowK,
                            'colNum' => $colNum,
                            'rowNum' => $rowNum,
                        ],
                        'data' => [
                        ],
                    ];
                }

                $colNum++;
            }

            $rowNum++;
        }

        $rowsCount = $rowNum;

        $isValEmpty = function (?string $val): bool {
            return $val === '' || $val === null;
        };

        foreach ($blocks as $blocksK => $blocksV) {

            $data = [];

            $left = $blocksV['coordinates']['colNum'];
            $right = 0;

            $colI = 0;

            while (true) {

                $val = $base[$blocksV['coordinates']['rowNum'] + 1]
                [$colsNumToAlf[$blocksV['coordinates']['colNum'] + $colI]];

                if ($isValEmpty($val)) {
                    break;
                }

                $colI++;

                $right++;
            }

            $right += $blocksV['coordinates']['colNum'];

            $maxRightNoEmpty = 0;
            $maxBottomNoEmpty = 0;

            $dataI = 0;
            $i = $blocksV['coordinates']['rowNum'] + 1;
            for (;$i < $rowsCount;$i++) {

                $itemArr = [];

                $isAllEmpty = true;

                $dataJ = 0;
                $j = $left;
                for (;$j < $right;$j++) {

                    $val = $base[$i][$colsNumToAlf[$j]];

                    $isValEmptyRes = $isValEmpty($val);

                    $itemArr[] = $val;

                    if (
                        !$isValEmptyRes
                        && $maxRightNoEmpty < $dataJ
                    ) {
                        $maxRightNoEmpty = $dataJ;
                    }

                    if (!$isValEmptyRes) {

                        $isAllEmpty = false;
                    }

                    $dataJ++;
                }

                if ($isAllEmpty) {

                    break;
                }

                $data[$dataI] = $itemArr;

                $maxBottomNoEmpty = $dataI;

                $dataI++;
            }

            $newArr = [];
            for ($i = 0; $i <= $maxBottomNoEmpty; $i++) {

                $newItemArr = [];
                $j = 0;
                for (;$j <= $maxRightNoEmpty;$j++) {

                    $val = (string) $data[$i][$j];

                    $isNumStr = true;

                    $valNumPatched = '';

                    foreach (str_split($val) as $valSplitEl) {

                        $valSplitEl64 = base64_encode($valSplitEl);

                        $isNum = preg_match('/^\d$/', $valSplitEl);
                        $isSpace = $valSplitEl === ' ';
                        $isDot = $valSplitEl === '.';
                        $isPatch1 = $valSplitEl64 === 'wg==';
                        $isPatch2 = $valSplitEl64 === 'oA==';

                        if ($isNumStr) {

                            $isNumStr = $isNum || $isSpace || $isDot || $isPatch1 || $isPatch2;

                            if (!$isNumStr) {
                                break;
                            }
                        }

                        if ($isNum || $isDot) {

                            $valNumPatched .= $valSplitEl;
                        }
                    }

                    if ($isNumStr) {

                        $val = $valNumPatched;
                    }

                    $newItemArr[] = $val;
                }

                $newArr[] = $newItemArr;
            }

            $blocks[$blocksK]['data'] = $newArr;
        }

        foreach ($blocks as $blocksK => $blocksV) {

            unset($blocks[$blocksK]['coordinates']);
        }

        return [
            'blocks' => $blocks,
        ];
    }

    function filterStartSettings(int $pId, array $returnItem): false|array
    {
        $order = $this->order;

        foreach ($order->getBasket()->getBasketItems() as $basketItem) {

            if ((string) $basketItem->getId() === (string) $pId) {

                $basketItem->getPrice();

                $zashchitaEkranaNaTovaryOt = (float) $this->getBlockById(
                    'grs-zashchita-ekrana__na-tovary-ot',
                    'value'
                );

                if ($basketItem->getPrice() < $zashchitaEkranaNaTovaryOt) {

                    if ($returnItem['id'] === 'insurance_ekran') {

                        return false;
                    }
                }

                break;
            }
        }

        return $returnItem;
    }
}